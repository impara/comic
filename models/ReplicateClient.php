<?php

class ReplicateClient
{
    private string $apiUrl = 'https://api.replicate.com/v1/predictions';
    private LoggerInterface $logger;
    private Config $config;
    private string $apiToken;
    private HttpClient $httpClient;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->config = Config::getInstance();
        try {
            $this->apiToken = $this->config->getReplicateApiKey();
        } catch (Exception $e) {
            $this->logger->error('Failed to initialize Replicate client: ' . $e->getMessage());
            throw new RuntimeException('Replicate API token not configured properly', 0, $e);
        }
        $this->httpClient = new HttpClient($logger, $this->apiToken);
    }

    public function createPrediction(array $input): array
    {
        $this->logger->debug('Creating prediction', [
            'input' => array_merge($input, ['prompt' => substr($input['prompt'] ?? '', 0, 100) . '...']),
            'webhook_url' => $input['webhook'] ?? null,
            'webhook_events' => $input['webhook_events_filter'] ?? []
        ]);

        // Skip webhook in development mode
        $isDev = $this->config->getEnvironment() === 'development';
        
        // Prepare API request data
        $requestData = [
            'version' => $input['model'],
            'input' => $input['input'] ?? []
        ];

        // Only add webhook in production
        if (!$isDev && isset($input['webhook'])) {
            $requestData['webhook'] = str_replace('http://', 'https://', $input['webhook']);
            $requestData['webhook_events_filter'] = $input['webhook_events_filter'] ?? ['completed'];
        }

        $this->logger->debug('Making Replicate API request', [
            'version' => $requestData['version'],
            'webhook_config' => [
                'url' => $requestData['webhook'] ?? null,
                'events' => $requestData['webhook_events_filter'] ?? []
            ],
            'environment' => $this->config->getEnvironment()
        ]);

        try {
            $response = $this->httpClient->request('POST', $this->apiUrl, [
                'headers' => [
                    'Authorization' => 'Token ' . $this->apiToken,
                    'Content-Type' => 'application/json'
                ],
                'json' => $requestData
            ]);

            if (!isset($response['id'])) {
                throw new Exception('Invalid prediction response: missing prediction ID');
            }

            if (!isset($response['status'])) {
                throw new Exception('Invalid prediction response: missing status');
            }

            $this->logger->debug('Prediction created successfully', [
                'prediction_id' => $response['id'],
                'status' => $response['status'],
                'webhook_configured' => isset($requestData['webhook']),
                'webhook_url' => $requestData['webhook'] ?? null,
                'webhook_events' => $requestData['webhook_events_filter']
            ]);

            return $response;
        } catch (Exception $e) {
            $this->logger->error('Failed to create prediction', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => array_merge($requestData, [
                    'prompt' => substr($requestData['input']['prompt'] ?? '', 0, 100) . '...'
                ])
            ]);
            throw $e;
        }
    }

    public function getPrediction(string $predictionId): array
    {
        try {
            $response = $this->httpClient->request('GET', $this->apiUrl . '/' . $predictionId);

            if (!isset($response['id'])) {
                throw new Exception('Invalid prediction response: missing prediction ID');
            }

            if (!isset($response['status'])) {
                throw new Exception('Invalid prediction response: missing status');
            }

            return $response;
        } catch (Exception $e) {
            $this->logger->error('Failed to get prediction', [
                'prediction_id' => $predictionId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}
