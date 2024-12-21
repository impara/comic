<?php

require_once __DIR__ . '/../interfaces/LoggerInterface.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/HttpClient.php';

class ReplicateClient
{
    private string $apiKey;
    private string $apiUrl = 'https://api.replicate.com/v1/predictions';
    private LoggerInterface $logger;

    public function __construct(string $apiKey, LoggerInterface $logger)
    {
        $this->apiKey = $apiKey;
        $this->logger = $logger;
    }

    public function createPrediction(array $input): array
    {
        $this->logger->info('Creating prediction', ['input' => $input]);

        $response = $this->post([
            'version' => getenv('REPLICATE_MODEL_VERSION'),
            'input' => [
                'image' => $input['image'],
                'character_id' => $input['character_id']
            ],
            'webhook' => getenv('WEBHOOK_URL')
        ]);

        if (!isset($response['id'])) {
            throw new Exception('Invalid prediction response');
        }

        return $response;
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
}
