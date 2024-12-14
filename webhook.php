<?php

require_once __DIR__ . '/models/Config.php';
require_once __DIR__ . '/models/Logger.php';

// Initialize logger and config
$logger = new Logger();
$config = Config::getInstance();

try {
    // Get the raw POST data and headers
    $rawData = file_get_contents('php://input');
    $signature = $_SERVER['HTTP_REPLICATE_WEBHOOK_SIGNATURE'] ?? null;
    $timestamp = $_SERVER['HTTP_REPLICATE_WEBHOOK_TIMESTAMP'] ?? time();

    // Get webhook secret from config
    $webhookSecret = $config->get('replicate.webhook_secret');
    if (!$webhookSecret) {
        // For testing: Skip webhook secret verification
        $logger->warning('Webhook secret not configured - TESTING MODE');
        // In testing mode, don't require signature
        $signature = $signature ?? 'test';
    } else {
        // Verify signature
        $signedContent = $timestamp . '.' . $rawData;
        $expectedSignature = hash_hmac('sha256', $signedContent, $webhookSecret);

        if (!hash_equals($expectedSignature, $signature)) {
            throw new Exception('Invalid webhook signature');
        }
    }

    $data = json_decode($rawData, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON payload');
    }

    $logger->info('Received webhook from Replicate', [
        'data' => $data
    ]);

    // Verify this is a completed prediction
    if ($data['status'] === 'succeeded') {
        // Store the result in a temporary file
        $resultFile = __DIR__ . '/public/temp/' . $data['id'] . '.json';
        file_put_contents($resultFile, $rawData);

        $logger->info('Stored prediction result', [
            'file' => $resultFile,
            'prediction_id' => $data['id']
        ]);

        // Return success response
        http_response_code(200);
        echo json_encode(['status' => 'success']);
    } else {
        $logger->warning('Received non-completed prediction', [
            'status' => $data['status']
        ]);
        http_response_code(202); // Accepted but not processed
    }
} catch (Exception $e) {
    $logger->error('Failed to process webhook', [
        'error' => $e->getMessage()
    ]);
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
