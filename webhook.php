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

            // Get prediction ID from payload
            $predictionId = $payload['id'] ?? null;
            if (!$predictionId) {
                throw new Exception('Missing prediction ID in webhook data');
            }

            // Read pending file data
            $tempPath = $this->config->getTempPath();
            $pendingFile = $tempPath . "pending_{$predictionId}.json";
            if (file_exists($pendingFile)) {
                $pendingData = json_decode(file_get_contents($pendingFile), true);
                if ($pendingData) {
                    // Merge pending data with payload
                    $payload = array_merge($payload, $pendingData);
                }
            }

            // Extract necessary information
            $panelId = $payload['panel_id'] ?? null;
            $stripId = $payload['strip_id'] ?? null;
            $status = $payload['status'] ?? null;
            $output = $payload['output'] ?? null;
            $stage = $payload['stage'] ?? null;

            if (!$panelId || !$stripId) {
                throw new Exception('Missing required webhook data');
            }

            // Update panel state based on stage
            $panelUpdate = [
                'webhook_received_at' => time()
            ];

            if ($stage === 'cartoonify') {
                if ($status === 'succeeded') {
                    $panelUpdate['status'] = StateManager::STATUS_PROCESSING;
                    $panelUpdate['cartoonified_image'] = $output;
                } else if ($status === 'failed') {
                    $panelUpdate['status'] = StateManager::STATUS_FAILED;
                    $panelUpdate['error'] = $payload['error'] ?? 'Cartoonification failed';
                }
            } else {
                $panelUpdate['status'] = $status === 'succeeded' ? StateManager::STATUS_COMPLETED : StateManager::STATUS_FAILED;
                if ($output) {
                    $panelUpdate['output_path'] = $output;
                }
                if ($status === 'failed') {
                    $panelUpdate['error'] = $payload['error'] ?? 'Panel generation failed';
                }
            }

            $this->stateManager->updatePanelState($panelId, $panelUpdate);

            // Check if all panels are complete
            $stripState = $this->stateManager->getStripState($stripId);
            $allComplete = true;
            $anyFailed = false;

            foreach ($stripState['panels'] as $panel) {
                if ($panel['status'] === 'failed') {
                    $anyFailed = true;
                    break;
                }
                if ($panel['status'] !== 'completed') {
                    $allComplete = false;
                }
            }

            // Update strip state accordingly
            if ($anyFailed) {
                $this->stateManager->updateStripState($stripId, [
                    'status' => 'failed',
                    'error' => 'One or more panels failed to generate'
                ]);
            } elseif ($allComplete) {
                $this->stateManager->updateStripState($stripId, [
                    'status' => 'completed'
                ]);
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
