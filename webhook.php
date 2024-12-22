<?php

require_once __DIR__ . '/models/StateManager.php';
require_once __DIR__ . '/models/Config.php';
require_once __DIR__ . '/models/Logger.php';

class WebhookHandler
{
    private $stateManager;
    private $logger;
    private $config;

    public function __construct(StateManager $stateManager, Logger $logger, Config $config)
    {
        $this->stateManager = $stateManager;
        $this->logger = $logger;
        $this->config = $config;
    }

    public function handleWebhook(): void
    {
        try {
            // Get webhook payload
            $payload = json_decode(file_get_contents('php://input'), true);
            if (!$payload) {
                throw new Exception('Invalid webhook payload');
            }

            $this->logger->info('Received webhook', ['payload' => $payload]);

            // Get prediction ID and read pending file
            $predictionId = $payload['id'] ?? null;
            if (!$predictionId) {
                throw new Exception('Missing prediction ID');
            }

            $pendingFile = $this->config->getTempPath() . "pending_{$predictionId}.json";
            if (!file_exists($pendingFile)) {
                throw new Exception('Pending file not found');
            }

            $pendingData = json_decode(file_get_contents($pendingFile), true);
            if (!$pendingData) {
                throw new Exception('Invalid pending data');
            }

            // Extract basic information
            $stripId = $pendingData['strip_id'] ?? null;
            $characterId = $pendingData['options']['character_id'] ?? null;
            $status = $payload['status'] ?? null;
            $output = $payload['output'] ?? null;

            if (!$stripId || !$characterId) {
                throw new Exception('Missing required data');
            }

            // Update character state
            $stripState = $this->stateManager->getStripState($stripId);

            if (!isset($stripState['characters'])) {
                $stripState['characters'] = [];
            }

            // Simple character state update
            if ($status === 'succeeded' && $output) {
                $this->logger->info('Processing successful cartoonification', [
                    'replicate_url' => $output,
                    'character_id' => $characterId
                ]);

                // Generate unique filename for cartoonified image
                $originalFilename = $pendingData['image_path'] ?? uniqid();
                $filename = 'cartoonified_' . $originalFilename;
                $outputPath = rtrim($this->config->getOutputPath(), '/') . '/' . $filename;

                try {
                    $imageContent = file_get_contents($output);
                    if ($imageContent === false) {
                        throw new Exception('Failed to download cartoonified image');
                    }

                    if (file_put_contents($outputPath, $imageContent) === false) {
                        throw new Exception('Failed to save cartoonified image');
                    }

                    // Set proper permissions
                    chmod($outputPath, 0644);
                    if (function_exists('posix_getpwuid')) {
                        chown($outputPath, 'www-data');
                        chgrp($outputPath, 'www-data');
                    }

                    $this->logger->info('Saved cartoonified image', [
                        'path' => $outputPath,
                        'filename' => $filename,
                        'size' => strlen($imageContent),
                        'permissions' => substr(sprintf('%o', fileperms($outputPath)), -4),
                        'owner' => function_exists('posix_getpwuid') ? posix_getpwuid(fileowner($outputPath))['name'] : 'unknown'
                    ]);

                    // Update character state with local URL
                    $generatedPath = basename($this->config->getOutputPath());
                    $localUrl = rtrim($this->config->getBaseUrl(), '/') . '/' . $generatedPath . '/' . $filename;
                    $stripState['characters'][$characterId] = [
                        'id' => $characterId,
                        'image_url' => $localUrl,
                        'status' => 'completed'
                    ];

                    $this->logger->info('Updated character state', [
                        'character_id' => $characterId,
                        'image_url' => $localUrl
                    ]);
                } catch (Exception $e) {
                    $this->logger->error('Failed to handle cartoonified image', [
                        'error' => $e->getMessage(),
                        'character_id' => $characterId
                    ]);

                    $stripState['characters'][$characterId] = [
                        'id' => $characterId,
                        'status' => 'failed',
                        'error' => 'Failed to save cartoonified image: ' . $e->getMessage()
                    ];
                }
            } else {
                $stripState['characters'][$characterId] = [
                    'id' => $characterId,
                    'status' => 'failed',
                    'error' => $payload['error'] ?? 'Processing failed'
                ];
            }

            // Update strip progress
            $totalCharacters = count($stripState['characters']);
            $completedCharacters = count(array_filter(
                $stripState['characters'],
                fn($char) => $char['status'] === 'completed'
            ));

            $stripState['progress'] = $totalCharacters > 0
                ? round(($completedCharacters / $totalCharacters) * 100)
                : 0;

            // Update strip status
            $allComplete = $completedCharacters === $totalCharacters;
            $anyFailed = count(array_filter(
                $stripState['characters'],
                fn($char) => $char['status'] === 'failed'
            )) > 0;

            if ($allComplete) {
                $stripState['status'] = 'completed';
                // Set the output path to the last completed character's image
                $lastCompleted = array_filter($stripState['characters'], fn($char) => $char['status'] === 'completed');
                $lastChar = end($lastCompleted);
                $stripState['output_path'] = $lastChar['image_url'];
            } elseif ($anyFailed) {
                $stripState['status'] = 'failed';
                $stripState['error'] = 'One or more characters failed to process';
            } else {
                $stripState['status'] = 'processing';
            }

            // Save state and clean up
            $this->stateManager->updateStripState($stripId, $stripState);
            if (file_exists($pendingFile)) {
                unlink($pendingFile);
            }

            http_response_code(200);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $this->logger->error('Webhook error', [
                'error' => $e->getMessage()
            ]);
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }
}

// Initialize and handle webhook
$config = new Config();
$logger = new Logger();
$stateManager = new StateManager($config->getTempPath(), $logger);
$handler = new WebhookHandler($stateManager, $logger, $config);
$handler->handleWebhook();
