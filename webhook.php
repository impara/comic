<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/models/Config.php';
require_once __DIR__ . '/interfaces/LoggerInterface.php';
require_once __DIR__ . '/models/Logger.php';
require_once __DIR__ . '/models/ReplicateClient.php';

$logger = new Logger();
$config = Config::getInstance();
$replicateClient = new ReplicateClient($logger);

try {
    // Get the webhook payload
    $payload = file_get_contents('php://input');
    $data = json_decode($payload, true);

    $logger->error("TEST_LOG - Webhook handler start", [
        'payload_length' => strlen($payload),
        'has_data' => !empty($data),
        'status' => $data['status'] ?? 'unknown',
        'prediction_id' => $data['id'] ?? 'none'
    ]);

    if (!$data) {
        throw new Exception("Invalid JSON payload received");
    }

    // Check if we're in testing mode (no webhook secret configured)
    $webhookSecret = $config->get('replicate.webhook_secret');
    $isTestingMode = empty($webhookSecret);

    $logger->info("Webhook mode", [
        'testing_mode' => $isTestingMode,
        'has_secret' => !empty($webhookSecret)
    ]);

    if ($isTestingMode) {
        $logger->warning("Running in TESTING MODE - webhook signature verification disabled");
    } else {
        // Production mode - verify signature
        $signature = $_SERVER['HTTP_REPLICATE_WEBHOOK_SIGNATURE'] ?? '';
        if (empty($signature)) {
            throw new Exception("Missing webhook signature");
        }

        $computedSignature = hash_hmac('sha256', $payload, $webhookSecret);
        if (!hash_equals($computedSignature, $signature)) {
            throw new Exception("Invalid webhook signature");
        }

        $logger->info("Webhook signature verified successfully");
    }

    // Basic validation of the payload
    if (empty($data['id']) || !isset($data['status'])) {
        throw new Exception("Invalid webhook payload structure");
    }

    $logger->info("Received webhook from Replicate", [
        'prediction_id' => $data['id'],
        'status' => $data['status']
    ]);

    // Store the prediction result
    $predictionId = $data['id'];
    $tempPath = $config->getTempPath();

    // First check pending files to find the stage
    $pendingFiles = glob($tempPath . "pending_*.json");
    $logger->error("TEST_LOG - Searching for pending file", [
        'prediction_id' => $predictionId,
        'pending_files' => array_map('basename', $pendingFiles)
    ]);

    foreach ($pendingFiles as $pendingFile) {
        $pending = json_decode(file_get_contents($pendingFile), true);

        // Verify pending file contents
        $logger->error("TEST_LOG - Checking pending file", [
            'file' => basename($pendingFile),
            'prediction_id_matches' => ($pending['prediction_id'] ?? null) === $predictionId,
            'has_original_panel_id' => isset($pending['original_panel_id']),
            'stage' => $pending['stage'] ?? 'unknown'
        ]);

        if ($pending && isset($pending['prediction_id']) && $pending['prediction_id'] === $predictionId) {
            $originalPanelId = $pending['original_panel_id'] ?? null;
            $currentStage = $pending['stage'] ?? 'unknown';

            if (!$originalPanelId) {
                $logger->error("Missing original panel ID", [
                    'prediction_id' => $predictionId,
                    'pending_file' => basename($pendingFile)
                ]);
                continue;
            }

            $logger->error("TEST_LOG - Processing webhook", [
                'prediction_id' => $predictionId,
                'stage' => $currentStage,
                'original_panel_id' => $originalPanelId,
                'status' => $data['status']
            ]);

            // Get state file path
            $stateFile = $tempPath . "state_{$originalPanelId}.json";
            if (!file_exists($stateFile)) {
                $logger->error("State file not found", [
                    'state_file' => basename($stateFile),
                    'original_panel_id' => $originalPanelId
                ]);
                continue;
            }

            $state = json_decode(file_get_contents($stateFile), true);
            if (!$state) {
                $logger->error("Invalid state file format", [
                    'state_file' => basename($stateFile)
                ]);
                continue;
            }

            if ($currentStage === 'sdxl') {
                // Handle SDXL completion
                if ($data['status'] === 'succeeded' && !empty($data['output'])) {
                    // Update state with success
                    $state['sdxl_status'] = 'succeeded';
                    $state['sdxl_output'] = is_array($data['output']) ? $data['output'][0] : $data['output'];
                    $state['sdxl_completed_at'] = time();
                    $state['status'] = 'succeeded';
                    if (file_put_contents($stateFile, json_encode($state)) === false) {
                        throw new Exception("Failed to update state file");
                    }

                    // Write final result
                    $finalResult = [
                        'id' => $originalPanelId,
                        'status' => 'succeeded',
                        'output' => $state['sdxl_output'],
                        'completed_at' => date('c')
                    ];
                    if (file_put_contents($tempPath . "{$originalPanelId}.json", json_encode($finalResult)) === false) {
                        throw new Exception("Failed to write final result file");
                    }

                    $logger->error("TEST_LOG - Updated state file with SDXL completion", [
                        'state_file' => basename($stateFile),
                        'panel_id' => $originalPanelId,
                        'sdxl_prediction_id' => $predictionId,
                        'output_url' => $state['sdxl_output']
                    ]);
                } else if ($data['status'] === 'failed') {
                    // Update state with failure
                    $state['sdxl_status'] = 'failed';
                    $state['sdxl_error'] = $data['error'] ?? 'SDXL generation failed';
                    $state['sdxl_failed_at'] = time();
                    $state['status'] = 'failed';
                    if (file_put_contents($stateFile, json_encode($state)) === false) {
                        throw new Exception("Failed to update state file with error");
                    }

                    // Write final result with error
                    $finalResult = [
                        'id' => $originalPanelId,
                        'status' => 'failed',
                        'error' => $state['sdxl_error'],
                        'failed_at' => date('c')
                    ];
                    if (file_put_contents($tempPath . "{$originalPanelId}.json", json_encode($finalResult)) === false) {
                        throw new Exception("Failed to write final error file");
                    }

                    $logger->error("TEST_LOG - Updated state file with SDXL failure", [
                        'state_file' => basename($stateFile),
                        'panel_id' => $originalPanelId,
                        'sdxl_prediction_id' => $predictionId,
                        'error' => $state['sdxl_error']
                    ]);
                }

                // Clean up all pending files for this panel
                $allPendingFiles = glob($tempPath . "pending_*.json");
                foreach ($allPendingFiles as $otherFile) {
                    $otherPending = json_decode(file_get_contents($otherFile), true);
                    if (isset($otherPending['original_panel_id']) && $otherPending['original_panel_id'] === $originalPanelId) {
                        @unlink($otherFile);
                        $logger->error("TEST_LOG - Cleaned up pending file", [
                            'file' => basename($otherFile),
                            'original_panel_id' => $originalPanelId
                        ]);
                    }
                }

                return;
            } else if ($currentStage === 'cartoonify') {
                // Handle cartoonification completion
                if ($data['status'] === 'succeeded') {
                    // Update cartoonification request status
                    if (!isset($state['cartoonification_requests'])) {
                        $state['cartoonification_requests'] = [];
                    }

                    $requestFound = false;
                    foreach ($state['cartoonification_requests'] as &$request) {
                        if ($request['prediction_id'] === $predictionId) {
                            $request['status'] = 'succeeded';
                            $request['completed_at'] = time();
                            $request['output'] = is_array($data['output']) ? $data['output'][0] : $data['output'];
                            $requestFound = true;
                            break;
                        }
                    }

                    if (!$requestFound) {
                        // Add new request if not found
                        $state['cartoonification_requests'][] = [
                            'prediction_id' => $predictionId,
                            'status' => 'succeeded',
                            'completed_at' => time(),
                            'output' => is_array($data['output']) ? $data['output'][0] : $data['output']
                        ];
                    }

                    // Check if all cartoonification requests are complete
                    $allComplete = true;
                    foreach ($state['cartoonification_requests'] as $request) {
                        if ($request['status'] !== 'succeeded') {
                            $allComplete = false;
                            break;
                        }
                    }

                    if ($allComplete) {
                        $state['status'] = 'cartoonification_complete';
                    }

                    // Add file locking for state updates
                    $lockFile = $tempPath . "state_{$originalPanelId}.lock";
                    $fp = fopen($lockFile, 'w');
                    if (!flock($fp, LOCK_EX)) {
                        throw new Exception("Could not acquire lock for state file update");
                    }

                    try {
                        if (file_put_contents($stateFile, json_encode($state)) === false) {
                            throw new Exception("Failed to update state file");
                        }

                        $logger->error("TEST_LOG - Updated state file with cartoonification success", [
                            'state_file' => basename($stateFile),
                            'prediction_id' => $predictionId,
                            'status' => $state['status'],
                            'cartoonification_count' => count($state['cartoonification_requests']),
                            'locked' => true
                        ]);

                        // Start SDXL if cartoonification is complete
                        if ($allComplete) {
                            // Get cartoonified URL
                            $cartoonifiedUrl = null;
                            foreach ($state['cartoonification_requests'] as $request) {
                                if ($request['status'] === 'succeeded' && !empty($request['output'])) {
                                    $cartoonifiedUrl = $request['output'];
                                    break;
                                }
                            }

                            if (!$cartoonifiedUrl) {
                                throw new Exception("No successful cartoonification output found");
                            }

                            // Extract panel data and start SDXL under the same lock
                            $panelData = $pending['panel_data'];
                            if (is_string($panelData)) {
                                $panelData = json_decode($panelData, true);
                            }

                            if (!$panelData || !isset($panelData['scene_description'])) {
                                $logger->error("TEST_LOG - Panel data validation failed", [
                                    'panel_data_type' => gettype($panelData),
                                    'has_scene_description' => isset($panelData['scene_description']),
                                    'raw_panel_data' => $pending['panel_data']
                                ]);
                                throw new Exception("Invalid or missing panel data in pending file");
                            }

                            $logger->error("TEST_LOG - Starting SDXL with panel data", [
                                'has_scene_description' => isset($panelData['scene_description']),
                                'has_characters' => isset($panelData['characters']),
                                'panel_id' => $originalPanelId,
                                'scene_description' => $panelData['scene_description'],
                                'character_style' => $panelData['characters'][0]['options']['style'] ?? 'modern',
                                'has_character_options' => isset($panelData['characters'][0]['options']),
                                'raw_character_data' => $panelData['characters'][0]
                            ]);

                            // Start SDXL
                            $sdxlResult = $replicateClient->generateImage([
                                'cartoonified_image' => $cartoonifiedUrl,
                                'prompt' => $panelData['scene_description'],
                                'original_panel_id' => $originalPanelId,
                                'options' => [
                                    'style' => $panelData['characters'][0]['options']['style'] ?? 'modern'
                                ]
                            ]);

                            // Update state with SDXL info while still holding the lock
                            $state['sdxl_status'] = 'processing';
                            $state['sdxl_prediction_id'] = $sdxlResult['id'];
                            $state['sdxl_started_at'] = time();
                            if (file_put_contents($stateFile, json_encode($state)) === false) {
                                throw new Exception("Failed to update state file with SDXL info");
                            }

                            // Create SDXL pending file
                            $sdxlPendingFile = $tempPath . "pending_{$sdxlResult['id']}.json";
                            file_put_contents($sdxlPendingFile, json_encode([
                                'prediction_id' => $sdxlResult['id'],
                                'original_panel_id' => $originalPanelId,
                                'stage' => 'sdxl',
                                'cartoonified_url' => $cartoonifiedUrl,
                                'started_at' => time(),
                                'panel_data' => $pending['panel_data']
                            ]));

                            if (!file_exists($sdxlPendingFile)) {
                                throw new Exception("SDXL pending file was not created");
                            }

                            $logger->error("TEST_LOG - Created SDXL pending file and updated state", [
                                'sdxl_pending_file' => basename($sdxlPendingFile),
                                'state_file' => basename($stateFile),
                                'locked' => true
                            ]);
                        }
                    } finally {
                        // Always release the lock
                        flock($fp, LOCK_UN);
                        fclose($fp);
                        @unlink($lockFile);
                    }

                    // Clean up cartoonification pending file after releasing lock
                    @unlink($pendingFile);
                    $logger->error("TEST_LOG - Cleaned up cartoonification pending file", [
                        'file' => basename($pendingFile),
                        'prediction_id' => $predictionId
                    ]);

                    return;
                } else if ($data['status'] === 'failed') {
                    // Handle failed cartoonification
                    if (!isset($state['cartoonification_requests'])) {
                        $state['cartoonification_requests'] = [];
                    }

                    $requestFound = false;
                    foreach ($state['cartoonification_requests'] as &$request) {
                        if ($request['prediction_id'] === $predictionId) {
                            $request['status'] = 'failed';
                            $request['completed_at'] = time();
                            $request['error'] = $data['error'] ?? 'Cartoonification failed';
                            $requestFound = true;
                            break;
                        }
                    }

                    if (!$requestFound) {
                        // Add new failed request if not found
                        $state['cartoonification_requests'][] = [
                            'prediction_id' => $predictionId,
                            'status' => 'failed',
                            'completed_at' => time(),
                            'error' => $data['error'] ?? 'Cartoonification failed'
                        ];
                    }

                    // Mark the entire process as failed
                    $state['status'] = 'failed';
                    $state['error'] = 'Character cartoonification failed';
                    if (file_put_contents($stateFile, json_encode($state)) === false) {
                        throw new Exception("Failed to update state file with failure");
                    }

                    // Create final result with error
                    $finalResult = [
                        'id' => $originalPanelId,
                        'status' => 'failed',
                        'error' => $state['error'],
                        'failed_at' => date('c')
                    ];
                    if (file_put_contents($tempPath . "{$originalPanelId}.json", json_encode($finalResult)) === false) {
                        throw new Exception("Failed to write final error file");
                    }

                    $logger->error("TEST_LOG - Updated state file with cartoonification failure", [
                        'state_file' => basename($stateFile),
                        'prediction_id' => $predictionId,
                        'error' => $state['error']
                    ]);
                }

                // Clean up this pending file
                @unlink($pendingFile);
                return;
            }
        }
    }

    // Return success response
    http_response_code(200);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    $logger->error("Webhook processing failed", [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);

    // Try to update state file if we have panel ID
    if (isset($originalPanelId) && isset($stateFile) && file_exists($stateFile)) {
        $state = json_decode(file_get_contents($stateFile), true);
        $state['status'] = 'failed';
        $state['error'] = $e->getMessage();
        $state['failed_at'] = time();
        file_put_contents($stateFile, json_encode($state));

        // Create final result file with error
        $finalResult = [
            'id' => $originalPanelId,
            'status' => 'failed',
            'error' => $e->getMessage(),
            'failed_at' => date('c')
        ];
        file_put_contents($tempPath . "{$originalPanelId}.json", json_encode($finalResult));

        $logger->error("TEST_LOG - Created final error result", [
            'panel_id' => $originalPanelId,
            'error' => $e->getMessage()
        ]);
    }

    // Clean up any pending files if we have panel ID
    if (isset($originalPanelId) && isset($tempPath)) {
        $pendingFiles = glob($tempPath . "pending_*.json");
        foreach ($pendingFiles as $pendingFile) {
            $pending = json_decode(file_get_contents($pendingFile), true);
            if (isset($pending['original_panel_id']) && $pending['original_panel_id'] === $originalPanelId) {
                @unlink($pendingFile);
                $logger->error("TEST_LOG - Cleaned up pending file during error", [
                    'file' => basename($pendingFile),
                    'original_panel_id' => $originalPanelId
                ]);
            }
        }
    }

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
