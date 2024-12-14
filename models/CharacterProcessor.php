<?php

require_once __DIR__ . '/../interfaces/LoggerInterface.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/ReplicateClient.php';
require_once __DIR__ . '/PromptBuilder.php';
require_once __DIR__ . '/ImageComposer.php';
require_once __DIR__ . '/FileManager.php';

class CharacterProcessor
{
    private LoggerInterface $logger;
    private ReplicateClient $replicateClient;
    private PromptBuilder $promptBuilder;
    private ImageComposer $imageComposer;
    private Config $config;
    private FileManager $fileManager;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->config = Config::getInstance();
        $this->replicateClient = new ReplicateClient($logger);
        $this->promptBuilder = new PromptBuilder();
        $this->imageComposer = new ImageComposer($logger);
        $this->fileManager = FileManager::getInstance($logger);
    }

    /**
     * Generate a character image from text description
     * @param string $characterDescription Character description
     * @param array|string $options Additional options or scene description
     * @return array Generated image data
     */
    public function generateCharacter(string $characterDescription, $options = ''): array
    {
        if (is_string($options)) {
            // Legacy mode - options is scene description
            $prompt = $this->promptBuilder->buildCharacterPrompt($characterDescription, $options);
            $this->logger->debug("Generating character", [
                'character' => $characterDescription,
                'scene' => $options
            ]);
        } else {
            // New mode - options is array of parameters
            $prompt = $characterDescription; // For txt2img, use prompt directly
            $this->logger->debug("Generating image with txt2img", [
                'prompt' => $prompt,
                'options' => $options
            ]);
        }

        // Add style-specific parameters if provided
        $params = [];
        if (is_array($options) && isset($options['style'])) {
            switch (strtolower($options['style'])) {
                case 'manga':
                case 'anime':
                    $params['style'] = 'anime';
                    break;
                case 'comic':
                default:
                    $params['style'] = 'comic';
                    break;
            }
        }

        // Generate the image directly
        return $this->replicateClient->txt2img($prompt, $params);
    }

    /**
     * Convert a custom uploaded character image to cartoon style
     * @param string $imageData Base64 encoded image or URL
     * @param array $options Additional options like art style
     * @return array Generated image data
     */
    public function cartoonifyCharacter(string $imageData, array $options = []): array
    {
        $this->logger->info("Cartoonifying character", [
            'image_length' => strlen($imageData),
            'options' => $options
        ]);

        try {
            // If image is a URL, download it first
            if (filter_var($imageData, FILTER_VALIDATE_URL)) {
                $imageData = $this->fileManager->saveImageFromUrl($imageData, 'character');
            }
            // If image is base64, save it to a file
            elseif (strpos($imageData, 'data:image') === 0) {
                $imageData = $this->fileManager->saveBase64Image($imageData, 'character');
            }

            // Call Replicate API to cartoonify
            $result = $this->replicateClient->cartoonify($imageData, $options);

            $this->logger->info("Character cartoonified successfully", [
                'result' => $result
            ]);

            return $result;
        } catch (Exception $e) {
            $this->logger->error("Failed to cartoonify character", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Process a character based on its type
     * @param array $character Character data
     * @return array Generated image data
     */
    public function processCharacter(array $character): array
    {
        $this->logger->info("Processing character", ['character' => $character]);

        try {
            if (!isset($character['image'])) {
                throw new Exception("Character image is required");
            }

            // Process the uploaded image
            $processedImage = $this->processImage($character['image']);

            return [
                'id' => $character['id'],
                'name' => $character['name'],
                'description' => $character['description'],
                'image' => $processedImage,
                'options' => $character['options'] ?? []
            ];
        } catch (Exception $e) {
            $this->logger->error("Character processing failed", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Process a character image through the cartoonification model
     * @param string $imageData Base64 or URL of the image
     * @return string Processed image data
     */
    private function processImage(string $imageData): string
    {
        try {
            // If the image is already cartoonified (from webhook), return it directly
            if (strpos($imageData, '/public/generated/') !== false) {
                return $imageData;
            }

            $this->logger->info("Cartoonifying image", [
                'image_length' => strlen($imageData),
                'options' => []
            ]);

            // Save the image first
            $savedImagePath = '';
            if (filter_var($imageData, FILTER_VALIDATE_URL)) {
                $savedImagePath = $this->fileManager->saveImageFromUrl($imageData, 'character');
            } elseif (preg_match('/^data:image\/(\w+);base64,/', $imageData)) {
                $savedImagePath = $this->fileManager->saveBase64Image($imageData, 'character');
            } else {
                throw new Exception("Invalid image data format");
            }

            // Convert saved path to URL
            $baseUrl = $this->config->getBaseUrl();
            $publicPath = str_replace('/var/www/comic.amertech.online/public/', '', $savedImagePath);
            $finalUrl = $baseUrl . '/public/' . $publicPath;

            $this->logger->info("Constructed image URL", [
                'original_path' => $savedImagePath,
                'public_path' => $publicPath,
                'base_url' => $baseUrl,
                'final_url' => $finalUrl
            ]);

            // Process through cartoonification
            $result = $this->replicateClient->cartoonify($finalUrl);

            if (!isset($result['output'])) {
                throw new Exception("Failed to process character image");
            }

            return $result['output'];
        } catch (Exception $e) {
            $this->logger->error("Image processing failed", [
                'error' => $e->getMessage()
            ]);
            throw new Exception("Failed to process character image");
        }
    }
}
