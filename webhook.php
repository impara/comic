<?php

require_once __DIR__ . '/bootstrap.php';

// Initialize dependencies
$logger = new Logger();
$config = Config::getInstance();
$stateManager = new StateManager(__DIR__ . '/public/temp', $logger);
$imageComposer = new ImageComposer($logger, $config);
$characterProcessor = new CharacterProcessor($logger, $config, $stateManager);
$storyParser = new StoryParser($logger, $config, $stateManager);
$comicGenerator = new ComicGenerator($stateManager, $logger, $config, $imageComposer, $characterProcessor, $storyParser);

// Initialize orchestrator
$orchestrator = new Orchestrator(
    $logger,
    $comicGenerator,
    $characterProcessor,
    $storyParser,
    __DIR__ . '/public/temp'
);

try {
    // Get raw POST data
    $rawData = file_get_contents('php://input');
    if (!$rawData) {
        throw new Exception('No payload received');
    }

    $payload = json_decode($rawData, true);
    if (!$payload) {
        throw new Exception('Invalid JSON payload');
    }

    // Check if we're in development mode
    $isDev = $config->getEnvironment() === 'development';
    $logger->debug('Webhook environment', [
        'environment' => $config->getEnvironment()
    ]);

    // Verify webhook signature only in non-development environments
    if (!$isDev) {
        // Get all headers
        $headers = getallheaders();
        
        // Headers are case-insensitive, convert to lowercase for comparison
        $headers = array_change_key_case($headers, CASE_LOWER);
        
        // Check if this is an internal request
        $isInternalRequest = ($headers['user-agent'] ?? '') === 'ComicGenerator/1.0';
        
        if (!$isInternalRequest) {
            $signature = $headers['webhook-signature'] ?? '';
            $timestamp = $headers['webhook-timestamp'] ?? '';
            $webhookId = $headers['webhook-id'] ?? '';
            $secret = $config->get('replicate.webhook_secret');

            if (!$secret) {
                throw new Exception('Webhook secret not configured');
            }

            // Get the base64 portion of the secret (after whsec_)
            if (!preg_match('/^whsec_(.+)$/', $secret, $matches)) {
                throw new Exception('Invalid webhook secret format');
            }
            $secretBase64 = $matches[1];
            
            // Base64 decode the secret to get the key bytes
            $secretKey = base64_decode($secretBase64);

            // Construct the signed content as per docs
            $signedContent = $webhookId . '.' . $timestamp . '.' . $rawData;

            // Calculate our signature
            $computedSignature = base64_encode(
                hash_hmac('sha256', $signedContent, $secretKey, true)
            );

            // Get all provided signatures
            $providedSignatures = array_map(
                function($sig) {
                    return preg_replace('/^v\d+,/', '', $sig);
                },
                explode(' ', $signature)
            );

            // Check if our computed signature matches any of the provided ones
            $validSignature = false;
            foreach ($providedSignatures as $providedSignature) {
                if (hash_equals($providedSignature, $computedSignature)) {
                    $validSignature = true;
                    break;
                }
            }

            if (!$validSignature) {
                $logger->error('Invalid webhook signature', [
                    'webhook_id' => $webhookId,
                    'timestamp' => $timestamp,
                    'received_count' => count($providedSignatures),
                    'computed' => substr($computedSignature, 0, 10) . '...'
                ]);
                throw new Exception('Invalid webhook signature');
            }
        }
    }

    // Get webhook type and job ID from metadata
    $metadata = $payload['input']['metadata'] ?? [];
    $predictionId = $payload['id'] ?? null;
    $jobId = $metadata['job_id'] ?? null;

    if (!$predictionId) {
        throw new Exception('Missing prediction ID in webhook payload');
    }

    // If job_id not in metadata, try to find it using prediction ID
    if (!$jobId) {
        $jobId = $stateManager->findJobByPredictionId($predictionId);
        if (!$jobId) {
            throw new Exception("No job found for prediction: $predictionId");
        }
    }

    // Load current state
    $state = $stateManager->getStripState($jobId);
    if (!$state) {
        throw new Exception("Job state not found: $jobId");
    }

    // Add detailed metadata logging
    $logger->debug('Webhook payload details', [
        'metadata' => $metadata,
        'type' => $metadata['type'] ?? 'not_set',
        'phase' => $state['phase'] ?? StateManager::PHASE_BACKGROUNDS,
        'prediction_id' => $predictionId,
        'job_id' => $jobId,
        'payload_status' => $payload['status'] ?? 'not_set',
        'input' => $payload['input'] ?? []
    ]);

    // Check if this webhook has already been processed
    $processedFile = __DIR__ . '/public/temp/webhook_' . $predictionId . '.processed';
    if (file_exists($processedFile)) {
        $logger->info('Webhook already processed', [
            'prediction_id' => $predictionId,
            'job_id' => $jobId
        ]);
        http_response_code(200);
        echo json_encode(['status' => 'success', 'message' => 'Webhook already processed']);
        return;
    }

    // Create a lock file to prevent concurrent processing
    $lockFile = __DIR__ . '/public/temp/webhook_' . $predictionId . '.lock';
    $lockFp = fopen($lockFile, 'c+');
    if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
        $logger->info('Webhook already being processed', [
            'prediction_id' => $predictionId,
            'job_id' => $jobId
        ]);
        if ($lockFp) {
            fclose($lockFp);
        }
        http_response_code(200);
        echo json_encode(['status' => 'success', 'message' => 'Webhook already being processed']);
        return;
    }

    try {
        // Update state based on prediction status
        if ($payload['status'] === 'succeeded') {
            // Get webhook type from metadata in input
            $type = $metadata['type'] ?? null;
            
            if ($type === 'cartoonify_complete') {
                $characterId = $metadata['character_id'] ?? null;
                if (!$characterId) {
                    throw new Exception('Missing character_id in webhook metadata');
                }

                // Log the complete payload for debugging
                $logger->debug('Cartoonify webhook payload', [
                    'raw_payload' => $payload,
                    'character_id' => $characterId,
                    'prediction_id' => $predictionId
                ]);

                // Get the output URL directly from payload output
                $cartoonifyUrl = $payload['output'] ?? null;
                if (is_array($cartoonifyUrl)) {
                    $cartoonifyUrl = $cartoonifyUrl[0] ?? null;
                }
                
                // Validate URL format
                if (!$cartoonifyUrl || !filter_var($cartoonifyUrl, FILTER_VALIDATE_URL)) {
                    $logger->error('Invalid cartoonify URL', [
                        'url' => $cartoonifyUrl,
                        'raw_output' => $payload['output'] ?? null
                    ]);
                    throw new Exception('Invalid cartoonify URL format');
                }

                // Ensure URL starts with https://
                if (strpos($cartoonifyUrl, 'https://') !== 0) {
                    $logger->error('Non-HTTPS cartoonify URL', [
                        'url' => $cartoonifyUrl
                    ]);
                    throw new Exception('Cartoonify URL must be HTTPS');
                }

                // Update character status in processes
                $characterItems = $state['processes'][StateManager::PHASE_CHARACTERS]['items'] ?? [];
                $characterItems[$characterId] = [
                    'status' => 'completed',
                    'cartoonify_url' => $cartoonifyUrl
                ];

                // Update character in options
                foreach ($state['options']['characters'] as &$character) {
                    if ($character['id'] === $characterId) {
                        $character['status'] = 'completed';
                        $character['cartoonify_url'] = $cartoonifyUrl;
                        break;
                    }
                }

                // Update both processes and options in state
                $stateManager->updateStripState($jobId, [
                    'processes' => array_merge($state['processes'], [
                        StateManager::PHASE_CHARACTERS => array_merge(
                            $state['processes'][StateManager::PHASE_CHARACTERS],
                            ['items' => $characterItems]
                        )
                    ]),
                    'options' => $state['options']
                ]);

                // Check if all characters are completed
                $allCompleted = true;
                foreach ($characterItems as $item) {
                    if ($item['status'] !== 'completed') {
                        $allCompleted = false;
                        break;
                    }
                }

                if ($allCompleted) {
                    $stateManager->updatePhase($jobId, StateManager::PHASE_CHARACTERS, 'completed');
                    // Process next step
                    $orchestrator->handleNextStep($jobId);
                }
            } elseif ($type === 'background_complete') {
                $panelId = $metadata['panel_id'] ?? null;
                if (!$panelId) {
                    throw new Exception('Missing panel_id in webhook metadata');
                }

                // Update background status
                $items = $state['processes'][StateManager::PHASE_BACKGROUNDS]['items'] ?? [];
                $items[$panelId] = [
                    'status' => 'completed',
                    'background_url' => $payload['output'][0] ?? null
                ];

                // Get NLP panels and update the matching panel's status
                $panels = $state['processes'][StateManager::PHASE_NLP]['result'] ?? [];
                foreach ($panels as &$panel) {
                    if ($panel['id'] === $panelId) {
                        $panel['status'] = 'completed';
                        $panel['background_url'] = $payload['output'][0] ?? null;
                        break;
                    }
                }

                // Update phase, items, and panels
                $updates = [
                    'phase' => StateManager::PHASE_BACKGROUNDS,
                    'processes' => array_merge($state['processes'], [
                        StateManager::PHASE_BACKGROUNDS => array_merge(
                            $state['processes'][StateManager::PHASE_BACKGROUNDS],
                            ['items' => $items]
                        ),
                        StateManager::PHASE_NLP => array_merge(
                            $state['processes'][StateManager::PHASE_NLP],
                            ['result' => $panels]
                        )
                    ])
                ];
                $stateManager->updateStripState($jobId, $updates);

                // Check if all backgrounds are completed
                $allCompleted = true;
                foreach ($items as $item) {
                    if ($item['status'] !== 'completed') {
                        $allCompleted = false;
                        break;
                    }
                }

                if ($allCompleted) {
                    // First update the backgrounds phase
                    $stateManager->updatePhase($jobId, StateManager::PHASE_BACKGROUNDS, 'completed');
                    
                    // Get fresh state after phase update
                    $state = $stateManager->getStripState($jobId);
                    
                    // Verify all phases are complete before proceeding
                    if ($state['processes'][StateManager::PHASE_NLP]['status'] === 'completed' &&
                        $state['processes'][StateManager::PHASE_CHARACTERS]['status'] === 'completed' &&
                        $state['processes'][StateManager::PHASE_BACKGROUNDS]['status'] === 'completed') {
                        
                        // Process next step which will handle final composition
                        $orchestrator->handleNextStep($jobId);
                    }
                }
            } else {
                // Handle NLP completion
                $output = $payload['output'] ?? [];
                $fullText = is_array($output) ? implode('', $output) : $output;
                
                // Log raw output for debugging
                $logger->debug('Raw NLP output', [
                    'text' => $fullText,
                    'prediction_id' => $predictionId
                ]);
                
                // Extract scenes using regex
                preg_match_all('/\*\*Scene \d+:.*?\*\*(.*?)(?=\*\*Scene|\z)/s', $fullText, $matches);
                
                // Create panels from scenes
                $panels = array_map(function ($sceneText) {
                    return [
                        'id' => uniqid('panel_'),
                        'description' => trim($sceneText),
                        'status' => 'pending',
                        'background_url' => null,
                        'error' => null
                    ];
                }, $matches[1] ?? []);

                // Ensure we have exactly 4 panels
                if (count($panels) !== 4) {
                    throw new Exception('Expected 4 scenes but got ' . count($panels));
                }

                $stateManager->updatePhase($jobId, StateManager::PHASE_NLP, 'completed', [
                    'result' => $panels
                ]);

                // Process next step
                $orchestrator->handleNextStep($jobId);
            }

            // Mark webhook as processed
            file_put_contents($processedFile, time());
        } elseif ($payload['status'] === 'failed') {
            $error = $payload['error'] ?? 'Unknown error';
            $phase = $state['phase'] ?? StateManager::PHASE_NLP;
            $stateManager->handleError($jobId, $phase, $error);
            
            // Mark webhook as processed even if it failed
            file_put_contents($processedFile, time());
        }

        // Return success response
        http_response_code(200);
        $state = $stateManager->getStripState($jobId);
        
        // Prepare response data
        $responseData = [
            'job_id' => $jobId,
            'status' => $state['status'],
            'phase' => $state['phase'],
            'progress' => $state['progress'],
            'output_url' => null
        ];

        // If job is completed, include panel URLs
        if ($state['status'] === StateManager::STATE_COMPLETED) {
            $panels = $state['processes'][StateManager::PHASE_NLP]['result'] ?? [];
            $backgrounds = $state['processes'][StateManager::PHASE_BACKGROUNDS]['items'] ?? [];
            $characters = $state['options']['characters'] ?? [];
            
            // Create a map of panel URLs
            $panelUrls = array_map(function($panel) use ($backgrounds) {
                return [
                    'id' => $panel['id'],
                    'url' => $backgrounds[$panel['id']]['background_url'] ?? null,
                    'description' => $panel['description']
                ];
            }, $panels);
            
            // Create a map of character URLs
            $characterUrls = array_map(function($character) {
                return [
                    'id' => $character['id'],
                    'name' => $character['name'],
                    'url' => $character['cartoonify_url']
                ];
            }, $characters);
            
            $responseData['output_url'] = [
                'panels' => $panelUrls,
                'characters' => $characterUrls
            ];
        }

        echo json_encode([
            'status' => 'success',
            'message' => 'Webhook processed successfully',
            'data' => $responseData
        ]);

    } finally {
        // Always release the lock and clean up
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
        @unlink($lockFile);
    }

} catch (Exception $e) {
    // Log error and return error response
    $logger->error('Webhook processing failed', [
        'error' => $e->getMessage(),
        'job_id' => $jobId ?? null
    ]);

    // Try to update job state to failed if we have the job ID and state manager
    if (isset($jobId) && isset($stateManager)) {
        try {
            $state = $stateManager->getStripState($jobId);
            if ($state) {
                $stateManager->handleError($jobId, $state['phase'] ?? StateManager::PHASE_NLP, $e->getMessage());
            }
        } catch (Exception $stateError) {
            $logger->error('Failed to update error state', [
                'job_id' => $jobId,
                'error' => $stateError->getMessage()
            ]);
        }
    }

    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Failed to process webhook: ' . $e->getMessage()
    ]);
}
