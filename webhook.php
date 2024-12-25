<?php

require_once __DIR__ . '/bootstrap.php';

// Initialize dependencies
$logger = new Logger();
$config = Config::getInstance();
$stateManager = new StateManager(__DIR__ . '/public/temp', $logger);
$imageComposer = new ImageComposer($logger, $config);
$characterProcessor = new CharacterProcessor($logger, $config);
$storyParser = new StoryParser($logger);

$comicGenerator = new ComicGenerator(
    $stateManager,
    $logger,
    $config,
    $imageComposer,
    $characterProcessor,
    $storyParser
);

// Initialize orchestrator
$orchestrator = new Orchestrator(
    $logger,
    $comicGenerator,
    $characterProcessor,
    $storyParser,
    __DIR__ . '/public/temp'
);

// Get raw POST data
$rawData = file_get_contents('php://input');
if (!$rawData) {
    http_response_code(400);
    echo json_encode(['error' => 'No payload received']);
    exit;
}

// Check if we're in development mode
$isDev = $config->getEnvironment() === 'development';
$logger->debug('Webhook environment check', [
    'is_development' => $isDev,
    'environment' => $config->getEnvironment()
]);

// Verify webhook signature only in non-development environments
if (!$isDev) {
    // Get all headers
    $headers = getallheaders();
    $logger->debug('Webhook headers received', [
        'headers' => $headers
    ]);

    // Headers are case-insensitive, convert to lowercase for comparison
    $headers = array_change_key_case($headers, CASE_LOWER);
    
    // Check if this is an internal request
    $isInternalRequest = ($headers['user-agent'] ?? '') === 'ComicGenerator/1.0';
    
    if (!$isInternalRequest) {
        $signature = $headers['webhook-signature'] ?? '';
        $timestamp = $headers['webhook-timestamp'] ?? '';
        $secret = $config->get('replicate.webhook_secret');

        if (!$secret) {
            $logger->error('Webhook secret not configured');
            http_response_code(500);
            echo json_encode(['error' => 'Webhook secret not configured']);
            exit;
        }

        // Extract the signature value from the v1,<signature> format
        if (preg_match('/^v1,(.+)$/', $signature, $matches)) {
            $signature = $matches[1];
        }

        // Calculate expected signature
        $computedSignature = hash_hmac('sha256', $timestamp . '.' . $rawData, $secret);

        // Convert computed signature to base64 to match Replicate's format
        $computedSignature = base64_encode(hex2bin($computedSignature));

        // Verify signature
        if (!hash_equals($signature, $computedSignature)) {
            $logger->error('Invalid webhook signature', [
                'received' => $signature,
                'computed' => $computedSignature,
                'environment' => $config->getEnvironment(),
                'timestamp' => $timestamp,
                'raw_headers' => $headers,
                'data_length' => strlen($rawData)
            ]);
            http_response_code(401);
            echo json_encode(['error' => 'Invalid webhook signature']);
            exit;
        }
    } else {
        $logger->info('Skipping signature validation for internal request');
    }
} else {
    $logger->info('Skipping webhook signature validation in development mode');
}

// Parse JSON payload
$payload = json_decode($rawData, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON payload']);
    exit;
}

try {
    // Process webhook through orchestrator
    $result = $orchestrator->onWebhookReceived($payload);

    // Return success response
    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'message' => 'Webhook processed successfully',
        'data' => $result
    ]);
} catch (Exception $e) {
    // Log error and return error response
    $logger->error('Webhook processing failed', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);

    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Failed to process webhook: ' . $e->getMessage()
    ]);
}
