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

                $logger->error("TEST_LOG - Updated panel with SDXL output", [
                    'panel_id' => basename($panelFile, '.json'),
                    'output_url' => $panel['output']
                ]);
            } else if ($data['status'] === 'failed') {
                $panel['status'] = 'failed';
                $panel['error'] = $data['error'] ?? 'SDXL generation failed';
                file_put_contents($panelFile, json_encode($panel));

                $logger->error("TEST_LOG - SDXL generation failed", [
                    'panel_id' => basename($panelFile, '.json'),
                    'error' => $panel['error']
                ]);
            }
            return;
        }
    }

    // If not SDXL, check for cartoonification completion
    $pendingFiles = glob($tempPath . "pending_*.json");

    // Clean up stale pending files (older than 1 hour)
    foreach ($pendingFiles as $pendingFile) {
        $pending = json_decode(file_get_contents($pendingFile), true);
        if ($pending && isset($pending['started_at'])) {
            $age = time() - $pending['started_at'];
            if ($age > 3600) { // 1 hour
                $logger->error("TEST_LOG - Removing stale pending file", [
                    'file' => basename($pendingFile),
                    'age_seconds' => $age,
                    'started_at' => $pending['started_at']
                ]);
                @unlink($pendingFile);
                continue;
            }
        }
    }

    // Refresh pending files list after cleanup
    $pendingFiles = glob($tempPath . "pending_*.json");
    $logger->error("TEST_LOG - Searching pending files", [
        'found_files' => count($pendingFiles),
        'prediction_id' => $predictionId,
        'pending_files' => array_map(function ($f) {
            return basename($f);
        }, $pendingFiles),
        'webhook_status' => $data['status'],
        'has_output' => !empty($data['output']),
        'output_type' => !empty($data['output']) ? (is_array($data['output']) ? 'array' : 'string') : 'none'
    ]);

    foreach ($pendingFiles as $pendingFile) {
        $pending = json_decode(file_get_contents($pendingFile), true);
        $logger->error("TEST_LOG - Checking pending file", [
            'file' => basename($pendingFile),
            'has_prediction_id' => isset($pending['prediction_id']),
            'pending_prediction_id' => $pending['prediction_id'] ?? 'none',
            'matches_current' => ($pending['prediction_id'] ?? '') === $predictionId,
            'has_panel_data' => isset($pending['panel_data']),
            'has_state_file' => isset($pending['state_file']),
            'raw_pending' => $pending
        ]);

        if ($pending && isset($pending['prediction_id']) && $pending['prediction_id'] === $predictionId) {
            $logger->error("TEST_LOG - Found matching cartoonification", [
                'pending_file' => basename($pendingFile),
                'prediction_id' => $predictionId,
                'has_panel_data' => isset($pending['panel_data']),
                'state_file' => $pending['state_file'] ?? null
            ]);

            // Update state file if it exists
            if (isset($pending['state_file'])) {
                $stateFile = $tempPath . $pending['state_file'];
                if (file_exists($stateFile)) {
                    $state = json_decode(file_get_contents($stateFile), true) ?? [];
                    foreach ($state['cartoonification_requests'] ?? [] as &$request) {
                        if ($request['prediction_id'] === $predictionId) {
                            $request['status'] = $data['status'];
                            $request['completed_at'] = time();
                            if ($data['status'] === 'succeeded') {
                                $request['output'] = is_array($data['output']) ? $data['output'][0] : $data['output'];
                            } elseif ($data['status'] === 'failed') {
                                $request['error'] = $data['error'] ?? 'Unknown error';
                            }
                            break;
                        }
                    }
                    file_put_contents($stateFile, json_encode($state));
                }
            }

            // Store the result directly
            if ($data['status'] === 'succeeded' && !empty($data['output'])) {
                $logger->error("TEST_LOG - Cartoonification succeeded", [
                    'output_url' => is_array($data['output']) ? $data['output'][0] : $data['output'],
                    'has_panel_data' => isset($pending['panel_data']),
                    'panel_data_type' => isset($pending['panel_data']) ? gettype($pending['panel_data']) : 'none'
                ]);

                // Store cartoonification result
                $cartoonificationResult = [
                    'id' => $predictionId,
                    'status' => 'succeeded',
                    'type' => 'cartoonification',
                    'output' => is_array($data['output']) ? $data['output'][0] : $data['output'],
                    'completed_at' => date('c'),
                    'original_prediction_id' => $pending['original_prediction_id'] ?? null,
                    'debug_info' => [
                        'pending_file' => basename($pendingFile),
                        'has_panel_data' => isset($pending['panel_data']),
                        'original_prediction_id' => $pending['original_prediction_id'] ?? null
                    ]
                ];

                $logger->error("TEST_LOG - Writing cartoonification result", [
                    'prediction_id' => $predictionId,
                    'original_prediction_id' => $pending['original_prediction_id'] ?? null,
                    'has_panel_data' => isset($pending['panel_data']),
                    'output_url' => is_array($data['output']) ? $data['output'][0] : $data['output']
                ]);

                file_put_contents($tempPath . "{$predictionId}.json", json_encode($cartoonificationResult));

                // If this is a cartoonification completion, trigger panel generation
                if (isset($pending['panel_data'])) {
                    $panelData = json_decode($pending['panel_data'], true);
                    if ($panelData === null) {
                        $logger->error("Failed to parse panel_data JSON", [
                            'panel_data' => $pending['panel_data'],
                            'json_error' => json_last_error_msg()
                        ]);
                        throw new Exception("Invalid panel_data format");
                    }

                    // Update cartoonified_image for the character(s)
                    $cartoonifiedUrl = is_array($data['output']) ? $data['output'][0] : $data['output'];

                    // Log the original panel data before update
                    $logger->error("TEST_LOG - Panel data before cartoonified update", [
                        'character_id' => $panelData['characters'][0]['id'],
                        'has_cartoonified' => isset($panelData['characters'][0]['cartoonified_image']),
                        'original_image' => $panelData['characters'][0]['image'],
                        'scene_description' => $panelData['scene_description']
                    ]);

                    // Update character's cartoonified image
                    $panelData['characters'][0]['cartoonified_image'] = $cartoonifiedUrl;

                    // Store cartoonification mapping for verification
                    $cartoonificationMappingFile = $tempPath . "cartoonification_{$predictionId}.json";
                    file_put_contents($cartoonificationMappingFile, json_encode([
                        'prediction_id' => $predictionId,
                        'character_id' => $panelData['characters'][0]['id'],
                        'cartoonified_url' => $cartoonifiedUrl,
                        'original_prediction_id' => $pending['original_prediction_id'],
                        'created_at' => date('c')
                    ]));

                    // Update state file with cartoonified image
                    if (isset($pending['state_file'])) {
                        $stateFile = $tempPath . $pending['state_file'];
                        if (file_exists($stateFile)) {
                            $state = json_decode(file_get_contents($stateFile), true) ?? [];
                            foreach ($state['cartoonification_requests'] ?? [] as &$request) {
                                if ($request['prediction_id'] === $predictionId) {
                                    $request['status'] = 'succeeded';
                                    $request['completed_at'] = time();
                                    $request['cartoonified_url'] = $cartoonifiedUrl;
                                    break;
                                }
                            }
                            file_put_contents($stateFile, json_encode($state));
                        }
                    }

                    // Log the updated panel data before generating panel
                    $logger->error("TEST_LOG - Updated panel data with cartoonified image", [
                        'character_id' => $panelData['characters'][0]['id'],
                        'cartoonified_url' => $cartoonifiedUrl,
                        'scene_description' => $panelData['scene_description'],
                        'full_panel_data' => $panelData,
                        'original_pending_file' => basename($pendingFile),
                        'cartoonification_mapping' => basename($cartoonificationMappingFile),
                        'state_file' => $pending['state_file'] ?? null
                    ]);

                    // Now call generatePanel() with updated panelData
                    require_once __DIR__ . '/models/ComicGenerator.php';
                    require_once __DIR__ . '/models/ReplicateClient.php';

                    $comicGenerator = new ComicGenerator($logger);
                    $replicateClient = new ReplicateClient($logger);

                    // First generate panel ID and state
                    $panelResult = $comicGenerator->generatePanel(
                        $panelData['characters'],
                        $panelData['scene_description'],
                        $pending['original_prediction_id']
                    );

                    $logger->error("TEST_LOG - Panel generation completed after cartoonification", [
                        'result' => $panelResult,
                        'panel_id' => $panelResult['id']
                    ]);

                    // Store panel info in original prediction file for frontend access
                    $originalPredictionFile = $tempPath . $pending['original_prediction_id'] . '.json';
                    $originalPrediction = [];
                    if (file_exists($originalPredictionFile)) {
                        $originalPrediction = json_decode(file_get_contents($originalPredictionFile), true) ?? [];
                    }
                    $originalPrediction['panel_id'] = $panelResult['id'];
                    $originalPrediction['status'] = 'processing';
                    file_put_contents($originalPredictionFile, json_encode($originalPrediction));

                    // Now trigger SDXL with cartoonified image
                    try {
                        // Prepare SDXL parameters
                        $sdxlParams = [
                            'cartoonified_image' => $cartoonifiedUrl,
                            'prompt' => $panelData['scene_description'],
                            'options' => [
                                'style' => $panelData['characters'][0]['options']['style'] ?? 'modern'
                            ],
                            'original_prediction_id' => $panelResult['id']
                        ];

                        // Call SDXL generation
                        $sdxlResult = $replicateClient->generateImage($sdxlParams);

                        // Update panel file with SDXL prediction ID
                        $panelFile = $tempPath . $panelResult['id'] . '.json';
                        $currentPanel = [
                            'id' => $panelResult['id'],
                            'status' => 'processing',
                            'sdxl_prediction_id' => $sdxlResult['id'],
                            'cartoonified_url' => $cartoonifiedUrl,
                            'original_prediction_id' => $pending['original_prediction_id'],
                            'created_at' => date('c')
                        ];
                        file_put_contents($panelFile, json_encode($currentPanel));

                        $logger->error("TEST_LOG - SDXL generation initiated", [
                            'panel_id' => $panelResult['id'],
                            'sdxl_prediction_id' => $sdxlResult['id'],
                            'cartoonified_url' => $cartoonifiedUrl
                        ]);
                    } catch (Exception $e) {
                        $logger->error("Failed to initiate SDXL generation", [
                            'error' => $e->getMessage(),
                            'panel_id' => $panelResult['id']
                        ]);
                        throw $e;
                    }

                    // Store the mapping between cartoonification and panel
                    if (isset($panelResult['id'])) {
                        $mappingFile = $tempPath . "mapping_{$panelResult['id']}.json";
                        file_put_contents($mappingFile, json_encode([
                            'original_prediction_id' => $pending['original_prediction_id'],
                            'panel_prediction_id' => $panelResult['id'],
                            'cartoonified_images' => [$cartoonifiedUrl],
                            'created_at' => date('c')
                        ]));
                    }

                    // Clean up the pending file since we're done with cartoonification
                    @unlink($pendingFile);
                    return;
                }
            } elseif ($data['status'] === 'failed') {
                $logger->error("TEST_LOG - Cartoonification failed", [
                    'error' => $data['error'] ?? 'Unknown error',
                    'prediction_id' => $predictionId
                ]);
                @unlink($pendingFile);
            }
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
