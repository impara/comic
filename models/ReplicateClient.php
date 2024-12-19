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

            // If we have a cartoonified image, prepare it for processing
            if (isset($params['cartoonified_image'])) {
                if (file_exists($params['cartoonified_image'])) {
                    $publicPath = str_replace($this->config->getOutputPath(), '', $params['cartoonified_image']);
                    $baseUrl = $this->config->getBaseUrl();
                    $params['cartoonified_image'] = rtrim($baseUrl, '/') . '/public/generated/' . ltrim($publicPath, '/');

                    $this->logger->info("Converted cartoonified image path to URL", [
                        'original_path' => $params['cartoonified_image'],
                        'public_url' => $params['cartoonified_image']
                    ]);
                }
            }

            // Build enhanced prompt with style and context
            $style = $params['options']['style'] ?? 'modern';
            $basePrompt = $params['prompt'] ?? '';

            // Style-specific prompt enhancements with stronger pose emphasis
            $stylePrompts = [
                'manga' => 'manga style, detailed manga shading, action scene',
                'comic' => 'comic book style, vibrant colors, action scene',
                'european' => 'European comic style, clear lines, action scene',
                'modern' => 'modern digital art style, action scene'
            ];

            // Extract action keywords from the base prompt for better pose emphasis
            $actionKeywords = '';
            if (preg_match('/\b(running|jumping|fighting|flying|walking|dancing)\b/i', $basePrompt, $matches)) {
                $actionKeywords = $matches[0];
                // Enhance action description based on the type
                $actionEnhancements = [
                    'running' => 'running pose, dynamic action',
                    'jumping' => 'jumping pose, dynamic action',
                    'fighting' => 'fighting pose, dynamic action',
                    'flying' => 'flying pose, dynamic action',
                    'walking' => 'walking pose, dynamic action',
                    'dancing' => 'dancing pose, dynamic action'
                ];
                $actionKeywords = $actionEnhancements[strtolower($matches[0])] ?? "$matches[0] pose, dynamic action";
            }

            // Action-specific negative prompts
            $actionNegativePrompts = array_merge(
                $this->config->getNegativePrompts(),
                ['static pose', 'standing still', 'stiff', 'rigid']
            );

            // Build the final prompt with stronger emphasis on action
            $enhancedPrompt = trim($actionKeywords . ", " . ($stylePrompts[$style] ?? $stylePrompts['modern']));

            // Build the secondary prompt focusing purely on action
            $actionPrompt = $actionKeywords ? "$actionKeywords, dynamic movement" : "";

            // Prepare SDXL parameters according to version 39ed52f2
            $modelParams = [
                'prompt' => $enhancedPrompt,
                'negative_prompt' => implode(', ', $actionNegativePrompts),
                'num_inference_steps' => 75,
                'guidance_scale' => 15.0,
                'width' => 1024,
                'height' => 1024,
                'scheduler' => "DDIM"
            ];

            // Handle image processing based on input type
            if (isset($params['cartoonified_image'])) {
                // Use already cartoonified image for SDXL
                $modelParams['image'] = $params['cartoonified_image'];
                $modelParams['strength'] = 0.45;
                $modelParams['high_noise_frac'] = 0.9;

                if ($actionPrompt) {
                    $modelParams['prompt_2'] = $actionPrompt;
                    $modelParams['guidance_scale_2'] = 12.0;
                }

                // Make the SDXL API call
                $result = $this->httpClient->post('https://api.replicate.com/v1/predictions', [
                    'version' => $this->config->get('replicate.models.sdxl.version'),
                    'input' => $modelParams,
                    'webhook' => $webhookUrl,
                    'webhook_events_filter' => ['completed']
                ]);

                // Log the SDXL API call result
                $this->logger->error("TEST_LOG - SDXL API call result", [
                    'prediction_id' => $result['id'],
                    'original_panel_id' => $params['original_panel_id'] ?? null,
                    'webhook_url' => $webhookUrl,
                    'has_cartoonified_image' => isset($params['cartoonified_image'])
                ]);

                // Create state file for SDXL if original_panel_id is provided
                if (isset($params['original_panel_id'])) {
                    $stateFile = $this->config->getTempPath() . "state_{$params['original_panel_id']}.json";
                    if (file_exists($stateFile)) {
                        $state = json_decode(file_get_contents($stateFile), true);
                        $state['sdxl_prediction_id'] = $result['id'];
                        $state['sdxl_status'] = 'processing';
                        $state['sdxl_started_at'] = time();
                        file_put_contents($stateFile, json_encode($state));

                        $this->logger->error("TEST_LOG - Updated state file with SDXL start", [
                            'state_file' => basename($stateFile),
                            'prediction_id' => $result['id'],
                            'original_panel_id' => $params['original_panel_id']
                        ]);
                    }
                }

                return $result;
            } else if (isset($params['character_image'])) {
                // Start with cartoonification
                $cartoonifyResult = $this->cartoonify($params['character_image']);

                // Create a pending file for webhook to handle SDXL stage
                if (!isset($cartoonifyResult['output'])) {
                    $tempPath = $this->config->getTempPath();
                    $pendingFile = $tempPath . "pending_{$cartoonifyResult['id']}.json";

                    file_put_contents($pendingFile, json_encode([
                        'prediction_id' => $cartoonifyResult['id'],
                        'stage' => 'cartoonify',
                        'next_stage' => 'sdxl',
                        'sdxl_params' => [
                            'prompt' => $enhancedPrompt,
                            'action_prompt' => $actionPrompt,
                            'style' => $style,
                            'model_params' => $modelParams
                        ],
                        'original_prediction_id' => $params['original_prediction_id'] ?? null,
                        'started_at' => time()
                    ]));
                }

                return $cartoonifyResult;
            }

            // If no image provided, throw error
            throw new Exception("No image provided for processing");
        } catch (Exception $e) {
            $this->logger->error("Failed to generate image", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Make a prediction using any Replicate model
     * @param string $modelType The type of model to use (e.g., 'nlp', 'sdxl', etc.)
     * @param array $params Parameters for the model
     * @return array Prediction result
     * @throws RuntimeException if model type is not configured or prediction fails
     */
    public function predict(string $modelType, array $params): array
    {
        $this->logger->info("Making prediction with model", [
            'model_type' => $modelType,
            'params' => $params
        ]);

        try {
            // Get model configuration
            $modelConfig = $this->config->get("replicate.models.{$modelType}");
            if (!$modelConfig) {
                throw new RuntimeException("Model type '{$modelType}' not configured");
            }

            // Merge default parameters with provided ones
            $modelParams = array_merge($modelConfig['params'], $params);

            // Get the webhook URL from config
            $webhookUrl = $this->config->getBaseUrl() . '/webhook.php';

            // Make the API call
            $result = $this->httpClient->post('https://api.replicate.com/v1/predictions', [
                'version' => $modelConfig['version'],
                'input' => $modelParams,
                'webhook' => $webhookUrl,
                'webhook_events_filter' => ['completed']
            ]);

            $this->logger->info("Prediction initiated", [
                'model_type' => $modelType,
                'result' => $result
            ]);

            // For synchronous models (like NLP), wait for completion
            if ($modelType === 'nlp') {
                // Poll for results
                $maxAttempts = 30;
                $attempt = 0;
                while ($attempt < $maxAttempts) {
                    $status = $this->httpClient->get("https://api.replicate.com/v1/predictions/{$result['id']}");
                    if ($status['status'] === 'succeeded') {
                        return $status['output'];
                    } elseif ($status['status'] === 'failed') {
                        throw new RuntimeException("Prediction failed: " . ($status['error'] ?? 'Unknown error'));
                    }
                    $attempt++;
                    sleep(2);
                }
                throw new RuntimeException("Prediction timed out");
            }

            return $result;
        } catch (Exception $e) {
            $this->logger->error("Prediction failed", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
}
