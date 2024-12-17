<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/models/Config.php';
require_once __DIR__ . '/interfaces/LoggerInterface.php';
require_once __DIR__ . '/models/Logger.php';

$logger = new Logger();
$config = Config::getInstance();

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

    // First check if this is an SDXL completion by looking for panel files
    $panelFiles = glob($tempPath . "panel_*.json");
    foreach ($panelFiles as $panelFile) {
        $panel = json_decode(file_get_contents($panelFile), true);
        if (isset($panel['sdxl_prediction_id']) && $panel['sdxl_prediction_id'] === $predictionId) {
            $logger->error("TEST_LOG - Found matching SDXL completion", [
                'panel_id' => basename($panelFile, '.json'),
                'sdxl_prediction_id' => $predictionId
            ]);

            if ($data['status'] === 'succeeded' && !empty($data['output'])) {
                // Update panel with final output
                $panel['status'] = 'succeeded';
                $panel['output'] = is_array($data['output']) ? $data['output'][0] : $data['output'];
                $panel['completed_at'] = date('c');
                file_put_contents($panelFile, json_encode($panel));

                // Also update the original panel's state file if it exists
                $stateFile = $tempPath . "state_" . basename($panelFile);
                if (file_exists($stateFile)) {
                    $state = json_decode(file_get_contents($stateFile), true);
                    $state['sdxl_status'] = 'succeeded';
                    $state['sdxl_output'] = $panel['output'];
                    $state['sdxl_completed_at'] = date('c');
                    file_put_contents($stateFile, json_encode($state));
                }

                $logger->error("TEST_LOG - Updated panel with SDXL output", [
                    'panel_id' => basename($panelFile, '.json'),
                    'output_url' => $panel['output']
                ]);
            }
            return;
        }
    }

    // If not SDXL, check for cartoonification completion
    $pendingFiles = glob($tempPath . "pending_*.json");
    foreach ($pendingFiles as $pendingFile) {
        $pending = json_decode(file_get_contents($pendingFile), true);

        if ($pending && isset($pending['prediction_id']) && $pending['prediction_id'] === $predictionId) {
            $logger->error("TEST_LOG - Found matching cartoonification", [
                'pending_file' => basename($pendingFile),
                'prediction_id' => $predictionId,
                'original_panel_id' => $pending['original_panel_id'] ?? null
            ]);

            // Get the original panel ID
            $originalPanelId = $pending['original_panel_id'] ?? null;
            if (!$originalPanelId) {
                $logger->error("Missing original panel ID in pending file", [
                    'pending_file' => basename($pendingFile)
                ]);
                continue;
            }

            // Update the original panel's state file
            $stateFile = $tempPath . "state_{$originalPanelId}.json";
            if (file_exists($stateFile)) {
                $state = json_decode(file_get_contents($stateFile), true);
                foreach ($state['cartoonification_requests'] ?? [] as &$request) {
                    if ($request['prediction_id'] === $predictionId) {
                        $request['status'] = $data['status'];
                        $request['completed_at'] = time();
                        if ($data['status'] === 'succeeded') {
                            $request['output'] = is_array($data['output']) ? $data['output'][0] : $data['output'];
                        }
                        break;
                    }
                }
                file_put_contents($stateFile, json_encode($state));
            }

            // Store the cartoonification result in the original panel's directory
            if ($data['status'] === 'succeeded' && !empty($data['output'])) {
                $cartoonificationResult = [
                    'id' => $predictionId,
                    'status' => 'succeeded',
                    'type' => 'cartoonification',
                    'output' => is_array($data['output']) ? $data['output'][0] : $data['output'],
                    'completed_at' => date('c'),
                    'original_panel_id' => $originalPanelId
                ];

                // Store result in original panel's directory
                file_put_contents(
                    $tempPath . "{$originalPanelId}_cartoonify_{$predictionId}.json",
                    json_encode($cartoonificationResult)
                );

                $logger->error("TEST_LOG - Stored cartoonification result", [
                    'original_panel_id' => $originalPanelId,
                    'prediction_id' => $predictionId,
                    'output_url' => $cartoonificationResult['output']
                ]);
            }

            // Clean up the pending file
            @unlink($pendingFile);
            break;
        }
    }

    // Check if this is a final panel generation completion
    $mappingFiles = glob($tempPath . "mapping_*.json");
    foreach ($mappingFiles as $mappingFile) {
        $mapping = json_decode(file_get_contents($mappingFile), true);
        if ($mapping && isset($mapping['panel_prediction_id']) && $mapping['panel_prediction_id'] === $predictionId) {
            $logger->error("TEST_LOG - Found matching panel prediction", [
                'mapping_file' => basename($mappingFile),
                'original_id' => $mapping['original_prediction_id'],
                'panel_id' => $predictionId
            ]);

            if ($data['status'] === 'succeeded' && !empty($data['output'])) {
                // First try to get original ID from pending files
                $originalPredictionId = null;
                $pendingFiles = glob($tempPath . "pending_*.json");
                foreach ($pendingFiles as $pendingFile) {
                    $pending = json_decode(file_get_contents($pendingFile), true);
                    if ($pending && isset($pending['original_prediction_id'])) {
                        $originalPredictionId = $pending['original_prediction_id'];
                        $logger->error("TEST_LOG - Found original ID in pending file", [
                            'pending_file' => basename($pendingFile),
                            'original_id' => $originalPredictionId
                        ]);
                        break;
                    }
                }

                // If not found in pending files, try mapping file
                if (!$originalPredictionId && isset($mapping['original_prediction_id'])) {
                    $originalPredictionId = $mapping['original_prediction_id'];
                    $logger->error("TEST_LOG - Using original ID from mapping file", [
                        'mapping_file' => basename($mappingFile),
                        'original_id' => $originalPredictionId
                    ]);
                }

                // If still not found, check state files
                if (!$originalPredictionId) {
                    $stateFiles = glob($tempPath . "state_*.json");
                    foreach ($stateFiles as $stateFile) {
                        $state = json_decode(file_get_contents($stateFile), true);
                        if ($state && isset($state['id'])) {
                            $originalPredictionId = $state['id'];
                            $logger->error("TEST_LOG - Found original ID in state file", [
                                'state_file' => basename($stateFile),
                                'original_id' => $originalPredictionId
                            ]);
                            break;
                        }
                    }
                }

                // If still not found, use panel ID
                if (!$originalPredictionId) {
                    $originalPredictionId = $predictionId;
                    $logger->error("TEST_LOG - Using panel ID as fallback", [
                        'panel_id' => $predictionId
                    ]);
                }

                // Write the final result to the original prediction file
                $originalResultFile = $tempPath . "{$originalPredictionId}.json";

                // Get cartoonified images from mapping
                $cartoonifiedImages = $mapping['cartoonified_images'] ?? [];

                $logger->error("TEST_LOG - Verifying cartoonified image usage", [
                    'panel_id' => $predictionId,
                    'original_id' => $originalPredictionId,
                    'cartoonified_images' => $cartoonifiedImages,
                    'has_output' => !empty($data['output']),
                    'output_type' => is_array($data['output']) ? 'array' : 'string',
                    'mapping_data' => $mapping
                ]);

                // Verify cartoonified images are valid URLs
                $validCartoonifiedImages = array_filter($cartoonifiedImages, function ($url) {
                    return filter_var($url, FILTER_VALIDATE_URL) !== false;
                });

                if (empty($validCartoonifiedImages) && !empty($cartoonifiedImages)) {
                    $logger->error("Invalid cartoonified image URLs found", [
                        'invalid_urls' => array_diff($cartoonifiedImages, $validCartoonifiedImages)
                    ]);
                }

                file_put_contents($originalResultFile, json_encode([
                    'id' => $originalPredictionId,
                    'status' => 'succeeded',
                    'type' => 'panel',
                    'output' => is_array($data['output']) ? $data['output'][0] : $data['output'],
                    'completed_at' => date('c'),
                    'cartoonified_images' => $validCartoonifiedImages,
                    'debug_info' => [
                        'used_cartoonified_images' => $validCartoonifiedImages,
                        'panel_id' => $predictionId,
                        'original_id' => $originalPredictionId,
                        'cartoonification_status' => [
                            'total_images' => count($cartoonifiedImages),
                            'valid_images' => count($validCartoonifiedImages),
                            'timestamp' => date('c')
                        ]
                    ]
                ]));

                $logger->info("Final panel result written to original prediction file", [
                    'original_file' => $originalResultFile,
                    'original_id' => $originalPredictionId,
                    'panel_output' => $data['output'],
                    'debug_info' => [
                        'used_cartoonified_images' => $validCartoonifiedImages
                    ]
                ]);

                // Clean up the mapping file
                @unlink($mappingFile);
                return;
            } elseif ($data['status'] === 'failed') {
                // First try to get original ID from pending files
                $originalPredictionId = null;
                $pendingFiles = glob($tempPath . "pending_*.json");
                foreach ($pendingFiles as $pendingFile) {
                    $pending = json_decode(file_get_contents($pendingFile), true);
                    if ($pending && isset($pending['original_prediction_id'])) {
                        $originalPredictionId = $pending['original_prediction_id'];
                        break;
                    }
                }

                // If not found in pending files, try mapping file
                if (!$originalPredictionId) {
                    $originalPredictionId = $mapping['original_prediction_id'];
                }

                // If still not found, use panel ID
                if (!$originalPredictionId) {
                    $originalPredictionId = $predictionId;
                }

                $originalResultFile = $tempPath . "{$originalPredictionId}.json";

                file_put_contents($originalResultFile, json_encode([
                    'id' => $originalPredictionId,
                    'status' => 'failed',
                    'error' => $data['error'] ?? 'Panel generation failed',
                    'type' => 'panel',
                    'completed_at' => date('c')
                ]));

                $logger->error("Panel generation failed", [
                    'error' => $data['error'] ?? 'Unknown error',
                    'original_id' => $originalPredictionId,
                    'panel_id' => $predictionId
                ]);

                // Clean up the mapping file
                @unlink($mappingFile);
                return;
            }
            break;
        }
    }

    // Handle the webhook payload
    if ($data['status'] === 'succeeded') {
        // Get the pending file
        $pendingFile = $tempPath . "pending_{$data['id']}.json";
        if (file_exists($pendingFile)) {
            $pending = json_decode(file_get_contents($pendingFile), true);

            // Check which stage we're in
            if ($pending['stage'] === 'cartoonify' && $pending['next_stage'] === 'sdxl') {
                // Log the transition
                $logger->error("TEST_LOG - Transitioning from cartoonify to SDXL", [
                    'prediction_id' => $data['id'],
                    'original_prediction_id' => $pending['original_prediction_id'],
                    'pending_data' => $pending
                ]);

                // Cartoonification completed, now start SDXL
                $cartoonifiedUrl = is_array($data['output']) ? $data['output'][0] : $data['output'];

                // Get the stored SDXL parameters
                $sdxlParams = $pending['sdxl_params'];
                $sdxlParams['model_params']['image'] = $cartoonifiedUrl;

                // Start SDXL processing
                require_once __DIR__ . '/models/ReplicateClient.php';
                $replicateClient = new ReplicateClient($logger);

                $result = $replicateClient->generateImage([
                    'cartoonified_image' => $cartoonifiedUrl,
                    'prompt' => $sdxlParams['prompt'],
                    'options' => ['style' => $sdxlParams['style']],
                    'original_prediction_id' => $pending['original_prediction_id']
                ]);

                // Create mapping file for SDXL stage
                $mappingFile = $tempPath . "mapping_{$result['id']}.json";
                file_put_contents($mappingFile, json_encode([
                    'original_prediction_id' => $pending['original_prediction_id'],
                    'panel_prediction_id' => $result['id'],
                    'cartoonified_images' => [$cartoonifiedUrl],
                    'created_at' => date('c')
                ]));

                // Create new pending file for SDXL stage
                $newPendingFile = $tempPath . "pending_{$result['id']}.json";
                file_put_contents($newPendingFile, json_encode([
                    'prediction_id' => $result['id'],
                    'stage' => 'sdxl',
                    'original_prediction_id' => $pending['original_prediction_id'],
                    'cartoonified_url' => $cartoonifiedUrl,
                    'started_at' => time(),
                    'debug_info' => [
                        'previous_stage' => 'cartoonify',
                        'previous_id' => $data['id'],
                        'cartoonified_url' => $cartoonifiedUrl
                    ]
                ]));

                // Log the SDXL stage initialization
                $logger->error("TEST_LOG - Initialized SDXL stage", [
                    'sdxl_prediction_id' => $result['id'],
                    'original_prediction_id' => $pending['original_prediction_id'],
                    'cartoonified_url' => $cartoonifiedUrl,
                    'mapping_file' => basename($mappingFile),
                    'pending_file' => basename($newPendingFile)
                ]);

                // Clean up old pending file
                @unlink($pendingFile);
                return;
            } else if ($pending['stage'] === 'sdxl') {
                // SDXL completed, this is our final result
                $finalResult = [
                    'id' => $pending['original_prediction_id'],
                    'status' => 'succeeded',
                    'output' => is_array($data['output']) ? $data['output'][0] : $data['output'],
                    'completed_at' => date('c'),
                    'cartoonified_url' => $pending['cartoonified_url']
                ];

                // Write final result
                $resultFile = $tempPath . "{$pending['original_prediction_id']}.json";
                file_put_contents($resultFile, json_encode($finalResult));

                // Log the completion
                $logger->error("TEST_LOG - SDXL stage completed", [
                    'prediction_id' => $data['id'],
                    'original_prediction_id' => $pending['original_prediction_id'],
                    'result_file' => basename($resultFile)
                ]);

                // Clean up pending file
                @unlink($pendingFile);
                return;
            }
        }
    }

    // If we get here, this is an unknown prediction - store it as-is
    $writeResult = file_put_contents($tempFile, $payload);
    if ($writeResult === false) {
        $error = error_get_last();
        $logger->error("Failed to write prediction result", [
            'file' => $tempFile,
            'error' => $error['message'] ?? 'Unknown error',
            'php_error' => error_get_last()
        ]);
        throw new Exception("Failed to write prediction result: " . ($error['message'] ?? 'Unknown error'));
    }

    $logger->info("Stored unknown prediction result", [
        'file' => $tempFile,
        'prediction_id' => $predictionId,
        'bytes_written' => $writeResult
    ]);

    // Return success response
    http_response_code(200);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    $logger->error("Webhook processing failed", [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
