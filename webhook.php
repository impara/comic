<?php

require_once __DIR__ . '/models/StateManager.php';
require_once __DIR__ . '/models/Config.php';
require_once __DIR__ . '/models/Logger.php';

class WebhookHandler
{
    private $stateManager;
    private $logger;

    public function __construct(StateManager $stateManager, Logger $logger)
    {
        $this->stateManager = $stateManager;
        $this->logger = $logger;
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

            // Extract necessary information
            $panelId = $payload['panel_id'] ?? null;
            $stripId = $payload['strip_id'] ?? null;
            $status = $payload['status'] ?? null;
            $output = $payload['output'] ?? null;

            if (!$panelId || !$stripId) {
                throw new Exception('Missing required webhook data');
            }

            // Update panel state
            $panelUpdate = [
                'status' => $status,
                'webhook_received_at' => time()
            ];

            if ($output) {
                $panelUpdate['output_path'] = $output;
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
$handler = new WebhookHandler($stateManager, $logger);
$handler->handleWebhook();
