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
            // Log request details
            $this->logger->debug('Webhook request received', [
                'timestamp' => date('Y-m-d H:i:s'),
                'request_method' => $_SERVER['REQUEST_METHOD'],
                'remote_addr' => $_SERVER['REMOTE_ADDR'],
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'not set',
                'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'not set',
                'content_length' => $_SERVER['CONTENT_LENGTH'] ?? 'not set',
                'query_params' => $_GET,
                'server_info' => [
                    'software' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
                    'protocol' => $_SERVER['SERVER_PROTOCOL'] ?? 'unknown',
                    'request_time' => $_SERVER['REQUEST_TIME'] ?? 'unknown'
                ]
            ]);

            // Log raw request data
            $rawInput = file_get_contents('php://input');
            $this->logger->debug('Raw webhook payload received', [
                'data' => $rawInput,
                'length' => strlen($rawInput),
                'headers' => getallheaders()
            ]);

            // Get webhook payload
            $payload = json_decode($rawInput, true);
            if (!$payload) {
                $jsonError = json_last_error_msg();
                $this->logger->error('Failed to parse webhook payload', [
                    'error' => $jsonError,
                    'raw_input_preview' => substr($rawInput, 0, 1000) // First 1000 chars for debugging
                ]);
                throw new Exception('Invalid webhook payload: ' . $jsonError);
            }

            $this->logger->debug('Parsed webhook payload', [
                'payload' => $payload,
                'prediction_id' => $payload['id'] ?? 'not set',
                'status' => $payload['status'] ?? 'not set',
                'version' => $payload['version'] ?? 'not set',
                'has_output' => isset($payload['output']),
                'has_error' => isset($payload['error'])
            ]);

            // Extract prediction details
            $predictionId = $payload['id'] ?? null;
            $status = $payload['status'] ?? null;
            $output = $payload['output'] ?? null;
            $error = $payload['error'] ?? null;
            $version = $payload['version'] ?? null;

            if (!$predictionId || !$status) {
                throw new Exception('Missing required webhook data');
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
                default:
                    throw new Exception('Unsupported model type');
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
            'raw_output' => $output,
            'raw_error' => $error,
            'timestamp' => date('Y-m-d H:i:s')
        ]);

        // Find associated panel state
        $panelStates = glob($this->config->get('paths.temp') . '/state_panel_*.json');

        $this->logger->debug('Searching for panel state', [
            'prediction_id' => $predictionId,
            'total_panel_states' => count($panelStates),
            'temp_path' => $this->config->get('paths.temp'),
            'panel_state_files' => $panelStates
        ]);

        $targetPanel = null;
        $targetState = null;

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
                    $this->logger->error('Timeout waiting for webhook lock', [
                        'prediction_id' => $predictionId,
                        'lock_file' => $lockFile,
                        'wait_time' => time() - $startTime
                    ]);
                    throw new Exception("Timeout waiting for webhook lock");
                }
                usleep(250000); // Wait 250ms before retrying
            }

            $this->logger->debug('Acquired webhook lock', [
                'prediction_id' => $predictionId,
                'lock_file' => $lockFile,
                'time_taken' => time() - $startTime
            ]);

            foreach ($panelStates as $statePath) {
                $state = json_decode(file_get_contents($statePath), true);
                if (!$state) {
                    $this->logger->warning('Failed to parse panel state file', [
                        'prediction_id' => $predictionId,
                        'state_path' => $statePath,
                        'raw_content' => file_get_contents($statePath),
                        'json_error' => json_last_error_msg()
                    ]);
                    continue;
                }

                if (isset($state['background_prediction_id']) && $state['background_prediction_id'] === $predictionId) {
                    $targetPanel = basename($statePath, '.json');
                    $targetState = $state;
                    $this->logger->debug('Found matching panel state', [
                        'prediction_id' => $predictionId,
                        'panel_id' => $targetPanel,
                        'state_path' => $statePath,
                        'state' => $state
                    ]);
                    break;
                }
            }

            if (!$targetPanel || !$targetState) {
                $this->logger->error('Could not find panel state', [
                    'prediction_id' => $predictionId,
                    'searched_files' => $panelStates,
                    'temp_path' => $this->config->get('paths.temp')
                ]);
                throw new Exception('Could not find panel state for prediction ' . $predictionId);
            }

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
                $this->logger->error('Panel background generation failed', [
                    'panel_id' => $targetPanel,
                    'prediction_id' => $predictionId,
                    'error' => $error,
                    'current_state' => $targetState
                ]);

                // Update panel state with error
                $this->stateManager->updatePanelState($targetPanel, [
                    'status' => StateManager::PANEL_STATE_FAILED,
                    'error' => $error ?? 'Background generation failed',
                    'failed_at' => time()
                ]);

                $this->logger->error('Updated panel state after failure', [
                    'panel_id' => $targetPanel,
                    'prediction_id' => $predictionId,
                    'updated_state' => $this->stateManager->getPanelState($targetPanel)
                ]);
            } else {
                $this->logger->debug('Received non-terminal SDXL webhook status', [
                    'prediction_id' => $predictionId,
                    'panel_id' => $targetPanel,
                    'status' => $status,
                    'current_state' => $targetState
                ]);
            }

            // Get updated state and map for frontend
            if (isset($targetState['strip_id'])) {
                $stripState = $this->stateManager->getStripState($targetState['strip_id']);
                if ($stripState) {
                    // Map state for frontend response
                    $apiState = $this->stateManager->mapStateForApi($stripState);

                    $this->logger->info('Strip state updated', [
                        'strip_id' => $targetState['strip_id'],
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
                    $this->logger->error('Failed to retrieve strip state', [
                        'strip_id' => $targetState['strip_id'],
                        'panel_id' => $targetPanel,
                        'prediction_id' => $predictionId
                    ]);
                }
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

    private function handleCartoonifySuccess(string $stripId, string $characterId, string $outputUrl, array $pendingData): void
    {
        // Generate unique filename for cartoonified image
        $originalFilename = $pendingData['image_path'] ?? uniqid();
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
            'character_statuses' => array_map(fn($char) => $char['status'], $stripState['characters'])
        ]);

        $totalCharacters = count($stripState['characters']);
        $completedCharacters = count(array_filter(
            $stripState['characters'],
            fn($char) => $char['status'] === 'completed'
        ));

        $this->logger->debug('Character completion status', [
            'strip_id' => $stripId,
            'total_characters' => $totalCharacters,
            'completed_characters' => $completedCharacters,
            'all_completed' => ($completedCharacters === $totalCharacters)
        ]);

        if ($completedCharacters === $totalCharacters) {
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
                        'status' => StateManager::STATE_CHARACTERS_COMPLETE
                    ]);

                    // Move to story segmenting
                    $this->logger->debug('Updating strip state to story segmenting', [
                        'strip_id' => $stripId
                    ]);

                    $stripState = $this->stateManager->updateStripState($stripId, [
                        'status' => StateManager::STATE_STORY_SEGMENTING
                    ]);

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
                } catch (Exception $e) {
                    $this->logger->error('Failed to start panel generation', [
                        'strip_id' => $stripId,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    $this->stateManager->updateStripState($stripId, [
                        'status' => StateManager::STATE_FAILED,
                        'error' => 'Failed to start panel generation: ' . $e->getMessage()
                    ]);
                    throw $e;
                }
            } else {
                $this->logger->debug('Strip not in correct state for panel generation', [
                    'strip_id' => $stripId,
                    'current_status' => $stripState['status'],
                    'required_status' => [
                        StateManager::STATE_CHARACTERS_PENDING,
                        StateManager::STATE_CHARACTERS_PROCESSING
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

        if (
            isset($panelState['background_url']) &&
            isset($panelState['characters']) &&
            !empty($panelState['characters']) &&
            $panelState['status'] === StateManager::PANEL_STATE_BACKGROUND_READY
        ) {
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
                        'status' => $char['status'] ?? 'unknown'
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
}

// Initialize and handle webhook
$config = new Config();
$logger = new Logger();
$stateManager = new StateManager($config->getTempPath(), $logger);
$handler = new WebhookHandler($stateManager, $logger, $config);
$handler->handleWebhook();
