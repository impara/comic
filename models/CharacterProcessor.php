<?php

class CharacterProcessor
{
    private LoggerInterface $logger;
    private ReplicateClient $replicateClient;
    private ImageComposer $imageComposer;
    private Config $config;
    private FileManager $fileManager;

    public function __construct(LoggerInterface $logger, Config $config)
    {
        $this->logger = $logger;
        $this->config = $config;
        $this->replicateClient = new ReplicateClient($logger);
        $this->imageComposer = new ImageComposer($logger, $config);
        $this->fileManager = new FileManager($logger, $config);
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

    /**
     * Process a single character
     * @param array $character Character data
     * @param string $jobId Job ID
     * @return array Processed character data
     */
    public function processCharacter(array $character, string $jobId): array
    {
        try {
            $this->logger->info('Processing character', [
                'character_name' => $character['name'],
                'job_id' => $jobId
            ]);

            // Generate character image from description
            $prediction = $this->replicateClient->createPrediction([
                'model' => $this->config->getModelVersion('sdxl'),
                'input' => array_merge(
                    $this->config->getModelParams('sdxl'),
                    [
                        'prompt' => $this->buildCharacterPrompt($character['description']),
                        'negative_prompt' => implode(', ', $this->config->getNegativePrompts())
                    ]
                ),
                'webhook' => rtrim($this->config->getBaseUrl(), '/') . '/webhook.php',
                'webhook_events_filter' => ['completed']
            ]);

            $this->logger->debug('Character cartoonification started', [
                'character_name' => $character['name'],
                'prediction_id' => $prediction['id'] ?? null,
                'model_config' => [
                    'version' => $this->config->getModelVersion('sdxl'),
                    'params' => $this->config->getModelParams('sdxl')
                ]
            ]);

            if (!$prediction || !isset($prediction['id'])) {
                throw new Exception('Failed to start cartoonification: No prediction ID received');
            }

            // Create pending file for webhook
            $pendingData = [
                'job_id' => $jobId,
                'type' => 'cartoonify_complete',
                'character_name' => $character['name']
            ];

            $this->createPendingFile($prediction['id'], $pendingData);

            return [
                'name' => $character['name'],
                'status' => 'processing',
                'prediction_id' => $prediction['id']
            ];
        } catch (Exception $e) {
            $this->logger->error('Character processing failed', [
                'character_name' => $character['name'],
                'job_id' => $jobId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    private function saveCharacterImage(string $base64Image): array
    {
        $imageData = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $base64Image));
        if (!$imageData) {
            throw new Exception('Invalid image data');
        }

        // Generate a unique filename
        $filename = 'uploadedcharacter_' . uniqid() . '.png';
        $outputPath = $this->config->getPath('output', [
            'create_if_missing' => true,
            'validate_writable' => true
        ]);
        $generatedPath = 'generated'; // URL path component

        // Log the paths for debugging
        $this->logger->info('Path configuration', [
            'output_path' => $outputPath,
            'generated_path' => $generatedPath,
            'full_path' => $outputPath . $filename
        ]);

        $path = $outputPath . $filename;

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
        try {
            // Validate image
            $imageInfo = getimagesize($imagePath);
            if (!$imageInfo) {
                throw new Exception('Invalid image file');
            }

            $imageData = [
                'path' => $imagePath,
                'url' => $this->getImageUrl($imagePath)
            ];

            $this->logger->debug('Starting cartoonification', [
                'character_id' => $characterId,
                'image_info' => [
                    'path' => $imagePath,
                    'url' => $imageData['url'],
                    'mime' => $imageInfo['mime'],
                    'dimensions' => [$imageInfo[0], $imageInfo[1]]
                ],
                'model_config' => [
                    'version' => $this->config->get('replicate.models.cartoonify.version'),
                    'seed' => $this->config->get('replicate.models.cartoonify.params.seed', 2862431)
                ]
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

                    // Ensure webhook URL is properly configured
                    $webhookUrl = rtrim($this->config->getBaseUrl(), '/') . '/webhook.php';
                    $this->logger->debug('Configuring cartoonify webhook', [
                        'webhook_url' => $webhookUrl,
                        'character_id' => $characterId,
                        'events' => ['completed']
                    ]);

                    $prediction = $this->replicateClient->createPrediction([
                        'model' => $this->config->getModelVersion('cartoonify'),
                        'input' => [
                            'image' => $imageData['url'],
                            'seed' => $this->config->getModelParams('cartoonify')['seed'] ?? 2862431
                        ],
                        'webhook' => $webhookUrl,
                        'webhook_events_filter' => ['completed']
                    ]);

                    $this->logger->debug('Cartoonification prediction created', [
                        'prediction_id' => $prediction['id'],
                        'character_id' => $characterId,
                        'status' => $prediction['status'] ?? 'unknown',
                        'webhook_url' => $webhookUrl,
                        'model_config' => [
                            'version' => $this->config->getModelVersion('cartoonify'),
                            'params' => $this->config->getModelParams('cartoonify')
                        ]
                    ]);

                    return $prediction;
                } catch (Exception $e) {
                    $lastError = $e;
                    $this->logger->error('Cartoonification attempt failed', [
                        'attempt' => $attempt + 1,
                        'max_retries' => $maxRetries,
                        'error' => $e->getMessage(),
                        'character_id' => $characterId,
                        'model_config' => [
                            'version' => $this->config->get('replicate.models.cartoonify.version'),
                            'seed' => $this->config->get('replicate.models.cartoonify.params.seed', 2862431)
                        ]
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
        $pendingFile = $this->config->getPath('temp', [
            'create_if_missing' => true,
            'validate_writable' => true
        ]) . "pending_{$predictionId}.json";

        if (file_put_contents($pendingFile, json_encode($data)) === false) {
            throw new Exception('Failed to create pending file');
        }
        chmod($pendingFile, 0664);
    }

    /**
     * Get the public URL for an image file
     * @param string $imagePath Local path to the image
     * @return string Public URL for the image
     */
    private function getImageUrl(string $imagePath): string
    {
        $generatedPath = basename($this->config->getPath('output', [
            'trailing_slash' => false
        ]));
        return rtrim($this->config->getBaseUrl(), '/') . '/' . $generatedPath . '/' . basename($imagePath);
    }

    private function buildCharacterPrompt(string $description): string
    {
        return "Create a detailed manga-style character portrait. " .
            "The character should be: {$description}. " .
            "Style: High-quality manga art, clean lines, expressive features, detailed shading. " .
            "Focus on creating a distinctive character design that captures their personality.";
    }
}
