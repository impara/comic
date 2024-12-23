<?php

require_once __DIR__ . '/models/StateManager.php';
require_once __DIR__ . '/models/Config.php';
require_once __DIR__ . '/models/Logger.php';
require_once __DIR__ . '/models/ComicGenerator.php';
require_once __DIR__ . '/models/ImageComposer.php';
require_once __DIR__ . '/models/CharacterProcessor.php';
require_once __DIR__ . '/models/StoryParser.php';

class WebhookHandler
{
    private $stateManager;
    private $logger;
    private $config;

    public function __construct(StateManager $stateManager, Logger $logger, Config $config)
    {
        $this->stateManager = $stateManager;
        $this->logger = $logger;
        $this->config = $config;
    }

    public function handleWebhook(): void
    {
        try {
            // Get webhook payload
            $rawInput = file_get_contents('php://input');
            $payload = json_decode($rawInput, true);

            if (!$payload || !isset($payload['id'])) {
                throw new Exception('Invalid webhook payload');
            }

            $predictionId = $payload['id'];
            $lockFile = $this->config->get('paths.temp') . "/webhook_{$predictionId}.lock";

            // Simple file-based duplicate prevention
            if (file_exists($lockFile)) {
                $lockData = json_decode(file_get_contents($lockFile), true);
                if ($lockData && isset($lockData['timestamp']) && (time() - $lockData['timestamp']) < 30) {
                    $this->logger->debug('Skipping duplicate webhook', [
                        'prediction_id' => $predictionId,
                        'last_processed' => $lockData['timestamp']
                    ]);
                    http_response_code(200);
                    echo json_encode(['success' => true, 'message' => 'Already processed']);
                    return;
                }
            }

            // Create/update lock file
            file_put_contents($lockFile, json_encode([
                'timestamp' => time(),
                'status' => $payload['status'] ?? 'unknown'
            ]));

            // Process the webhook
            $this->processWebhook($payload);

            // Cleanup old lock file after successful processing
            if (file_exists($lockFile)) {
                unlink($lockFile);
            }

            http_response_code(200);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $this->logger->error('Webhook processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function handleCartoonifyWebhook(array $payload): void
    {
        $predictionId = $payload['id'];
        $status = $payload['status'];
        $output = $payload['output'] ?? null;

        $this->logger->debug('Processing cartoonify webhook', [
            'prediction_id' => $predictionId,
            'status' => $status,
            'has_output' => !empty($output),
            'timestamp' => date('Y-m-d H:i:s')
        ]);

        // Use a transaction-like approach for state updates
        $lockFile = $this->config->get('paths.temp') . "/webhook_{$predictionId}.lock";
        $lockFp = fopen($lockFile, 'c+');

        if (!$lockFp) {
            $this->logger->error('Failed to create webhook lock file', [
                'prediction_id' => $predictionId,
                'lock_file' => $lockFile,
                'error' => error_get_last()
            ]);
            throw new Exception("Could not create webhook lock file");
        }

        try {
            // Try to acquire lock with timeout
            $startTime = time();
            while (!flock($lockFp, LOCK_EX | LOCK_NB)) {
                if (time() - $startTime >= 10) { // 10 second timeout
                    throw new Exception("Timeout waiting for webhook lock");
                }
                usleep(250000); // Wait 250ms before retrying
            }

            $this->logger->debug('Acquired webhook lock', [
                'prediction_id' => $predictionId,
                'lock_file' => $lockFile,
                'time_taken' => time() - $startTime
            ]);

            // Get pending file data
            $pendingFile = $this->config->getTempPath() . "pending_{$predictionId}.json";
            if (!file_exists($pendingFile)) {
                $this->logger->error('Pending file not found', [
                    'prediction_id' => $predictionId,
                    'pending_file' => $pendingFile,
                    'temp_path' => $this->config->getTempPath(),
                    'existing_files' => glob($this->config->getTempPath() . "pending_*.json")
                ]);
                throw new Exception('Pending file not found');
            }

            $pendingData = json_decode(file_get_contents($pendingFile), true);
            if (!$pendingData) {
                $this->logger->error('Invalid pending data', [
                    'prediction_id' => $predictionId,
                    'pending_file' => $pendingFile,
                    'raw_content' => file_get_contents($pendingFile),
                    'json_error' => json_last_error_msg()
                ]);
                throw new Exception('Invalid pending data');
            }

            $this->logger->debug('Retrieved pending data', [
                'prediction_id' => $predictionId,
                'pending_data' => $pendingData
            ]);

            // Extract required data
            $stripId = $pendingData['strip_id'] ?? null;
            $characterId = $pendingData['options']['character_id'] ?? null;

            if (!$stripId || !$characterId) {
                $this->logger->error('Missing required data in pending file', [
                    'prediction_id' => $predictionId,
                    'strip_id' => $stripId,
                    'character_id' => $characterId,
                    'pending_data' => $pendingData
                ]);
                throw new Exception('Missing required data');
            }

            if ($status === 'succeeded' && $output) {
                $this->logger->debug('Processing successful cartoonify result', [
                    'prediction_id' => $predictionId,
                    'strip_id' => $stripId,
                    'character_id' => $characterId,
                    'output_url' => $output
                ]);
                $this->handleCartoonifySuccess($stripId, $characterId, $output, $pendingData);
            } else {
                $this->logger->debug('Processing failed cartoonify result', [
                    'prediction_id' => $predictionId,
                    'strip_id' => $stripId,
                    'character_id' => $characterId,
                    'error' => $payload['error'] ?? 'Processing failed'
                ]);
                $this->handleCartoonifyFailure($stripId, $characterId, $payload['error'] ?? 'Processing failed');
            }

            // Clean up pending file
            if (file_exists($pendingFile)) {
                $this->logger->debug('Cleaning up pending file', [
                    'prediction_id' => $predictionId,
                    'pending_file' => $pendingFile
                ]);
                unlink($pendingFile);
            }

            // Get updated state and map for frontend
            $stripState = $this->stateManager->getStripState($stripId);
            if ($stripState) {
                // Map state for frontend response
                $apiState = $this->stateManager->mapStateForApi($stripState);

                $this->logger->debug('Strip state after cartoonify processing', [
                    'strip_id' => $stripId,
                    'status' => $apiState['status'],
                    'progress' => $apiState['progress'],
                    'current_operation' => $apiState['current_operation'],
                    'characters' => array_map(fn($char) => [
                        'id' => $char['id'],
                        'status' => $char['status']
                    ], $stripState['characters']),
                    'panels' => array_map(fn($panel) => [
                        'id' => $panel['id'],
                        'status' => $panel['status']
                    ], $stripState['panels'] ?? [])
                ]);
            } else {
                $this->logger->error('Failed to retrieve strip state after cartoonify', [
                    'strip_id' => $stripId
                ]);
            }
        } finally {
            // Always release the lock
            $this->logger->debug('Releasing webhook lock', [
                'prediction_id' => $predictionId,
                'lock_file' => $lockFile
            ]);
            flock($lockFp, LOCK_UN);
            fclose($lockFp);
            @unlink($lockFile);
        }
    }

    private function handleSdxlWebhook(array $payload): void
    {
        $predictionId = $payload['id'];
        $status = $payload['status'];
        $output = $payload['output'] ?? null;
        $error = $payload['error'] ?? null;

        $this->logger->debug('Processing SDXL webhook', [
            'prediction_id' => $predictionId,
            'status' => $status,
            'has_output' => !empty($output),
            'has_error' => !empty($error),
            'timestamp' => date('Y-m-d H:i:s')
        ]);

        // Find panel state using the new method
        $stateInfo = $this->findStateFile($predictionId, 'panel');
        if (!$stateInfo) {
            $this->logger->error('Could not find panel state for prediction', [
                'prediction_id' => $predictionId
            ]);
            return;
        }

        $targetPanel = $stateInfo['id'];
        $targetState = $stateInfo['state'];

        try {
            if ($status === 'succeeded' && $output) {
                $this->logger->debug('Processing successful SDXL result', [
                    'prediction_id' => $predictionId,
                    'panel_id' => $targetPanel,
                    'output_url' => $output[0],
                    'current_state' => $targetState
                ]);

                // Update panel state with background URL
                $this->stateManager->updatePanelState($targetPanel, [
                    'background_url' => $output[0],
                    'background_generated_at' => time(),
                    'status' => StateManager::PANEL_STATE_BACKGROUND_READY,
                    'progress' => 60
                ]);

                $this->logger->info('Panel background generated', [
                    'panel_id' => $targetPanel,
                    'prediction_id' => $predictionId,
                    'background_url' => $output[0],
                    'updated_state' => $this->stateManager->getPanelState($targetPanel)
                ]);

                // Check if we can proceed with character composition
                $this->checkAndStartCharacterComposition($targetPanel, $targetState);
            } elseif ($status === 'failed') {
                $this->logger->error('SDXL generation failed', [
                    'prediction_id' => $predictionId,
                    'panel_id' => $targetPanel,
                    'error' => $error
                ]);

                $this->stateManager->updatePanelState($targetPanel, [
                    'status' => StateManager::PANEL_STATE_FAILED,
                    'error' => $error,
                    'failed_at' => time()
                ]);

                // Check if we need to fail the entire strip
                if (isset($targetState['strip_id'])) {
                    $this->stateManager->updateStripState($targetState['strip_id'], [
                        'status' => StateManager::STATE_FAILED,
                        'error' => "Panel background generation failed: $error",
                        'failed_at' => time()
                    ]);
                }
            } else {
                $this->logger->debug('SDXL generation in progress', [
                    'prediction_id' => $predictionId,
                    'panel_id' => $targetPanel,
                    'status' => $status
                ]);
            }
        } catch (Exception $e) {
            $this->logger->error('Error processing SDXL webhook', [
                'prediction_id' => $predictionId,
                'panel_id' => $targetPanel,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    private function handleCartoonifySuccess(string $stripId, string $characterId, string $outputUrl, array $pendingData): void
    {
        // Generate unique filename for cartoonified image
        $originalFilename = $pendingData['image_path'] ?? uniqid() . '.png';
        $filename = 'cartoonified_' . $originalFilename;
        $outputPath = rtrim($this->config->getOutputPath(), '/') . '/' . $filename;

        try {
            // Download and save image
            $imageContent = file_get_contents($outputUrl);
            if ($imageContent === false) {
                throw new Exception('Failed to download cartoonified image');
            }

            if (file_put_contents($outputPath, $imageContent) === false) {
                throw new Exception('Failed to save cartoonified image');
            }

            // Set proper permissions
            chmod($outputPath, 0644);
            if (function_exists('posix_getpwuid')) {
                chown($outputPath, 'www-data');
                chgrp($outputPath, 'www-data');
            }

            // Update character state
            $generatedPath = basename($this->config->getOutputPath());
            $localUrl = rtrim($this->config->getBaseUrl(), '/') . '/' . $generatedPath . '/' . $filename;

            $stripState = $this->stateManager->updateStripState($stripId, [
                'characters' => [
                    $characterId => [
                        'id' => $characterId,
                        'image_url' => $localUrl,
                        'cartoonified_image' => $localUrl,
                        'status' => 'completed'
                    ]
                ]
            ]);

            // Check if all characters are completed and proceed with panel generation
            $this->checkAndStartPanelGeneration($stripId, $stripState);
        } catch (Exception $e) {
            $this->handleCartoonifyFailure($stripId, $characterId, $e->getMessage());
            throw $e;
        }
    }

    private function handleCartoonifyFailure(string $stripId, string $characterId, string $error): void
    {
        $this->stateManager->updateStripState($stripId, [
            'characters' => [
                $characterId => [
                    'id' => $characterId,
                    'status' => 'failed',
                    'error' => $error
                ]
            ]
        ]);
    }

    private function getStripStateWithRetry(string $stripId): array
    {
        $maxRetries = 3;
        $retryDelay = 2;

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            $stripState = $this->stateManager->getStripState($stripId);
            if ($stripState) {
                return $stripState;
            }
            if ($attempt < $maxRetries) {
                $this->logger->info("Strip state not found, retrying...", [
                    'strip_id' => $stripId,
                    'attempt' => $attempt + 1
                ]);
                sleep($retryDelay);
            }
        }

        // Create initial state if not found
        return [
            'id' => $stripId,
            'status' => StateManager::STATE_CHARACTERS_PENDING,
            'characters' => [],
            'progress' => 0,
            'created_at' => time()
        ];
    }

    private function checkAndStartPanelGeneration(string $stripId, array $stripState): void
    {
        $this->logger->debug('Checking panel generation conditions', [
            'strip_id' => $stripId,
            'current_status' => $stripState['status'],
            'total_characters' => count($stripState['characters']),
            'character_statuses' => array_map(fn($char) => $char['status'], $stripState['characters']),
            'has_panels' => isset($stripState['panels']) && !empty($stripState['panels'])
        ]);

        // Check if panel generation has already started
        if (isset($stripState['panels']) && !empty($stripState['panels'])) {
            $this->logger->debug('Panel generation already started', [
                'strip_id' => $stripId,
                'panel_count' => count($stripState['panels']),
                'current_status' => $stripState['status']
            ]);
            return;
        }

        $totalCharacters = count($stripState['characters']);
        $completedCharacters = count(array_filter(
            $stripState['characters'],
            fn($char) => $char['status'] === 'completed'
        ));

        $this->logger->debug('Character completion status', [
            'strip_id' => $stripId,
            'total_characters' => $totalCharacters,
            'completed_characters' => $completedCharacters,
            'all_completed' => ($completedCharacters === $totalCharacters),
            'character_details' => array_map(fn($char) => [
                'id' => $char['id'],
                'status' => $char['status'],
                'has_image' => isset($char['cartoonified_image'])
            ], $stripState['characters'])
        ]);

        if ($completedCharacters === $totalCharacters) {
            // Verify all characters have their images
            $allImagesReady = array_reduce(
                $stripState['characters'],
                fn($carry, $char) => $carry && isset($char['cartoonified_image']),
                true
            );

            if (!$allImagesReady) {
                $this->logger->warning('Not all character images are ready', [
                    'strip_id' => $stripId,
                    'missing_images' => array_filter(
                        $stripState['characters'],
                        fn($char) => !isset($char['cartoonified_image'])
                    )
                ]);
                return;
            }

            $this->logger->debug('All characters completed, checking strip status', [
                'strip_id' => $stripId,
                'current_status' => $stripState['status'],
                'can_proceed' => (
                    $stripState['status'] === StateManager::STATE_CHARACTERS_PENDING ||
                    $stripState['status'] === StateManager::STATE_CHARACTERS_PROCESSING
                )
            ]);

            if (
                $stripState['status'] === StateManager::STATE_CHARACTERS_PENDING ||
                $stripState['status'] === StateManager::STATE_CHARACTERS_PROCESSING
            ) {
                try {
                    // Update to characters complete
                    $this->logger->debug('Updating strip state to characters complete', [
                        'strip_id' => $stripId,
                        'previous_status' => $stripState['status']
                    ]);

                    $stripState = $this->stateManager->updateStripState($stripId, [
                        'status' => StateManager::STATE_CHARACTERS_COMPLETE,
                        'characters_completed_at' => time()
                    ]);

                    // Move to story segmenting
                    $this->logger->debug('Updating strip state to story segmenting', [
                        'strip_id' => $stripId
                    ]);

                    $stripState = $this->stateManager->updateStripState($stripId, [
                        'status' => StateManager::STATE_STORY_SEGMENTING,
                        'story_segmenting_started_at' => time()
                    ]);
                } catch (Exception $e) {
                    $this->logger->error('Failed to update strip state', [
                        'strip_id' => $stripId,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    throw $e;
                }
            }

            // Always try to start panel generation if in story_segmenting state
            if ($stripState['status'] === StateManager::STATE_STORY_SEGMENTING) {
                try {
                    // Acquire lock to prevent duplicate panel generation
                    $lockFile = $this->config->getTempPath() . "/panel_generation_{$stripId}.lock";
                    $lockFp = fopen($lockFile, 'c+');

                    if (!$lockFp) {
                        throw new Exception("Could not create panel generation lock file");
                    }

                    if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
                        $this->logger->debug('Panel generation already in progress', [
                            'strip_id' => $stripId
                        ]);
                        fclose($lockFp);
                        return;
                    }

                    // Initialize dependencies properly
                    $imageComposer = new ImageComposer($this->logger, $this->config);
                    $characterProcessor = new CharacterProcessor($this->logger, $this->config);
                    $storyParser = new StoryParser($this->logger);

                    $this->logger->debug('Starting panel generation process', [
                        'strip_id' => $stripId,
                        'dependencies_initialized' => [
                            'image_composer' => get_class($imageComposer),
                            'character_processor' => get_class($characterProcessor),
                            'story_parser' => get_class($storyParser)
                        ]
                    ]);

                    // Start panel generation
                    $comicGenerator = new ComicGenerator(
                        $this->stateManager,
                        $this->logger,
                        $this->config,
                        $imageComposer,
                        $characterProcessor,
                        $storyParser
                    );

                    $this->logger->debug('Initializing panel generation', [
                        'strip_id' => $stripId,
                        'state' => $stripState
                    ]);

                    $comicGenerator->startPanelGeneration($stripId);

                    $this->logger->debug('Panel generation started successfully', [
                        'strip_id' => $stripId,
                        'current_state' => $this->stateManager->getStripState($stripId)
                    ]);

                    // Release lock
                    flock($lockFp, LOCK_UN);
                    fclose($lockFp);
                    unlink($lockFile);
                } catch (Exception $e) {
                    $this->logger->error('Failed to start panel generation', [
                        'strip_id' => $stripId,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    $this->stateManager->updateStripState($stripId, [
                        'status' => StateManager::STATE_FAILED,
                        'error' => 'Failed to start panel generation: ' . $e->getMessage(),
                        'failed_at' => time()
                    ]);

                    // Clean up lock if we created it
                    if (isset($lockFp)) {
                        flock($lockFp, LOCK_UN);
                        fclose($lockFp);
                        @unlink($lockFile);
                    }
                    throw $e;
                }
            } else {
                $this->logger->debug('Strip not in correct state for panel generation', [
                    'strip_id' => $stripId,
                    'current_status' => $stripState['status'],
                    'required_status' => [
                        StateManager::STATE_CHARACTERS_PENDING,
                        StateManager::STATE_CHARACTERS_PROCESSING,
                        StateManager::STATE_STORY_SEGMENTING
                    ]
                ]);
            }
        } else {
            $this->logger->debug('Not all characters completed yet', [
                'strip_id' => $stripId,
                'completed' => $completedCharacters,
                'total' => $totalCharacters,
                'pending_characters' => array_filter(
                    $stripState['characters'],
                    fn($char) => $char['status'] !== 'completed'
                )
            ]);
        }
    }

    private function checkAndStartCharacterComposition(string $panelId, array $panelState): void
    {
        $this->logger->debug('Checking character composition conditions', [
            'panel_id' => $panelId,
            'current_state' => $panelState,
            'has_background' => isset($panelState['background_url']),
            'has_characters' => isset($panelState['characters']) && !empty($panelState['characters']),
            'status' => $panelState['status'] ?? 'unknown'
        ]);

        // Verify we have all required data
        if (!isset($panelState['background_url'])) {
            $this->logger->warning('Missing background URL for panel', [
                'panel_id' => $panelId,
                'state' => $panelState
            ]);
            return;
        }

        if (!isset($panelState['characters']) || empty($panelState['characters'])) {
            $this->logger->warning('No characters found for panel', [
                'panel_id' => $panelId,
                'state' => $panelState
            ]);
            return;
        }

        // Verify background URL is accessible
        $backgroundHeaders = get_headers($panelState['background_url'], 1);
        if (!$backgroundHeaders || strpos($backgroundHeaders[0], '200') === false) {
            $this->logger->error('Background URL is not accessible', [
                'panel_id' => $panelId,
                'url' => $panelState['background_url'],
                'headers' => $backgroundHeaders
            ]);

            $this->stateManager->updatePanelState($panelId, [
                'status' => StateManager::PANEL_STATE_FAILED,
                'error' => 'Background image is not accessible',
                'failed_at' => time()
            ]);
            return;
        }

        // Verify all character images are ready
        $missingImages = [];
        foreach ($panelState['characters'] as $charId => $char) {
            if (!isset($char['cartoonified_image'])) {
                $missingImages[] = $charId;
                continue;
            }

            $headers = get_headers($char['cartoonified_image'], 1);
            if (!$headers || strpos($headers[0], '200') === false) {
                $missingImages[] = $charId;
            }
        }

        if (!empty($missingImages)) {
            $this->logger->warning('Some character images are not accessible', [
                'panel_id' => $panelId,
                'missing_characters' => $missingImages
            ]);
            return;
        }

        if ($panelState['status'] === StateManager::PANEL_STATE_BACKGROUND_READY) {
            try {
                // First update state to composing
                $this->stateManager->updatePanelState($panelId, [
                    'status' => StateManager::PANEL_STATE_COMPOSING,
                    'composing_started_at' => time()
                ]);

                $this->logger->debug('Starting character composition', [
                    'panel_id' => $panelId,
                    'background_url' => $panelState['background_url'],
                    'character_count' => count($panelState['characters']),
                    'characters' => array_map(fn($char) => [
                        'id' => $char['id'],
                        'status' => $char['status'] ?? 'unknown',
                        'has_image' => isset($char['cartoonified_image'])
                    ], $panelState['characters'])
                ]);

                // Initialize ImageComposer
                $imageComposer = new ImageComposer($this->logger, $this->config);

                $this->logger->debug('Composing panel image', [
                    'panel_id' => $panelId,
                    'description' => $panelState['description'] ?? 'no description',
                    'options' => $panelState['options'] ?? []
                ]);

                // Compose the panel image
                $composedPath = $imageComposer->composePanelImage(
                    $panelState['characters'],
                    $panelState['description'],
                    $panelState['options'] ?? []
                );

                $this->logger->info('Panel image composition completed', [
                    'panel_id' => $panelId,
                    'output_path' => $composedPath,
                    'previous_state' => $panelState
                ]);

                // Verify the composed image exists and is accessible
                if (!file_exists($composedPath)) {
                    throw new Exception('Composed panel image file not found');
                }

                // Update panel state
                $this->stateManager->updatePanelState($panelId, [
                    'status' => StateManager::PANEL_STATE_COMPLETE,
                    'output_path' => $composedPath,
                    'completed_at' => time(),
                    'progress' => 100
                ]);

                $updatedState = $this->stateManager->getPanelState($panelId);
                $this->logger->debug('Panel state updated after composition', [
                    'panel_id' => $panelId,
                    'new_state' => $updatedState,
                    'status' => $updatedState['status'],
                    'progress' => $updatedState['progress']
                ]);

                // Check if all panels are completed
                if (isset($panelState['strip_id'])) {
                    $stripState = $this->stateManager->getStripState($panelState['strip_id']);
                    if ($stripState) {
                        $totalPanels = count($stripState['panels'] ?? []);
                        $completedPanels = count(array_filter(
                            $stripState['panels'] ?? [],
                            fn($panel) => $panel['status'] === StateManager::PANEL_STATE_COMPLETE
                        ));

                        $this->logger->debug('Checking strip completion status', [
                            'strip_id' => $panelState['strip_id'],
                            'total_panels' => $totalPanels,
                            'completed_panels' => $completedPanels,
                            'all_completed' => ($completedPanels === $totalPanels)
                        ]);

                        if ($completedPanels === $totalPanels) {
                            $this->logger->info('All panels completed for strip', [
                                'strip_id' => $panelState['strip_id'],
                                'total_panels' => $totalPanels
                            ]);

                            $this->stateManager->updateStripState($panelState['strip_id'], [
                                'status' => StateManager::STATE_COMPLETE,
                                'completed_at' => time(),
                                'progress' => 100
                            ]);
                        }
                    }
                }
            } catch (Exception $e) {
                $this->logger->error('Character composition failed', [
                    'panel_id' => $panelId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'previous_state' => $panelState
                ]);

                $this->stateManager->updatePanelState($panelId, [
                    'status' => StateManager::PANEL_STATE_FAILED,
                    'error' => 'Character composition failed: ' . $e->getMessage(),
                    'failed_at' => time()
                ]);

                // If this is a critical error, fail the entire strip
                if (isset($panelState['strip_id'])) {
                    $this->stateManager->updateStripState($panelState['strip_id'], [
                        'status' => StateManager::STATE_FAILED,
                        'error' => 'Panel composition failed: ' . $e->getMessage(),
                        'failed_at' => time()
                    ]);
                }

                throw $e;
            }
        } else {
            $this->logger->debug('Panel not ready for character composition', [
                'panel_id' => $panelId,
                'missing_requirements' => [
                    'background_url' => !isset($panelState['background_url']),
                    'characters' => !isset($panelState['characters']) || empty($panelState['characters']),
                    'wrong_status' => ($panelState['status'] ?? '') !== StateManager::PANEL_STATE_BACKGROUND_READY
                ],
                'current_state' => $panelState
            ]);
        }
    }

    private function findStateFile(string $predictionId, string $type = 'panel'): ?array
    {
        $maxRetries = 3;
        $retryDelay = 1;
        $attempt = 0;

        while ($attempt < $maxRetries) {
            $this->logger->debug('Searching for state file', [
                'prediction_id' => $predictionId,
                'type' => $type,
                'attempt' => $attempt + 1,
                'max_attempts' => $maxRetries
            ]);

            // Get all state files
            $stateFiles = glob($this->config->getTempPath() . "/state_{$type}_*.json");

            if (empty($stateFiles)) {
                $this->logger->warning('No state files found', [
                    'type' => $type,
                    'temp_path' => $this->config->getTempPath()
                ]);
                sleep($retryDelay);
                $attempt++;
                continue;
            }

            // Track file read errors
            $errors = [];

            foreach ($stateFiles as $statePath) {
                try {
                    if (!file_exists($statePath)) {
                        continue;
                    }

                    // Get exclusive lock for reading
                    $fp = fopen($statePath, 'r');
                    if (!$fp) {
                        $errors[] = "Could not open file: $statePath";
                        continue;
                    }

                    if (!flock($fp, LOCK_SH)) {
                        fclose($fp);
                        $errors[] = "Could not acquire lock: $statePath";
                        continue;
                    }

                    $content = fread($fp, filesize($statePath));
                    flock($fp, LOCK_UN);
                    fclose($fp);

                    $state = json_decode($content, true);
                    if (!$state) {
                        $errors[] = "Invalid JSON in file: $statePath";
                        continue;
                    }

                    // Check for prediction ID in different locations based on type
                    $found = false;
                    switch ($type) {
                        case 'panel':
                            $found = isset($state['prediction_id']) &&
                                $state['prediction_id'] === $predictionId;
                            break;
                        case 'character':
                            $found = isset($state['cartoonify_prediction_id']) &&
                                $state['cartoonify_prediction_id'] === $predictionId;
                            break;
                        case 'strip':
                            // For strip files, check panels and characters
                            if (isset($state['panels'])) {
                                foreach ($state['panels'] as $panel) {
                                    if (
                                        isset($panel['prediction_id']) &&
                                        $panel['prediction_id'] === $predictionId
                                    ) {
                                        $found = true;
                                        break;
                                    }
                                }
                            }
                            if (!$found && isset($state['characters'])) {
                                foreach ($state['characters'] as $char) {
                                    if (
                                        isset($char['prediction_id']) &&
                                        $char['prediction_id'] === $predictionId
                                    ) {
                                        $found = true;
                                        break;
                                    }
                                }
                            }
                            break;
                    }

                    if ($found) {
                        $id = basename($statePath, '.json');
                        $id = str_replace("state_{$type}_", '', $id);

                        $this->logger->info("Found matching state file", [
                            'prediction_id' => $predictionId,
                            'type' => $type,
                            'id' => $id,
                            'status' => $state['status'] ?? 'unknown'
                        ]);

                        return [
                            'id' => $id,
                            'state' => $state,
                            'path' => $statePath
                        ];
                    }
                } catch (Exception $e) {
                    $errors[] = $e->getMessage();
                }
            }

            if (!empty($errors)) {
                $this->logger->warning('Errors while searching state files', [
                    'prediction_id' => $predictionId,
                    'type' => $type,
                    'attempt' => $attempt + 1,
                    'errors' => $errors
                ]);
            }

            sleep($retryDelay);
            $attempt++;
        }

        $this->logger->error('Failed to find state file after retries', [
            'prediction_id' => $predictionId,
            'type' => $type,
            'max_attempts' => $maxRetries
        ]);

        return null;
    }

    private function processWebhook(array $payload): void
    {
        $predictionId = $payload['id'];
        $status = $payload['status'] ?? null;
        $output = $payload['output'] ?? null;
        $error = $payload['error'] ?? null;
        $version = $payload['version'] ?? null;

        if (!$status) {
            throw new Exception('Missing status in webhook data');
        }

        // Determine model type from version
        $modelType = null;
        foreach ($this->config->get('replicate.models') as $type => $model) {
            if ($model['version'] === $version) {
                $modelType = $type;
                break;
            }
        }

        if (!$modelType) {
            throw new Exception('Unknown model version');
        }

        // Handle different model types
        switch ($modelType) {
            case 'cartoonify':
                $this->handleCartoonifyWebhook($payload);
                break;
            case 'sdxl':
                $this->handleSdxlWebhook($payload);
                break;
            case 'llama':
                $this->handleLlamaWebhook($payload);
                break;
            default:
                throw new Exception('Unsupported model type');
        }
    }

    private function handleLlamaWebhook(array $payload): void
    {
        $predictionId = $payload['id'];
        $status = $payload['status'];
        $output = $payload['output'] ?? null;

        // Get pending file data to retrieve stripId
        $pendingFile = $this->config->getTempPath() . "/pending_{$predictionId}.json";
        if (!file_exists($pendingFile)) {
            $this->logger->error('Pending file not found for LLaMA webhook', [
                'prediction_id' => $predictionId
            ]);
            return;
        }

        $pendingData = json_decode(file_get_contents($pendingFile), true);
        if (!$pendingData || !isset($pendingData['strip_id'])) {
            $this->logger->error('Invalid pending data for LLaMA webhook', [
                'prediction_id' => $predictionId
            ]);
            return;
        }

        $stripId = $pendingData['strip_id'];

        if ($status === 'succeeded' && $output) {
            // Store the result
            $resultFile = $this->config->get('paths.temp') . "/llama_result_{$predictionId}.json";
            file_put_contents($resultFile, json_encode([
                'timestamp' => time(),
                'output' => $output,
                'status' => 'completed'
            ]));

            // Update strip state using correct state constant
            $this->stateManager->updateStripState($stripId, [
                'status' => StateManager::STATE_PANELS_GENERATING,  // Changed from 'story_segmented'
                'progress' => 40,
                'current_operation' => 'Story segmentation completed, starting panel generation',
                'llama_result' => $output
            ]);

            $this->logger->debug('LLaMA webhook processed successfully', [
                'strip_id' => $stripId,
                'prediction_id' => $predictionId,
                'new_state' => StateManager::STATE_PANELS_GENERATING
            ]);
        } else {
            // Handle failure using correct state constant
            $this->stateManager->updateStripState($stripId, [
                'status' => StateManager::STATE_FAILED,  // Using constant instead of string
                'error' => $payload['error'] ?? 'LLaMA processing failed',
                'current_operation' => 'Story segmentation failed'
            ]);

            $this->logger->error('LLaMA webhook processing failed', [
                'strip_id' => $stripId,
                'prediction_id' => $predictionId,
                'error' => $payload['error'] ?? 'Unknown error'
            ]);
        }

        // Clean up pending file
        if (file_exists($pendingFile)) {
            unlink($pendingFile);
        }
    }
}

// Initialize and handle webhook
$config = new Config();
$logger = new Logger();
$stateManager = new StateManager($config->getTempPath(), $logger);
$handler = new WebhookHandler($stateManager, $logger, $config);
$handler->handleWebhook();
