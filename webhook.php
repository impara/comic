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

    if (!$data) {
        throw new Exception("Invalid JSON payload received");
    }

    // Check if we're in testing mode (no webhook secret configured)
    $webhookSecret = $config->get('replicate.webhook_secret');
    $isTestingMode = empty($webhookSecret);

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
    file_put_contents($tempFile, $payload);

    $logger->info("Stored prediction result", [
        'file' => $tempFile,
        'prediction_id' => $predictionId
    ]);

    // Check if this is a cartoonification completion
    $pendingFiles = glob($tempPath . "pending_*.json");
    foreach ($pendingFiles as $pendingFile) {
        $pending = json_decode(file_get_contents($pendingFile), true);
        if ($pending && isset($pending['prediction_id']) && $pending['prediction_id'] === $predictionId) {
            // Update the original image with the cartoonified version
            if ($data['status'] === 'succeeded' && !empty($data['output'])) {
                $logger->info("Updating cartoonified image", [
                    'original' => $pending['original_image'],
                    'cartoonified' => $data['output']
                ]);

                // Store the cartoonified URL for the original image
                $cartoonifiedFile = $tempPath . "cartoonified_" . basename($pending['original_image']) . ".json";
                file_put_contents($cartoonifiedFile, json_encode([
                    'original_url' => $pending['original_image'],
                    'cartoonified_url' => $data['output'],
                    'completed_at' => time()
                ]));

                // Clean up the pending file
                unlink($pendingFile);
                $logger->info("Cartoonification process completed successfully");
            } elseif ($data['status'] === 'failed') {
                $logger->error("Cartoonification failed", [
                    'error' => $data['error'] ?? 'Unknown error',
                    'original_image' => $pending['original_image']
                ]);
                unlink($pendingFile); // Clean up pending file even on failure
            }
            break;
        }
    }

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'message' => 'Webhook processed successfully'
    ]);
} catch (Exception $e) {
    $logger->error("Webhook processing failed", [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
