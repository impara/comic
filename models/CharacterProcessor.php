<?php

class CharacterProcessor
{
    private LoggerInterface $logger;
    private ReplicateClient $replicateClient;
    private ImageComposer $imageComposer;
    private Config $config;
    private FileManager $fileManager;
    private StateManager $stateManager;

    public function __construct(LoggerInterface $logger, Config $config, StateManager $stateManager)
    {
        $this->logger = $logger;
        $this->config = $config;
        $this->replicateClient = new ReplicateClient($logger, $config);
        $this->imageComposer = new ImageComposer($logger, $config);
        $this->fileManager = new FileManager($logger, $config);
        $this->stateManager = $stateManager;
    }

    /**
     * Process a single character
     */
    public function processCharacter(array $character, string $jobId): array
    {
        try {
            if (!isset($character['id'])) {
                throw new Exception('Character ID is required');
            }

            if (!isset($character['image'])) {
                throw new Exception('Character image is required');
            }

            $this->logger->info('Processing character', [
                'character_id' => $character['id'],
                'job_id' => $jobId
            ]);

            // Save character image
            $imageData = $this->saveCharacterImage($character['image']);
            if (!$imageData) {
                throw new Exception('Failed to save character image');
            }

            // Start cartoonification
            $prediction = $this->startCartoonification($imageData['path'], $character['id'], $jobId);
            if (!$prediction || !isset($prediction['id'])) {
                throw new Exception('Failed to start cartoonification');
            }

            // Update character status in state
            $state = $this->stateManager->getStripState($jobId);
            $items = $state['processes'][StateManager::PHASE_CHARACTERS]['items'] ?? [];
            $items[$character['id']] = [
                'status' => 'processing',
                'prediction_id' => $prediction['id'],
                'original_image' => $imageData['url']
            ];

            $this->stateManager->updatePhase($jobId, StateManager::PHASE_CHARACTERS, 'processing', [
                'items' => $items,
                'prediction_id' => $prediction['id']
            ]);

            $this->logger->debug('Character processing started', [
                'character_id' => $character['id'],
                'job_id' => $jobId,
                'prediction_id' => $prediction['id']
            ]);

            return [
                'id' => $prediction['id'],
                'status' => 'processing',
                'character_id' => $character['id']
            ];
        } catch (Exception $e) {
            $this->logger->error('Character processing failed', [
                'character_id' => $character['id'] ?? 'unknown',
                'job_id' => $jobId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Update character status to failed in state
            if (isset($character['id'])) {
                $items = $this->stateManager->getStripState($jobId)['processes'][StateManager::PHASE_CHARACTERS]['items'] ?? [];
                $items[$character['id']] = [
                    'status' => 'failed',
                    'error' => $e->getMessage()
                ];

                $this->stateManager->updatePhase($jobId, StateManager::PHASE_CHARACTERS, 'failed', [
                    'items' => $items,
                    'error' => $e->getMessage()
                ]);
            }

            throw $e;
        }
    }

    /**
     * Save character image to disk
     */
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
        $generatedPath = 'generated';

        $path = $outputPath . $filename;

        if (!file_put_contents($path, $imageData)) {
            throw new Exception('Failed to save image');
        }

        // Set file permissions
        chmod($path, 0644);

        // Construct URL
        $url = rtrim($this->config->getBaseUrl(), '/') . '/' . $generatedPath . '/' . $filename;

        // Verify file exists and is accessible
        if (!file_exists($path)) {
            throw new Exception('File was not created successfully');
        }

        return [
            'path' => $path,
            'filename' => $filename,
            'url' => $url
        ];
    }

    /**
     * Start cartoonification process
     */
    private function startCartoonification(string $imagePath, string $characterId, string $jobId): array
    {
        try {
            $maxRetries = $this->config->get('replicate.models.cartoonify.max_retries', 2);
            $lastError = null;

            for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
                try {
                    // Get image data
                    $imageData = base64_encode(file_get_contents($imagePath));
                    if (!$imageData) {
                        throw new Exception('Failed to read image file');
                    }

                    // Check image size (max 10MB after base64 encoding)
                    $imageSize = strlen($imageData);
                    if ($imageSize > 10 * 1024 * 1024) {
                        throw new Exception('Image is too large: ' . round($imageSize / (1024 * 1024), 2) . 'MB');
                    }

                    // Format image data as data URI
                    $imageDataUri = 'data:image/png;base64,' . $imageData;

                    // Prepare request data
                    $requestData = [
                        'version' => $this->config->get('replicate.models.cartoonify.version'),
                        'input' => [
                            'image' => $imageDataUri,
                            'prompt' => $this->buildCartoonifyPrompt($characterId),
                            'negative_prompt' => $this->buildNegativePrompt(),
                            'width' => 512,
                            'height' => 512,
                            'num_outputs' => 1,
                            'scheduler' => 'K_EULER',
                            'num_inference_steps' => 50,
                            'guidance_scale' => 7.5,
                            'seed' => random_int(1, PHP_INT_MAX)
                        ],
                        'webhook' => rtrim($this->config->getBaseUrl(), '/') . '/webhook.php'
                    ];

                    // Only add webhook in production environment
                    if ($this->config->getEnvironment() === 'development') {
                        unset($requestData['webhook']);
                    }

                    // Add metadata for cartoonify completion
                    $requestData['metadata'] = [
                        'type' => 'cartoonify_complete',
                        'job_id' => $jobId,
                        'character_id' => $characterId
                    ];

                    $this->logger->debug('Creating cartoonification prediction', [
                        'character_id' => $characterId,
                        'version' => $requestData['version'],
                        'image_size' => strlen($imageData)
                    ]);

                    $prediction = $this->replicateClient->createPrediction($requestData);

                    $this->logger->debug('Cartoonification prediction created', [
                        'prediction_id' => $prediction['id'],
                        'character_id' => $characterId,
                        'status' => $prediction['status'] ?? 'unknown'
                    ]);

                    // In development, poll for completion
                    if ($this->config->getEnvironment() === 'development') {
                        $prediction = $this->pollForCompletion($prediction['id'], $characterId, $jobId);
                    }

                    return $prediction;
                } catch (Exception $e) {
                    $lastError = $e;
                    $this->logger->warning('Cartoonification attempt failed', [
                        'attempt' => $attempt,
                        'character_id' => $characterId,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            throw new Exception('Failed to start cartoonification after ' . ($maxRetries + 1) . ' attempts: ' . $lastError->getMessage());
        } catch (Exception $e) {
            $this->logger->error('Failed to start cartoonification', [
                'character_id' => $characterId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Build the cartoonify prompt
     */
    private function buildCartoonifyPrompt(string $characterId): string
    {
        return "Convert this image into a high-quality cartoon character, maintaining facial features and expressions, comic book style";
    }

    /**
     * Build the negative prompt
     */
    private function buildNegativePrompt(): string
    {
        return "blurry, low quality, distorted, deformed, disfigured, bad anatomy, extra limbs";
    }

    /**
     * Poll for prediction completion in development environment
     */
    private function pollForCompletion(string $predictionId, string $characterId, string $jobId): array
    {
        $maxAttempts = 30;
        $completed = false;
        $prediction = null;

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $prediction = $this->replicateClient->getPrediction($predictionId);
            
            if ($prediction['status'] === 'succeeded') {
                $completed = true;
                
                // Simulate webhook in development
                $this->logger->info('Simulating webhook in development', [
                    'character_id' => $characterId,
                    'prediction_id' => $predictionId
                ]);

                // Manually trigger webhook processing
                $webhookPayload = [
                    'id' => $predictionId,
                    'status' => 'succeeded',
                    'output' => $prediction['output'] ?? [],
                    'metadata' => [
                        'type' => 'cartoonify_complete',
                        'job_id' => $jobId,
                        'character_id' => $characterId
                    ]
                ];

                // Call webhook handler
                $webhookUrl = rtrim($this->config->getBaseUrl(), '/') . '/webhook.php';
                $this->sendWebhookCallback($webhookUrl, $webhookPayload);

                return $prediction;
            } elseif ($prediction['status'] === 'failed') {
                throw new Exception('Character processing failed: ' . ($prediction['error'] ?? 'Unknown error'));
            }

            sleep(2);
        }

        if (!$completed) {
            throw new Exception('Character processing timed out after ' . $maxAttempts . ' attempts');
        }

        return $prediction;
    }

    /**
     * Send webhook callback
     */
    private function sendWebhookCallback(string $webhookUrl, array $payload): void
    {
        try {
            $ch = curl_init($webhookUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'User-Agent: ComicGenerator/1.0'
                ],
                CURLOPT_TIMEOUT => 10
            ]);

            $response = curl_exec($ch);
            $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $this->logger->debug('Development webhook callback sent', [
                'url' => $webhookUrl,
                'status_code' => $statusCode,
                'response' => $response
            ]);
        } catch (Exception $e) {
            $this->logger->error('Failed to send development webhook callback', [
                'url' => $webhookUrl,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}
