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

            if ($currentStage === 'cartoonify' && $data['status'] === 'succeeded') {
                $logger->error("TEST_LOG - Cartoonification succeeded, preparing SDXL", [
                    'original_panel_id' => $originalPanelId,
                    'cartoonified_url' => is_array($data['output']) ? $data['output'][0] : $data['output']
                ]);

                // Store the cartoonification result
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

                // Trigger SDXL
                require_once __DIR__ . '/models/ReplicateClient.php';
                $replicateClient = new ReplicateClient($logger);

                try {
                    // Parse panel data
                    $panelData = json_decode($pending['panel_data'], true);
                    if (!$panelData) {
                        throw new Exception("Invalid panel data format");
                    }

                    $logger->error("TEST_LOG - Starting SDXL generation", [
                        'original_panel_id' => $originalPanelId,
                        'scene_description' => $panelData['scene_description'],
                        'cartoonified_url' => $cartoonificationResult['output']
                    ]);

                    // Start SDXL generation
                    $sdxlResult = $replicateClient->generateImage([
                        'cartoonified_image' => $cartoonificationResult['output'],
                        'prompt' => $panelData['scene_description'],
                        'original_panel_id' => $originalPanelId,
                        'options' => [
                            'style' => $panelData['characters'][0]['options']['style'] ?? 'modern'
                        ]
                    ]);

                    // Create new pending file for SDXL
                    $sdxlPendingFile = $tempPath . "pending_{$sdxlResult['id']}.json";
                    file_put_contents($sdxlPendingFile, json_encode([
                        'prediction_id' => $sdxlResult['id'],
                        'original_panel_id' => $originalPanelId,
                        'stage' => 'sdxl',
                        'cartoonified_url' => $cartoonificationResult['output'],
                        'started_at' => time(),
                        'panel_data' => $pending['panel_data'] // Preserve original panel data
                    ]));

                    // Update state file with SDXL status
                    if (file_exists($stateFile)) {
                        $state = json_decode(file_get_contents($stateFile), true);
                        $state['sdxl_status'] = 'processing';
                        $state['sdxl_prediction_id'] = $sdxlResult['id'];
                        $state['sdxl_started_at'] = time();
                        file_put_contents($stateFile, json_encode($state));
                    }

                    $logger->error("TEST_LOG - SDXL generation initiated", [
                        'sdxl_prediction_id' => $sdxlResult['id'],
                        'original_panel_id' => $originalPanelId
                    ]);
                } catch (Exception $e) {
                    $logger->error("Failed to start SDXL", [
                        'error' => $e->getMessage(),
                        'original_panel_id' => $originalPanelId
                    ]);
                }
            } elseif ($currentStage === 'sdxl') {
                $logger->error("TEST_LOG - Processing SDXL completion", [
                    'prediction_id' => $predictionId,
                    'original_panel_id' => $originalPanelId,
                    'status' => $data['status']
                ]);

                if ($data['status'] === 'succeeded') {
                    // Update state file with SDXL completion
                    if (file_exists($stateFile)) {
                        $state = json_decode(file_get_contents($stateFile), true);
                        $state['sdxl_status'] = 'succeeded';
                        $state['sdxl_output'] = is_array($data['output']) ? $data['output'][0] : $data['output'];
                        $state['sdxl_completed_at'] = time();
                        file_put_contents($stateFile, json_encode($state));
                    }

                    // Write final result to original panel file
                    $finalResult = [
                        'id' => $originalPanelId,
                        'status' => 'succeeded',
                        'output' => is_array($data['output']) ? $data['output'][0] : $data['output'],
                        'completed_at' => date('c')
                    ];
                    file_put_contents($tempPath . "{$originalPanelId}.json", json_encode($finalResult));

                    $logger->error("TEST_LOG - SDXL completed successfully", [
                        'original_panel_id' => $originalPanelId,
                        'output_url' => $finalResult['output']
                    ]);
                }
            }

            // Clean up the pending file
            @unlink($pendingFile);
            break;
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

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
