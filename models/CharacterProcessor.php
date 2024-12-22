<?php

require_once __DIR__ . '/../interfaces/LoggerInterface.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/ReplicateClient.php';
require_once __DIR__ . '/ImageComposer.php';
require_once __DIR__ . '/FileManager.php';

class CharacterProcessor
{
    private LoggerInterface $logger;
    private ReplicateClient $replicateClient;
    private ImageComposer $imageComposer;
    private Config $config;
    private FileManager $fileManager;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->config = Config::getInstance();
        $this->replicateClient = new ReplicateClient(
            $this->config->getApiToken(),
            $logger
        );
        $this->imageComposer = new ImageComposer($logger);
        $this->fileManager = FileManager::getInstance($logger);
    }

    /**
     * Process a batch of characters
     * @param array $characters Array of character data
     * @param string $stripId Strip ID
     * @return array Processed character data
     */
    public function processCharacters(array $characters, string $stripId): array
    {
        $processedCharacters = [];

        foreach ($characters as $character) {
            try {
                // Save character image
                $imageData = $this->saveCharacterImage($character['image']);
                if (!$imageData) {
                    throw new Exception('Failed to save character image');
                }

                // Start cartoonification
                $prediction = $this->startCartoonification($imageData['path'], $character['id']);
                if (!$prediction || !isset($prediction['id'])) {
                    throw new Exception('Failed to start cartoonification');
                }

                // Create pending file for webhook
                $pendingData = [
                    'strip_id' => $stripId,
                    'options' => [
                        'character_id' => $character['id']
                    ]
                ];

                $this->createPendingFile($prediction['id'], $pendingData);

                // Add to processed characters
                $processedCharacters[$character['id']] = [
                    'id' => $character['id'],
                    'status' => 'processing',
                    'prediction_id' => $prediction['id']
                ];

                $this->logger->info('Character processing started', [
                    'character_id' => $character['id'],
                    'prediction_id' => $prediction['id']
                ]);
            } catch (Exception $e) {
                $this->logger->error('Character processing failed', [
                    'character_id' => $character['id'],
                    'error' => $e->getMessage()
                ]);

                $processedCharacters[$character['id']] = [
                    'id' => $character['id'],
                    'status' => 'failed',
                    'error' => $e->getMessage()
                ];
            }
        }

        return $processedCharacters;
    }

    private function saveCharacterImage(string $base64Image): array
    {
        $imageData = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $base64Image));
        if (!$imageData) {
            throw new Exception('Invalid image data');
        }

        // Generate a unique filename
        $filename = 'generatedcharacter_' . uniqid() . '.png';
        $outputPath = rtrim($this->config->getOutputPath(), '/'); // Full filesystem path
        $generatedPath = 'generated'; // URL path component

        // Log the paths for debugging
        $this->logger->info('Path configuration', [
            'output_path' => $outputPath,
            'generated_path' => $generatedPath,
            'full_path' => $outputPath . '/' . $filename
        ]);

        // Ensure output directory exists and has correct permissions
        if (!is_dir($outputPath)) {
            if (!mkdir($outputPath, 0755, true)) {
                throw new Exception('Failed to create output directory');
            }
            chown($outputPath, 'www-data');
            chgrp($outputPath, 'www-data');
        }

        $path = $outputPath . '/' . $filename;

        if (!file_put_contents($path, $imageData)) {
            throw new Exception('Failed to save image');
        }

        chmod($path, 0644);
        chown($path, 'www-data');
        chgrp($path, 'www-data');

        // Construct URL with consistent path
        $url = rtrim($this->config->getBaseUrl(), '/') . '/' . $generatedPath . '/' . $filename;

        // Verify file exists and is accessible
        if (!file_exists($path)) {
            throw new Exception('File was not created successfully');
        }

        // Log detailed file information
        $this->logger->info('Saved character image', [
            'path' => $path,
            'filename' => $filename,
            'url' => $url,
            'exists' => file_exists($path),
            'permissions' => substr(sprintf('%o', fileperms($path)), -4),
            'owner' => posix_getpwuid(fileowner($path))['name'],
            'group' => posix_getgrgid(filegroup($path))['name']
        ]);

        return [
            'path' => $path,
            'filename' => $filename,
            'url' => $url
        ];
    }

    private function startCartoonification(string $imagePath, string $characterId): array
    {
        $this->logger->info('Starting cartoonification', [
            'character_id' => $characterId,
            'image_path' => $imagePath
        ]);

        try {
            // Get image URL from saved data
            $generatedPath = basename($this->config->getOutputPath());
            $imageData = [
                'path' => $imagePath,
                'filename' => basename($imagePath),
                'url' => rtrim($this->config->getBaseUrl(), '/') . '/' . $generatedPath . '/' . basename($imagePath)
            ];

            // Validate image file
            if (!file_exists($imagePath)) {
                throw new Exception('Image file does not exist: ' . $imagePath);
            }

            if (!is_readable($imagePath)) {
                throw new Exception('Image file is not readable: ' . $imagePath);
            }

            // Validate image content
            $imageInfo = getimagesize($imagePath);
            if (!$imageInfo) {
                throw new Exception('Invalid image file or format');
            }

            $this->logger->info('Image validation passed', [
                'mime_type' => $imageInfo['mime'],
                'dimensions' => [$imageInfo[0], $imageInfo[1]],
                'url' => $imageData['url']
            ]);

            // Create prediction with retry logic
            $maxRetries = $this->config->get('replicate.models.cartoonify.max_retries', 2);
            $retryDelay = $this->config->get('replicate.models.cartoonify.retry_delay', 5);
            $lastError = null;

            for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
                try {
                    if ($attempt > 0) {
                        $this->logger->info('Retrying cartoonification', [
                            'attempt' => $attempt,
                            'character_id' => $characterId
                        ]);
                        sleep($retryDelay);
                    }

                    $prediction = $this->replicateClient->createPrediction([
                        'image' => $imageData['url'],
                        'character_id' => $characterId
                    ]);

                    if (!isset($prediction['id'])) {
                        throw new Exception('Invalid prediction response: missing ID');
                    }

                    $this->logger->info('Cartoonification prediction created', [
                        'prediction_id' => $prediction['id'],
                        'character_id' => $characterId,
                        'attempt' => $attempt + 1
                    ]);

                    return $prediction;
                } catch (Exception $e) {
                    $lastError = $e;
                    $this->logger->error('Cartoonification attempt failed', [
                        'attempt' => $attempt + 1,
                        'character_id' => $characterId,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            throw new Exception('Failed to start cartoonification after ' . ($maxRetries + 1) . ' attempts: ' . $lastError->getMessage());
        } catch (Exception $e) {
            $this->logger->error('Cartoonification failed', [
                'character_id' => $characterId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    private function createPendingFile(string $predictionId, array $data): void
    {
        $pendingFile = $this->config->getTempPath() . "pending_{$predictionId}.json";

        if (!file_put_contents($pendingFile, json_encode($data))) {
            throw new Exception('Failed to create pending file');
        }
    }
}
