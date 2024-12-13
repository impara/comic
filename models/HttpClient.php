<?php

require_once __DIR__ . '/../interfaces/LoggerInterface.php';

class HttpClient
{
    private LoggerInterface $logger;
    private string $apiKey;

    public function __construct(LoggerInterface $logger, string $apiKey)
    {
        $this->logger = $logger;
        $this->apiKey = $apiKey;
    }

    /**
     * Makes an HTTP request with proper error logging
     *
     * @param string $endpoint The URL to make the request to
     * @param array $payload The data to send (for POST requests)
     * @param string $method The HTTP method (GET, POST, etc.)
     * @return array The decoded response data
     * @throws Exception If the request fails or returns an error status
     */
    public function request(string $endpoint, array $payload = [], string $method = 'POST'): array
    {
        try {
            $ch = curl_init($endpoint);
            if ($ch === false) {
                throw new RuntimeException('Failed to initialize cURL');
            }

            $headers = [
                'Authorization: Token ' . $this->apiKey,
                'Content-Type: application/json'
            ];

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_CUSTOMREQUEST => $method
            ]);

            if ($method === 'POST' && !empty($payload)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            }

            $response = curl_exec($ch);
            if ($response === false) {
                throw new RuntimeException('cURL error: ' . curl_error($ch));
            }

            $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $responseData = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException('Invalid JSON response: ' . json_last_error_msg());
            }

            if ($statusCode >= 400) {
                $this->logger->error('Unprocessable Entity Error', [
                    'endpoint' => $endpoint,
                    'method' => $method,
                    'payload' => $payload,
                    'response' => $responseData,
                    'error_message' => $responseData['detail'] ?? 'Unknown error'
                ]);
                throw new RuntimeException($responseData['detail'] ?? 'API request failed');
            }

            return $responseData;
        } catch (Exception $e) {
            $this->logger->error('HTTP request failed', [
                'endpoint' => $endpoint,
                'method' => $method,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Extracts a meaningful error message from Replicate's error response
     *
     * @param array|null $responseData The decoded response data
     * @return string A meaningful error message
     */
    private function extractErrorMessage(?array $responseData): string
    {
        if (!$responseData) {
            return "No response data available";
        }

        // Handle Replicate's detailed error format
        if (isset($responseData['detail'])) {
            if (is_array($responseData['detail'])) {
                // Handle validation errors array
                $errors = array_map(function ($error) {
                    return isset($error['loc'])
                        ? "{$error['msg']} at " . implode('.', $error['loc'])
                        : $error['msg'];
                }, $responseData['detail']);
                return implode('; ', $errors);
            }
            return $responseData['detail'];
        }

        // Handle simple error message
        if (isset($responseData['error'])) {
            return is_string($responseData['error'])
                ? $responseData['error']
                : json_encode($responseData['error']);
        }

        // Handle nested errors
        if (isset($responseData['errors']) && is_array($responseData['errors'])) {
            return implode('; ', array_map(function ($error) {
                return is_string($error) ? $error : json_encode($error);
            }, $responseData['errors']));
        }

        // Fallback for unknown error format
        return json_encode($responseData);
    }

    /**
     * Makes a POST request
     * @param string $endpoint The endpoint to post to
     * @param array $payload The data to send
     * @return array The decoded response data
     * @throws Exception If the request fails
     */
    public function post(string $endpoint, array $payload): array
    {
        return $this->request($endpoint, $payload, 'POST');
    }

    /**
     * Make a GET request to the specified endpoint
     * @param string $endpoint The endpoint to get from
     * @return array The decoded response data
     * @throws Exception If the request fails
     */
    public function get(string $endpoint): array
    {
        return $this->request($endpoint, [], 'GET');
    }
}
