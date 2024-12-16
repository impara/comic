<?php

require_once __DIR__ . '/../interfaces/LoggerInterface.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/HttpClient.php';

class ReplicateClient
{
    private string $apiKey;
    private LoggerInterface $logger;
    private Config $config;
    private HttpClient $httpClient;

    /**
     * @param LoggerInterface $logger Logger instance
     * @throws Exception if cURL extension is not installed
     */
    public function __construct(LoggerInterface $logger)
    {
        if (!extension_loaded('curl')) {
            throw new Exception('cURL extension is required but not installed');
        }

        $this->config = Config::getInstance();
        $this->apiKey = $this->config->getApiToken();
        $this->logger = $logger;
        $this->httpClient = new HttpClient($logger, $this->apiKey);
    }

    /**
     * Generate an image from text description
     * @param string $prompt Text description to generate image from
     * @param array $options Additional options for image generation
     * @return array Generated image data
     * @throws Exception if image generation fails
     */
    public function txt2img(string $prompt, array $options = []): array
    {
        $this->logger->info("Generating image from text", [
            'prompt' => $prompt,
            'options' => $options
        ]);

        try {
            $params = array_merge($this->config->get('replicate.models.txt2img.params'), [
                'prompt' => $prompt
            ], $options);

            $result = $this->httpClient->post('https://api.replicate.com/v1/predictions', [
                'version' => $this->config->get('replicate.models.txt2img.version'),
                'input' => $params
            ]);

            $this->logger->info("Image generation completed", [
                'result' => $result
            ]);

            return $result;
        } catch (Exception $e) {
            $this->logger->error("Failed to generate image", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Convert an image to cartoon style
     * @param string $image Image data (file path or base64)
     * @param array $options Additional options for cartoonification
     * @return array Cartoonified image data
     * @throws Exception if cartoonification fails
     */
    public function cartoonify(string $image, array $options = []): array
    {
        $this->logger->info("Cartoonifying image", [
            'image_length' => strlen($image),
            'options' => $options
        ]);

        try {
            // Convert local file path to public URL if needed
            if (strpos($image, '/') === 0) {  // If it's a local file path
                // Extract the part after /public/
                if (preg_match('/\/public\/(.*)$/', $image, $matches)) {
                    $publicPath = $matches[1];
                    // Get the base URL using the dedicated method
                    $baseUrl = $this->config->getBaseUrl();
                    $image = rtrim($baseUrl, '/') . '/public/' . $publicPath;

                    // Add debug logging
                    $this->logger->info("Constructed image URL", [
                        'original_path' => $matches[0],
                        'public_path' => $publicPath,
                        'base_url' => $baseUrl,
                        'final_url' => $image
                    ]);
                }
            }

            $params = array_merge($this->config->get('replicate.models.cartoonify.params'), [
                'image' => $image
            ], $options);

            // Get the webhook URL from config
            $webhookUrl = $this->config->getBaseUrl() . '/webhook.php';

            // Log webhook configuration
            $this->logger->info("Webhook configuration", [
                'base_url' => $this->config->getBaseUrl(),
                'webhook_url' => $webhookUrl,
                'webhook_secret' => !empty($this->config->get('replicate.webhook_secret')),
                'server_info' => [
                    'http_host' => $_SERVER['HTTP_HOST'] ?? 'not set',
                    'request_uri' => $_SERVER['REQUEST_URI'] ?? 'not set',
                    'https' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
                    'server_port' => $_SERVER['SERVER_PORT'] ?? 'not set'
                ]
            ]);

            $result = $this->httpClient->post('https://api.replicate.com/v1/predictions', [
                'version' => $this->config->get('replicate.models.cartoonify.version'),
                'input' => $params,
                'webhook' => $webhookUrl,
                'webhook_events_filter' => ['completed']  // Only receive webhook when prediction is complete
            ]);

            $this->logger->info("Image cartoonification initiated", [
                'result' => $result,
                'webhook_url' => $webhookUrl
            ]);

            return $result;
        } catch (Exception $e) {
            $this->logger->error("Failed to cartoonify image", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Generate an image with custom parameters
     * @param array $params Custom parameters for image generation
     * @return array Generated image data
     * @throws Exception if image generation fails
     */
    public function generateImage(array $params): array
    {
        $this->logger->info("Generating image", [
            'params' => $params
        ]);

        try {
            // Get the webhook URL from config
            $webhookUrl = $this->config->getBaseUrl() . '/webhook.php';

            // If we have a composed panel, convert it to a URL
            if (isset($params['composed_panel']) && file_exists($params['composed_panel'])) {
                $publicPath = str_replace($this->config->getOutputPath(), '', $params['composed_panel']);
                $baseUrl = $this->config->getBaseUrl();
                $params['composed_panel'] = rtrim($baseUrl, '/') . '/public/generated/' . ltrim($publicPath, '/');

                $this->logger->info("Converted composed panel path to URL", [
                    'original_path' => $params['composed_panel'],
                    'public_url' => $params['composed_panel']
                ]);
            }

            // Build enhanced prompt with style, background, and story context
            $style = $params['options']['style'] ?? 'modern';
            $basePrompt = $params['prompt'] ?? '';

            // Style-specific prompt enhancements
            $stylePrompts = [
                'manga' => 'high-quality manga art style, detailed manga shading, dynamic manga composition',
                'comic' => 'professional comic book art style, vibrant colors, dynamic comic book composition',
                'european' => 'European comic art style, ligne claire, detailed backgrounds, clear lines',
                'modern' => 'modern digital art style, high detail, professional illustration'
            ];

            // Build the final prompt
            $enhancedPrompt = "Create a {$style} comic panel: {$basePrompt}. ";
            $enhancedPrompt .= $stylePrompts[$style] ?? $stylePrompts['modern'];

            if (isset($params['composed_panel'])) {
                $enhancedPrompt .= " Use the provided character composition as reference for character placement and poses.";
            }

            // Add background context if available
            if (isset($params['background'])) {
                $enhancedPrompt .= " Set in {$params['background']}.";
            }

            // Update parameters with enhanced prompt and style-specific settings
            $modelParams = [
                'prompt' => $enhancedPrompt,
                'negative_prompt' => implode(', ', $this->config->getNegativePrompts()),
                'steps' => 30,
                'width' => 1024,
                'height' => 1024,
                'cfg_scale' => 7.5,
                'init_image' => $params['composed_panel'] ?? null,
                'prompt_strength' => isset($params['composed_panel']) ? 0.7 : 1.0,
                'cartoonified_images' => $params['cartoonified_images'] ?? []  // Add cartoonified images
            ];

            // Log the final parameters
            $this->logger->info("Final model parameters", [
                'prompt' => $enhancedPrompt,
                'style' => $style,
                'has_composition' => isset($params['composed_panel']),
                'cartoonified_images_count' => count($params['cartoonified_images'] ?? [])
            ]);

            // Make the API call
            $result = $this->httpClient->post('https://api.replicate.com/v1/predictions', [
                'version' => $this->config->get('replicate.models.txt2img.version'),
                'input' => $modelParams,
                'webhook' => $webhookUrl,
                'webhook_events_filter' => ['completed']  // Only receive webhook when prediction is complete
            ]);

            $this->logger->info("Image generation initiated", [
                'result' => $result,
                'webhook_url' => $webhookUrl
            ]);

            return $result;
        } catch (Exception $e) {
            $this->logger->error("Failed to generate image", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
}
