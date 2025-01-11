<?php

class ComicGenerator
{
    private $stateManager;
    private $logger;
    private $config;
    private $imageComposer;
    private $characterProcessor;
    private $storyParser;
    private $replicateClient;

    public function __construct(
        StateManager $stateManager,
        LoggerInterface $logger,
        Config $config,
        ImageComposer $imageComposer,
        CharacterProcessor $characterProcessor,
        StoryParser $storyParser
    ) {
        $this->stateManager = $stateManager;
        $this->logger = $logger;
        $this->config = $config;
        $this->imageComposer = $imageComposer;
        $this->characterProcessor = $characterProcessor;
        $this->storyParser = $storyParser;
        $this->replicateClient = new ReplicateClient($logger, $config);
    }

    /**
     * Initialize a new comic strip generation
     */
    public function initializeComicStrip(string $jobId, string $story, array $characters, array $options = []): array
    {
        try {
            $this->logger->info('Starting comic generation', [
                'job_id' => $jobId,
                'story_length' => strlen($story),
                'character_count' => count($characters)
            ]);

            // Initialize strip state using new structure
            $this->stateManager->initializeStrip($jobId, array_merge($options, [
                'story' => $story,
                'characters' => array_map(function ($char) {
                    return [
                        'id' => $char['id'],
                        'name' => $char['name'],
                        'image' => $char['image'],
                        'status' => 'pending',
                        'cartoonify_url' => null,
                        'error' => null
                    ];
                }, $characters)
            ]));

            return [
                'success' => true,
                'data' => [
                    'id' => $jobId,
                    'status' => StateManager::STATE_INIT,
                    'message' => 'Comic generation initialized'
                ]
            ];
        } catch (Exception $e) {
            $this->logger->error('Comic initialization failed', [
                'error' => $e->getMessage()
            ]);
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Generate background for a panel using the specified style and background type
     */
    public function generatePanelBackground(string $jobId, string $description, string $style, string $background, array $options = []): array
    {
        try {
            if (!isset($options['panel_id'])) {
                throw new Exception('Missing panel_id in options');
            }

            $this->logger->info('Generating panel background', [
                'job_id' => $jobId,
                'style' => $style,
                'background' => $background,
                'panel_id' => $options['panel_id']
            ]);

            $prediction = $this->startBackgroundGeneration($description, array_merge($options, [
                'style' => $style,
                'background' => $background,
                'job_id' => $jobId
            ]));

            if (!$prediction || !isset($prediction['id'])) {
                throw new Exception('Failed to start background generation');
            }

            // Update background status in state
            $items = $this->stateManager->getStripState($jobId)['processes'][StateManager::PHASE_BACKGROUNDS]['items'] ?? [];
            $items[$options['panel_id']] = [
                'status' => 'processing',
                'prediction_id' => $prediction['id']
            ];

            $this->stateManager->updatePhase($jobId, StateManager::PHASE_BACKGROUNDS, 'processing', [
                'items' => $items
            ]);

            return $prediction;
        } catch (Exception $e) {
            $this->logger->error('Background generation failed', [
                'job_id' => $jobId,
                'panel_id' => $options['panel_id'] ?? null,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Start background generation process
     */
    private function startBackgroundGeneration(string $description, array $options = []): array
    {
        try {
            $requestData = [
                'version' => $this->config->get('replicate.models.sdxl.version'),
                'input' => [
                    'prompt' => $this->buildBackgroundPrompt($description, $options),
                    'negative_prompt' => $this->buildNegativePrompt($description, $options),
                    'width' => 768,
                    'height' => 512,
                    'num_outputs' => 1,
                    'scheduler' => 'K_EULER',
                    'num_inference_steps' => 50,
                    'guidance_scale' => 7.5,
                    'seed' => random_int(1, PHP_INT_MAX)
                ],
                'webhook' => rtrim($this->config->getBaseUrl(), '/') . '/webhook.php',
                // IMPORTANT: Add metadata so your webhook can identify "background_complete"
                'metadata' => [
                    'type'     => 'background_complete',
                    'job_id'   => $options['job_id'] ?? null,
                    'panel_id' => $options['panel_id'] ?? null
                ],
            ];
    
            // If you still want to remove the webhook in dev mode, that’s optional:
            if ($this->config->getEnvironment() === 'development') {
                unset($requestData['webhook']);
            }
    
            $prediction = $this->replicateClient->createPrediction($requestData);

            $this->logger->debug('Background generation started', [
                'prediction_id' => $prediction['id'],
                'panel_id' => $options['panel_id'],
                'status' => $prediction['status'] ?? 'unknown'
            ]);

            // In development, poll for completion
            if ($this->config->getEnvironment() === 'development' && isset($options['job_id'], $options['panel_id'])) {
                $this->pollForCompletion($prediction['id'], $options['job_id'], $options['panel_id']);
            }

            return $prediction;
        } catch (Exception $e) {
            $this->logger->error('Failed to start background generation', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Poll for prediction completion in development environment
     */
    private function pollForCompletion(string $predictionId, string $jobId, string $panelId): void
    {
        $maxAttempts = 30;
        $completed = false;
        $prediction = null;

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $prediction = $this->replicateClient->getPrediction($predictionId);
            
            if ($prediction['status'] === 'succeeded') {
                $completed = true;
                
                // Debug log the full prediction response
                $this->logger->debug('Background prediction response', [
                    'prediction_id' => $predictionId,
                    'status' => $prediction['status'],
                    'output' => $prediction['output'] ?? null,
                    'raw' => $prediction
                ]);

                // Simulate webhook in development
                $this->logger->info('Simulating webhook in development', [
                    'prediction_id' => $predictionId,
                    'job_id' => $jobId,
                    'panel_id' => $panelId
                ]);

                // Make internal webhook call
                $webhookData = [
                    'id' => $predictionId,
                    'status' => 'succeeded',
                    'output' => $prediction['output'] ?? null,
                    'webhook_type' => 'background_complete',
                    'metadata' => [
                        'job_id' => $jobId,
                        'panel_id' => $panelId
                    ]
                ];

                $webhookUrl = rtrim($this->config->getBaseUrl(), '/') . '/webhook.php';
                $client = new HttpClient($this->logger, ''); // Empty token since it's an internal call
                $client->request('POST', $webhookUrl, [
                    'json' => $webhookData,
                    'headers' => ['Content-Type' => 'application/json']
                ]);
                break;
            }

            if ($prediction['status'] === 'failed') {
                $this->logger->error('Background generation failed', [
                    'prediction_id' => $predictionId,
                    'error' => $prediction['error'] ?? 'Unknown error'
                ]);
                break;
            }

            sleep(3); // Wait 3 seconds between attempts
        }

        if (!$completed) {
            $this->logger->warning('Background generation polling timed out', [
                'prediction_id' => $predictionId,
                'attempts' => $maxAttempts
            ]);
        }
    }

    /**
     * Build the prompt for background generation
     */
    private function buildBackgroundPrompt(string $description, array $options): string
    {
        $style = $options['style'] ?? 'default';
        $background = $options['background'] ?? 'default';
        
        $stylePrefix = $style !== 'default' ? "In $style style: " : '';
        $backgroundPrefix = $background !== 'default' ? "With $background background: " : '';
        
        return trim($stylePrefix . $backgroundPrefix . $description);
    }

    /**
     * Build the negative prompt for background generation
     */
    private function buildNegativePrompt(string $description, array $options): string
    {
        $style = $options['style'] ?? 'default';
        $background = $options['background'] ?? 'default';
        
        $stylePrefix = $style !== 'default' ? "In $style style: " : '';
        $backgroundPrefix = $background !== 'default' ? "With $background background: " : '';
        
        return trim($stylePrefix . $backgroundPrefix . "negative");
    }

    /**
     * Process character positions and interactions for a panel
     */
    public function processCharacterInteractions(array $characters, string $description, array $options = []): array
    {
        $this->logger->info("Processing character interactions", [
            'character_count' => count($characters),
            'description_length' => strlen($description)
        ]);

        $positions = [];
        $panelWidth = $options['panel_width'] ?? 800;
        $panelHeight = $options['panel_height'] ?? 600;
        $margin = 50; // Minimum margin from panel edges

        // Simple positioning logic - distribute characters evenly across the panel
        $characterCount = count($characters);
        $spacing = ($panelWidth - (2 * $margin)) / max(1, ($characterCount - 1));

        $index = 0;
        foreach ($characters as $charId => $character) {
            // Calculate x position with margin and spacing
            $x = $margin + ($spacing * $index);

            // Place characters at different depths for visual interest
            $y = $margin + (($index % 2) * ($panelHeight / 4));

            $positions[$charId] = [
                'x' => $x,
                'y' => $y,
                'z_index' => $index + 1, // Layer characters front to back
                'scale' => 1.0 - (($index % 2) * 0.2) // Vary scale slightly for depth
            ];

            $index++;
        }

        return $positions;
    }

    /**
     * Prepare panel composition data
     */
    public function preparePanelComposition(array $characters, array $positions): array
    {
        $composition = [];
        foreach ($characters as $charId => $character) {
            if (!isset($character['cartoonify_url'])) {
                throw new Exception("Character $charId is missing cartoonified image");
            }

            $composition[$charId] = [
                'image' => $character['cartoonify_url'],
                'position' => $positions[$charId] ?? [],
                'character_data' => $character
            ];
        }
        return $composition;
    }

    /**
     * Compose the final panel image
     */
    public function composePanelImage(array $composition, string $description, array $options = []): string
    {
        return $this->imageComposer->composePanelImage($composition, $description, $options);
    }
}
