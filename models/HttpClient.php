<?php

class HttpClient
{
    private LoggerInterface $logger;
    private string $apiToken;
    private array $defaultHeaders;

    public function __construct(LoggerInterface $logger, string $apiToken)
    {
        $this->logger = $logger;
        $this->apiToken = $apiToken;
        $this->defaultHeaders = [
            'Authorization' => 'Token ' . $apiToken,
            'Content-Type' => 'application/json'
        ];
    }

    public function request(string $method, string $url, array $options = []): array
    {
        $ch = curl_init();

        $headers = array_map(
            fn($key, $value) => "$key: $value",
            array_keys($options['headers'] ?? $this->defaultHeaders),
            array_values($options['headers'] ?? $this->defaultHeaders)
        );

        $postData = null;
        if (isset($options['json'])) {
            $postData = json_encode($options['json']);
            if ($postData === false) {
                throw new RuntimeException('Failed to encode request data: ' . json_last_error_msg());
            }
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_TIMEOUT => 30
        ]);

        $this->logger->debug('Making HTTP request', [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'data_length' => $postData ? strlen($postData) : 0
        ]);

        $response = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $errno = curl_errno($ch);

        curl_close($ch);

        if ($response === false) {
            $this->logger->error('HTTP request failed', [
                'error' => $error,
                'errno' => $errno,
                'url' => $url
            ]);
            throw new RuntimeException("HTTP request failed: $error");
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error('Failed to decode response', [
                'error' => json_last_error_msg(),
                'response' => substr($response, 0, 1000)
            ]);
            throw new RuntimeException('Invalid JSON response: ' . json_last_error_msg());
        }

        if ($statusCode >= 400) {
            $this->logger->error('HTTP request failed', [
                'status_code' => $statusCode,
                'response' => $data,
                'raw_response' => substr($response, 0, 1000),
                'request_data' => $postData,
                'url' => $url
            ]);
            throw new RuntimeException("HTTP request failed with status $statusCode: " .
                ($data['error'] ?? ($data['detail'] ?? 'Unknown error')));
        }

        $this->logger->debug('HTTP request completed', [
            'status_code' => $statusCode,
            'response_length' => strlen($response)
        ]);

        return $data;
    }
}
