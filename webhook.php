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

            // Get pending file data
            $pendingFile = $this->config->getTempPath() . "pending_{$predictionId}.json";
            if (!file_exists($pendingFile)) {
                throw new Exception('Pending file not found');
            }

            $pendingData = json_decode(file_get_contents($pendingFile), true);
            if (!$pendingData) {
                throw new Exception('Invalid pending data');
            }

            // Extract required data
            $stripId = $pendingData['strip_id'] ?? null;
            $characterId = $pendingData['options']['character_id'] ?? null;

            if (!$stripId || !$characterId) {
                throw new Exception('Missing required data');
            }

            if ($status === 'succeeded' && $output) {
                $this->handleCartoonifySuccess($stripId, $characterId, $output, $pendingData);
            } else {
                $this->handleCartoonifyFailure($stripId, $characterId, $payload['error'] ?? 'Processing failed');
            }

            // Clean up pending file
            if (file_exists($pendingFile)) {
                unlink($pendingFile);
            }

            // Get updated state and map for frontend
            $stripState = $this->stateManager->getStripState($stripId);
            if ($stripState) {
                // Map state for frontend response
                $apiState = $this->stateManager->mapStateForApi($stripState);

                $this->logger->info('Strip state updated after cartoonify', [
                    'strip_id' => $stripId,
                    'status' => $apiState['status'],
                    'progress' => $apiState['progress'],
                    'current_operation' => $apiState['current_operation']
                ]);
            }
        } finally {
            // Always release the lock
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

        // Find associated panel state
        $panelStates = glob($this->config->get('paths.temp') . '/state_panel_*.json');
        $targetPanel = null;
        $targetState = null;

        // Use a transaction-like approach for state updates
        $lockFile = $this->config->get('paths.temp') . "/webhook_{$predictionId}.lock";
        $lockFp = fopen($lockFile, 'c+');

        if (!$lockFp) {
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

            foreach ($panelStates as $statePath) {
                $state = json_decode(file_get_contents($statePath), true);
                if (isset($state['background_prediction_id']) && $state['background_prediction_id'] === $predictionId) {
                    $targetPanel = basename($statePath, '.json');
                    $targetState = $state;
                    break;
                }
            }

            if (!$targetPanel || !$targetState) {
                throw new Exception('Could not find panel state for prediction ' . $predictionId);
            }

            if ($status === 'succeeded' && $output) {
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
                    'background_url' => $output[0]
                ]);

                // Check if we can proceed with character composition
                $this->checkAndStartCharacterComposition($targetPanel, $targetState);
            } elseif ($status === 'failed') {
                // Update panel state with error
                $this->stateManager->updatePanelState($targetPanel, [
                    'status' => StateManager::PANEL_STATE_FAILED,
                    'error' => $error ?? 'Background generation failed',
                    'failed_at' => time()
                ]);

                $this->logger->error('Panel background generation failed', [
                    'panel_id' => $targetPanel,
                    'prediction_id' => $predictionId,
                    'error' => $error
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
                        'current_operation' => $apiState['current_operation']
                    ]);
                }
            }
        } finally {
            // Always release the lock
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
        $totalCharacters = count($stripState['characters']);
        $completedCharacters = count(array_filter(
            $stripState['characters'],
            fn($char) => $char['status'] === 'completed'
        ));

        if ($completedCharacters === $totalCharacters) {
            if (
                $stripState['status'] === StateManager::STATE_CHARACTERS_PENDING ||
                $stripState['status'] === StateManager::STATE_CHARACTERS_PROCESSING
            ) {
                try {
                    // Update to characters complete
                    $this->stateManager->updateStripState($stripId, [
                        'status' => StateManager::STATE_CHARACTERS_COMPLETE
                    ]);

                    // Move to story segmenting
                    $this->stateManager->updateStripState($stripId, [
                        'status' => StateManager::STATE_STORY_SEGMENTING
                    ]);

                    // Initialize dependencies properly
                    $imageComposer = new ImageComposer($this->logger, $this->config);
                    $characterProcessor = new CharacterProcessor($this->logger, $this->config);
                    $storyParser = new StoryParser($this->logger);

                    // Start panel generation
                    $comicGenerator = new ComicGenerator(
                        $this->stateManager,
                        $this->logger,
                        $this->config,
                        $imageComposer,
                        $characterProcessor,
                        $storyParser
                    );
                    $comicGenerator->startPanelGeneration($stripId);
                } catch (Exception $e) {
                    $this->logger->error('Failed to start panel generation', [
                        'strip_id' => $stripId,
                        'error' => $e->getMessage()
                    ]);
                    $this->stateManager->updateStripState($stripId, [
                        'status' => StateManager::STATE_FAILED,
                        'error' => 'Failed to start panel generation: ' . $e->getMessage()
                    ]);
                }
            }
        }
    }

    private function checkAndStartCharacterComposition(string $panelId, array $panelState): void
    {
        if (!isset($panelState['strip_id'])) {
            throw new Exception('Panel state missing strip ID');
        }

        $stripState = $this->stateManager->getStripState($panelState['strip_id']);
        if (!$stripState) {
            throw new Exception('Strip state not found');
        }

        // Start character composition if all characters are ready
        $allCharactersReady = true;
        foreach ($stripState['characters'] as $character) {
            if ($character['status'] !== 'completed') {
                $allCharactersReady = false;
                break;
            }
        }

        if ($allCharactersReady) {
            try {
                // Initialize ImageComposer with proper dependencies
                $imageComposer = new ImageComposer($this->logger, $this->config);
                $composedPath = $imageComposer->composePanelImage(
                    $stripState['characters'],
                    $panelState['description'],
                    $panelState['options'] ?? []
                );

                $this->stateManager->updatePanelState($panelId, [
                    'status' => 'completed',
                    'output_path' => $composedPath,
                    'completed_at' => time()
                ]);
            } catch (Exception $e) {
                $this->stateManager->updatePanelState($panelId, [
                    'status' => 'failed',
                    'error' => 'Character composition failed: ' . $e->getMessage(),
                    'failed_at' => time()
                ]);
                throw $e;
            }
        }
    }
}

// Initialize and handle webhook
$config = new Config();
$logger = new Logger();
$stateManager = new StateManager($config->getTempPath(), $logger);
$handler = new WebhookHandler($stateManager, $logger, $config);
$handler->handleWebhook();
