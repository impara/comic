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
    $tempFile = $tempPath . "{$predictionId}.json";

    // Log temp directory details
    $logger->info("Temp directory details", [
        'path' => $tempPath,
        'exists' => file_exists($tempPath),
        'writable' => is_writable($tempPath),
        'owner' => function_exists('posix_getpwuid') ? posix_getpwuid(fileowner($tempPath))['name'] : 'unknown',
        'group' => function_exists('posix_getgrgid') ? posix_getgrgid(filegroup($tempPath))['name'] : 'unknown',
        'permissions' => substr(sprintf('%o', fileperms($tempPath)), -4)
    ]);

    // Check if this is a cartoonification completion
    $pendingFiles = glob($tempPath . "pending_*.json");
    $logger->error("TEST_LOG - Searching pending files", [
        'found_files' => count($pendingFiles),
        'prediction_id' => $predictionId,
        'pending_files' => array_map(function ($f) {
            return basename($f);
        }, $pendingFiles)
    ]);

    foreach ($pendingFiles as $pendingFile) {
        $pending = json_decode(file_get_contents($pendingFile), true);
        $logger->error("TEST_LOG - Checking pending file", [
            'file' => basename($pendingFile),
            'has_prediction_id' => isset($pending['prediction_id']),
            'pending_prediction_id' => $pending['prediction_id'] ?? 'none',
            'matches_current' => ($pending['prediction_id'] ?? '') === $predictionId,
            'has_panel_data' => isset($pending['panel_data']),
            'raw_pending' => $pending
        ]);
        if ($pending && isset($pending['prediction_id']) && $pending['prediction_id'] === $predictionId) {
            $logger->error("TEST_LOG - Found matching cartoonification", [
                'pending_file' => $pendingFile,
                'prediction_id' => $predictionId,
                'has_panel_data' => isset($pending['panel_data'])
            ]);

            // Store the result directly
            if ($data['status'] === 'succeeded' && !empty($data['output'])) {
                $logger->error("TEST_LOG - Cartoonification succeeded", [
                    'output_url' => is_array($data['output']) ? $data['output'][0] : $data['output']
                ]);

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
                    // Assuming we have only one character or know the correct index:
                    $panelData['characters'][0]['cartoonified_image'] = is_array($data['output']) ? $data['output'][0] : $data['output'];

                    // Log that we're updating the character with the cartoonified image
                    $logger->error("TEST_LOG - Setting cartoonified_image for character", [
                        'character_id' => $panelData['characters'][0]['id'],
                        'cartoonified_url' => $panelData['characters'][0]['cartoonified_image']
                    ]);

                    // Now call generatePanel() with updated panelData
                    require_once __DIR__ . '/models/ComicGenerator.php';
                    $comicGenerator = new ComicGenerator($logger);

                    $logger->error("TEST_LOG - About to call generatePanel after setting cartoonified_image", [
                        'character_count' => count($panelData['characters']),
                        'first_character' => isset($panelData['characters'][0]) ? [
                            'id' => $panelData['characters'][0]['id'] ?? 'unknown',
                            'has_cartoonified' => isset($panelData['characters'][0]['cartoonified_image'])
                        ] : null
                    ]);

                    $panelResult = $comicGenerator->generatePanel(
                        $panelData['characters'],
                        $panelData['scene_description'],
                        $predictionId
                    );

                    $logger->error("TEST_LOG - Panel generation completed", [
                        'result' => [
                            'status' => $panelResult['status'] ?? 'unknown',
                            'has_output' => isset($panelResult['output']),
                            'prediction_id' => $predictionId
                        ]
                    ]);

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
                'mapping_file' => $mappingFile,
                'original_id' => $mapping['original_prediction_id'],
                'panel_id' => $predictionId
            ]);

            if ($data['status'] === 'succeeded' && !empty($data['output'])) {
                // Write the final result to the original prediction file
                $originalResultFile = $tempPath . "{$mapping['original_prediction_id']}.json";
                file_put_contents($originalResultFile, json_encode([
                    'id' => $mapping['original_prediction_id'],
                    'status' => 'succeeded',
                    'output' => is_array($data['output']) ? $data['output'][0] : $data['output'],
                    'type' => 'panel',
                    'completed_at' => date('c')
                ]));

                $logger->info("Final panel result written to original prediction file", [
                    'original_file' => $originalResultFile,
                    'panel_output' => $data['output']
                ]);

                // Clean up the mapping file
                @unlink($mappingFile);
                return;
            } elseif ($data['status'] === 'failed') {
                // If panel generation failed, update the original prediction with the error
                $originalResultFile = $tempPath . "{$mapping['original_prediction_id']}.json";
                file_put_contents($originalResultFile, json_encode([
                    'id' => $mapping['original_prediction_id'],
                    'status' => 'failed',
                    'error' => $data['error'] ?? 'Panel generation failed',
                    'type' => 'panel',
                    'completed_at' => date('c')
                ]));

                $logger->error("Panel generation failed", [
                    'error' => $data['error'] ?? 'Unknown error',
                    'original_id' => $mapping['original_prediction_id']
                ]);

                // Clean up the mapping file
                @unlink($mappingFile);
                return;
            }
            break;
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
