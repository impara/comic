<?php

require_once __DIR__ . '/../interfaces/LoggerInterface.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/HttpClient.php';

class ReplicateClient
{
    private string $apiKey;
    private string $apiUrl = 'https://api.replicate.com/v1/predictions';
    private LoggerInterface $logger;
    private Config $config;

    public function __construct(string $apiKey, LoggerInterface $logger)
    {
        $this->apiKey = $apiKey;
        $this->logger = $logger;
        $this->config = Config::getInstance();
    }

    public function createPrediction(array $input): array
    {
        $this->logger->debug('Creating prediction', [
            'input' => $input,
            'webhook_url' => $this->config->getBaseUrl() . '/webhook.php'
        ]);

        // Get model version from config
        $modelConfig = $this->config->get('replicate.models.cartoonify');
        if (!$modelConfig || !isset($modelConfig['version'])) {
            throw new Exception('Cartoonify model version not configured');
        }

        // Get webhook URL from config
        $webhookUrl = $this->config->getBaseUrl() . '/webhook.php';

        $response = $this->post([
            'version' => $modelConfig['version'],
            'input' => [
                'image' => $input['image'],
                'character_id' => $input['character_id']
            ],
            'webhook' => $webhookUrl,
            'webhook_events_filter' => ['completed']
        ]);

        if (!isset($response['id'])) {
            throw new Exception('Invalid prediction response');
        }

        $this->logger->debug('Prediction created successfully', [
            'prediction_id' => $response['id'],
            'status' => $response['status'] ?? 'unknown',
            'webhook_configured' => true,
            'webhook_url' => $webhookUrl
        ]);

        return $response;
    }

    public function predict(string $modelType, array $params): array
    {
        $this->logger->info('Making prediction', [
            'model_type' => $modelType,
            'params' => $params
        ]);

        // Get model configuration
        $modelConfig = $this->config->get("replicate.models.{$modelType}");
        if (!$modelConfig) {
            throw new Exception("Model type '{$modelType}' not configured");
        }

        // For NLP model, handle synchronously
        if ($modelType === 'nlp') {
            $result = $this->post([
                'version' => $modelConfig['version'],
                'input' => array_merge($modelConfig['params'], $params)
            ]);

            // Poll for results
            $maxAttempts = 30;
            $attempt = 0;
            while ($attempt < $maxAttempts) {
                $status = $this->get("https://api.replicate.com/v1/predictions/{$result['id']}");

                if ($status['status'] === 'succeeded') {
                    return $status['output'];
                } elseif ($status['status'] === 'failed') {
                    throw new Exception($status['error'] ?? 'Prediction failed');
                }

                $attempt++;
                sleep(2);
            }
            throw new Exception("Prediction timed out");
        }

        throw new Exception("Unsupported model type: {$modelType}");
    }

    private function post(array $data): array
    {
        $ch = curl_init($this->apiUrl);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Token ' . $this->apiKey,
            'Content-Type: application/json'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 201) {
            throw new Exception('Failed to create prediction: ' . $response);
        }

        return json_decode($response, true);
    }

    private function get(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Token ' . $this->apiKey,
            'Content-Type: application/json'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception('Failed to get prediction: ' . $response);
        }

        return json_decode($response, true);
    }
}
