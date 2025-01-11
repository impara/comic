<?php

require_once __DIR__ . '/../interfaces/LoggerInterface.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/HttpClient.php';
require_once __DIR__ . '/ReplicateClient.php';
require_once __DIR__ . '/StateManager.php';

class StoryParser
{
    private LoggerInterface $logger;
    private Config $config;
    private HttpClient $httpClient;
    private ReplicateClient $replicateClient;
    private StateManager $stateManager;

    public function __construct(LoggerInterface $logger, Config $config, StateManager $stateManager)
    {
        $this->logger = $logger;
        $this->config = $config;
        $this->httpClient = new HttpClient($logger, $config->getReplicateApiKey());
        $this->replicateClient = new ReplicateClient($logger, $config);
        $this->stateManager = $stateManager;
    }

    /**
     * Parse a story into segments for comic panels
     */
    public function parseStory(string $story, string $jobId): array
    {
        try {
            $this->logger->info('Starting story parsing', [
                'job_id' => $jobId
            ]);

            // Validate story input
            if (empty(trim($story))) {
                throw new Exception('Story cannot be empty');
            }

            // Start NLP processing
            $prediction = $this->startNlpProcessing($story, $jobId);
            if (!$prediction || !isset($prediction['id'])) {
                throw new Exception('Failed to start NLP processing');
            }

            // Update NLP phase status
            $this->stateManager->updatePhase($jobId, StateManager::PHASE_NLP, 'processing', [
                'prediction_id' => $prediction['id']
            ]);

            return [
                'id' => $prediction['id'],
                'status' => 'processing'
            ];
        } catch (Exception $e) {
            $this->logger->error('Story parsing failed', [
                'job_id' => $jobId,
                'error' => $e->getMessage()
            ]);

            // Update NLP phase status to failed
            $this->stateManager->handleError($jobId, StateManager::PHASE_NLP, $e->getMessage());

            throw $e;
        }
    }

    /**
     * Start NLP processing
     */
    private function startNlpProcessing(string $story, string $jobId): array
    {
        try {
            $maxRetries = $this->config->get('replicate.models.nlp.max_retries', 2);
            $retryDelay = $this->config->get('replicate.models.nlp.retry_delay', 5);
            $lastError = null;

            for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
                try {
                    if ($attempt > 0) {
                        $this->logger->info('Retrying NLP processing', [
                            'attempt' => $attempt,
                            'job_id' => $jobId
                        ]);
                        sleep($retryDelay);
                    }

                    $requestData = [
                        'version' => $this->config->get('replicate.models.nlp.version'),
                        'input' => [
                            'prompt' => $this->formatPrompt(
                                $this->config->getModelParams('nlp')['system'],
                                $story
                            ),
                            'max_tokens' => 1000,
                            'temperature' => 0.7,
                            'top_p' => 0.9
                        ],
                        'webhook' => rtrim($this->config->getBaseUrl(), '/') . '/webhook.php'
                    ];

                    // Only add webhook in production environment
                    if ($this->config->getEnvironment() === 'development') {
                        unset($requestData['webhook']);
                    }

                    $prediction = $this->replicateClient->createPrediction($requestData);

                    $this->logger->debug('NLP prediction created', [
                        'prediction_id' => $prediction['id'],
                        'status' => $prediction['status'] ?? 'unknown'
                    ]);

                    // In development, poll for completion
                    if ($this->config->getEnvironment() === 'development') {
                        $prediction = $this->pollForCompletion($prediction['id'], $jobId);
                    }

                    return $prediction;
                } catch (Exception $e) {
                    $lastError = $e;
                    $this->logger->warning('NLP processing attempt failed', [
                        'attempt' => $attempt,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            throw new Exception('Failed to start NLP processing after ' . ($maxRetries + 1) . ' attempts: ' . $lastError->getMessage());
        } catch (Exception $e) {
            $this->logger->error('Failed to start NLP processing', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Poll for prediction completion in development environment
     */
    private function pollForCompletion(string $predictionId, string $jobId): array
    {
        $maxAttempts = 30;
        $completed = false;
        $prediction = null;

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $prediction = $this->replicateClient->getPrediction($predictionId);
            
            if ($prediction['status'] === 'succeeded') {
                $completed = true;
                
                // Debug log the full prediction response
                $this->logger->debug('NLP prediction response', [
                    'prediction_id' => $predictionId,
                    'status' => $prediction['status'],
                    'output' => $prediction['output'] ?? null,
                    'raw' => $prediction
                ]);

                // Simulate webhook in development
                $this->logger->info('Simulating webhook in development', [
                    'prediction_id' => $predictionId
                ]);

                // Extract scenes from output
                $fullText = implode('', $prediction['output'] ?? []);
                preg_match_all('/\*\*Scene \d+:.*?\*\*(.*?)(?=\*\*Scene|\z)/s', $fullText, $matches);
                
                // Validate scene count before sending webhook
                if (count($matches[1] ?? []) !== 4) {
                    throw new Exception('Expected 4 scenes but got ' . count($matches[1] ?? []));
                }

                // Manually trigger webhook processing
                $webhookPayload = [
                    'id' => $predictionId,
                    'status' => 'succeeded',
                    'output' => $prediction['output'] ?? [],
                    'metadata' => [
                        'type' => 'nlp_complete',
                        'job_id' => $jobId
                    ]
                ];

                // Call webhook handler
                $webhookUrl = rtrim($this->config->getBaseUrl(), '/') . '/webhook.php';
                $this->sendWebhookCallback($webhookUrl, $webhookPayload);

                return $prediction;
            } elseif ($prediction['status'] === 'failed') {
                throw new Exception('NLP processing failed: ' . ($prediction['error'] ?? 'Unknown error'));
            }

            sleep(2);
        }

        if (!$completed) {
            throw new Exception('NLP processing timed out after ' . $maxAttempts . ' attempts');
        }

        return $prediction;
    }

    /**
     * Send webhook callback
     */
    private function sendWebhookCallback(string $webhookUrl, array $payload): void
    {
        try {
            $ch = curl_init($webhookUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'User-Agent: ComicGenerator/1.0'
                ],
                CURLOPT_TIMEOUT => 10
            ]);

            $response = curl_exec($ch);
            $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $this->logger->debug('Development webhook callback sent', [
                'url' => $webhookUrl,
                'status_code' => $statusCode,
                'response' => $response
            ]);
        } catch (Exception $e) {
            $this->logger->error('Failed to send development webhook callback', [
                'url' => $webhookUrl,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Format the prompt for the NLP model
     */
    private function formatPrompt(string $systemPrompt, string $userPrompt): string
    {
        return "<|begin_of_text|><|start_header_id|>system<|end_header_id|>\n\n" . 
               $systemPrompt . 
               "<|eot_id|><|start_header_id|>user<|end_header_id|>\n\n" . 
               $userPrompt . 
               "<|eot_id|><|start_header_id|>assistant<|end_header_id|>";
    }
}
