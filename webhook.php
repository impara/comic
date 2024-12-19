<?php

require_once __DIR__ . '/bootstrap.php';

$logger = new Logger();
$config = Config::getInstance();
$replicateClient = new ReplicateClient($logger);

// Function to safely handle file operations with locking
function withFileLock($filePath, callable $callback)
{
    $lockFile = $filePath . '.lock';
    $fp = fopen($lockFile, 'w');

    if (!$fp) {
        throw new Exception("Could not create lock file: $lockFile");
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            throw new Exception("Could not acquire lock for file: $filePath");
        }

        $result = $callback();

        flock($fp, LOCK_UN);
        return $result;
    } finally {
        fclose($fp);
        @unlink($lockFile);
    }
}

try {
    // Get and validate the webhook payload
    $payload = file_get_contents('php://input');
    $data = json_decode($payload, true);

    if (!$data) {
        throw new Exception("Invalid JSON payload received");
    }

    $logger->info("Received webhook payload", [
        'prediction_id' => $data['id'] ?? 'none',
        'status' => $data['status'] ?? 'unknown'
    ]);

    // Verify webhook signature in production
    $webhookSecret = $config->get('replicate.webhook_secret');
    if (!empty($webhookSecret)) {
        $signature = $_SERVER['HTTP_REPLICATE_WEBHOOK_SIGNATURE'] ?? '';
        if (empty($signature)) {
            throw new Exception("Missing webhook signature");
        }

        $computedSignature = hash_hmac('sha256', $payload, $webhookSecret);
        if (!hash_equals($computedSignature, $signature)) {
            throw new Exception("Invalid webhook signature");
        }
    } else {
        $logger->warning("Running in TESTING MODE - webhook signature verification disabled");
    }

    // Basic validation
    if (empty($data['id']) || !isset($data['status'])) {
        throw new Exception("Invalid webhook payload structure");
    }

    $predictionId = $data['id'];
    $tempPath = $config->getTempPath();

    // Process pending files
    $pendingFiles = glob($tempPath . "pending_*.json");
    foreach ($pendingFiles as $pendingFile) {
        withFileLock($pendingFile, function () use ($pendingFile, $data, $predictionId, $tempPath, $logger) {
            $pending = json_decode(file_get_contents($pendingFile), true);

            if (!$pending || !isset($pending['prediction_id']) || $pending['prediction_id'] !== $predictionId) {
                return;
            }

            $originalPanelId = $pending['original_panel_id'] ?? null;
            $stripId = $pending['strip_id'] ?? null;
            $currentStage = $pending['stage'] ?? 'unknown';

            if (!$originalPanelId) {
                $logger->error("Missing original panel ID", [
                    'prediction_id' => $predictionId,
                    'pending_file' => basename($pendingFile)
                ]);
                return;
            }

            // Update strip state if available
            if ($stripId) {
                $stripStateFile = $tempPath . "strip_state_{$stripId}.json";
                if (file_exists($stripStateFile)) {
                    withFileLock($stripStateFile, function () use ($stripStateFile, $data, $originalPanelId) {
                        $stripState = json_decode(file_get_contents($stripStateFile), true);
                        if (!$stripState) {
                            return;
                        }

                        // Update panel status
                        if (isset($stripState['panels'])) {
                            foreach ($stripState['panels'] as &$panel) {
                                if ($panel['id'] === $originalPanelId) {
                                    $panel['status'] = $data['status'];
                                    $panel['updated_at'] = time();
                                    if ($data['status'] === 'succeeded') {
                                        $panel['completed_at'] = time();
                                    } else if ($data['status'] === 'failed') {
                                        $panel['failed_at'] = time();
                                        $panel['error'] = $data['error'] ?? 'Unknown error';
                                    }
                                    break;
                                }
                            }

                            // Check overall status
                            $allComplete = true;
                            $anyFailed = false;
                            foreach ($stripState['panels'] as $panel) {
                                if ($panel['status'] === 'failed') {
                                    $anyFailed = true;
                                    break;
                                }
                                if ($panel['status'] !== 'succeeded') {
                                    $allComplete = false;
                                }
                            }

                            if ($anyFailed) {
                                $stripState['status'] = 'failed';
                                $stripState['error'] = 'One or more panels failed to generate';
                                $stripState['failed_at'] = time();
                            } else if ($allComplete) {
                                $stripState['status'] = 'completed';
                                $stripState['completed_at'] = time();
                            }

                            $stripState['updated_at'] = time();
                            file_put_contents($stripStateFile, json_encode($stripState));
                        }
                    });
                }
            }

            // Update panel state
            $stateFile = $tempPath . "state_{$originalPanelId}.json";
            if (file_exists($stateFile)) {
                withFileLock($stateFile, function () use ($stateFile, $data, $currentStage, $originalPanelId, $tempPath) {
                    $state = json_decode(file_get_contents($stateFile), true);
                    if (!$state) {
                        return;
                    }

                    if ($currentStage === 'sdxl') {
                        if ($data['status'] === 'succeeded' && !empty($data['output'])) {
                            $state['sdxl_status'] = 'succeeded';
                            $state['sdxl_output'] = is_array($data['output']) ? $data['output'][0] : $data['output'];
                            $state['sdxl_completed_at'] = time();
                            $state['status'] = 'succeeded';
                            file_put_contents($stateFile, json_encode($state));

                            // Write final result
                            $finalResult = [
                                'id' => $originalPanelId,
                                'status' => 'succeeded',
                                'output' => $state['sdxl_output'],
                                'completed_at' => date('c')
                            ];
                            file_put_contents($tempPath . "{$originalPanelId}.json", json_encode($finalResult));
                        } else if ($data['status'] === 'failed') {
                            $state['sdxl_status'] = 'failed';
                            $state['sdxl_error'] = $data['error'] ?? 'SDXL generation failed';
                            $state['sdxl_failed_at'] = time();
                            $state['status'] = 'failed';
                            file_put_contents($stateFile, json_encode($state));
                        }
                    }
                });
            }
        });
    }

    http_response_code(200);
    echo json_encode(['status' => 'success']);
} catch (Throwable $e) {
    $logger->error("Webhook error", [
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);

    http_response_code(500);
    echo json_encode([
        'error' => 'Internal Server Error',
        'message' => IS_PRODUCTION ? 'An unexpected error occurred' : $e->getMessage()
    ]);
}
