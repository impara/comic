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
                $stripState['characters'][$characterId] = [
                    'id' => $characterId,
                    'image_url' => $output,
                    'status' => 'completed'
                ];

                // If all characters are complete, set the output path
                $allComplete = count(array_filter(
                    $stripState['characters'],
                    fn($char) => $char['status'] === 'completed'
                )) === count($stripState['characters']);

                if ($allComplete) {
                    $stripState['output_path'] = $output;
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

            $stripState['status'] = $completedCharacters === $totalCharacters
                ? 'completed'
                : 'processing';

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
