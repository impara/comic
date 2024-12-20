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

        $filename = uniqid('char_') . '.png';
        $outputPath = $this->config->getOutputPath();

        // Ensure output directory exists
        if (!is_dir($outputPath)) {
            mkdir($outputPath, 0755, true);
        }

        $path = $outputPath . $filename;

        if (!file_put_contents($path, $imageData)) {
            throw new Exception('Failed to save image');
        }

        return [
            'path' => $path,
            'filename' => $filename,
            'url' => rtrim($this->config->getBaseUrl(), '/') . '/generated/' . $filename
        ];
    }

    private function startCartoonification(string $imagePath, string $characterId): array
    {
        $this->logger->info('Starting cartoonification', [
            'character_id' => $characterId,
            'image_path' => $imagePath
        ]);

        // Get image URL from saved data
        $imageData = [
            'path' => $imagePath,
            'filename' => basename($imagePath),
            'url' => rtrim($this->config->getBaseUrl(), '/') . '/generated/' . basename($imagePath)
        ];

        // Log the URL we're sending to Replicate
        $this->logger->info('Using image URL for cartoonification', [
            'url' => $imageData['url']
        ]);

        return $this->replicateClient->createPrediction([
            'image' => $imageData['url'],
            'character_id' => $characterId
        ]);
    }

    private function createPendingFile(string $predictionId, array $data): void
    {
        $pendingFile = $this->config->getTempPath() . "pending_{$predictionId}.json";

        if (!file_put_contents($pendingFile, json_encode($data))) {
            throw new Exception('Failed to create pending file');
        }
    }
}
