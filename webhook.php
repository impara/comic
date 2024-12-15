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

    $logger->info("Webhook received", [
        'payload_length' => strlen($payload),
        'data' => $data,
        'request_headers' => getallheaders()
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
    foreach ($pendingFiles as $pendingFile) {
        $pending = json_decode(file_get_contents($pendingFile), true);
        if ($pending && isset($pending['prediction_id']) && $pending['prediction_id'] === $predictionId) {
            $logger->info("Found matching pending cartoonification", [
                'pending_file' => $pendingFile,
                'prediction_id' => $predictionId
            ]);

            // Store the result directly
            if ($data['status'] === 'succeeded' && !empty($data['output'])) {
                $logger->info("Cartoonification completed successfully", [
                    'original_image' => $pending['original_image'],
                    'cartoonified_url' => is_array($data['output']) ? $data['output'][0] : $data['output']
                ]);

                // Store cartoonification result in a separate file
                $cartoonifiedFile = $tempPath . "cartoonified_{$predictionId}.json";
                file_put_contents($cartoonifiedFile, json_encode([
                    'original_image' => $pending['original_image'],
                    'cartoonified_url' => is_array($data['output']) ? $data['output'][0] : $data['output'],
                    'completed_at' => time()
                ]));

                // Update the prediction result file with the cartoonified URL
                $resultFile = $tempPath . "{$predictionId}.json";
                if (file_exists($resultFile)) {
                    $result = json_decode(file_get_contents($resultFile), true);
                    $result['output'] = is_array($data['output']) ? $data['output'][0] : $data['output'];
                    file_put_contents($resultFile, json_encode($result));
                }

                // Clean up the pending file
                @unlink($pendingFile);

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
                    $panelData['characters'][0]['cartoonified_image'] = is_array($data['output']) ? $data['output'][0] : $data['output'];

                    // Create a new ComicGenerator instance
                    require_once __DIR__ . '/models/ComicGenerator.php';
                    $comicGenerator = new ComicGenerator($logger);

                    // Generate the panel
                    $panelResult = $comicGenerator->generatePanel(
                        $panelData['characters'],
                        $panelData['scene_description']
                    );

                    // Store the panel result
                    file_put_contents($resultFile, json_encode($panelResult));
                }
            } elseif ($data['status'] === 'failed') {
                $logger->error("Cartoonification failed", [
                    'error' => $data['error'] ?? 'Unknown error',
                    'original_image' => $pending['original_image']
                ]);
                @unlink($pendingFile);
            }
            break;
        }
    }

    // Store the prediction result for polling
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

    $logger->info("Stored prediction result", [
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
