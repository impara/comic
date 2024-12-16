<?php

require_once __DIR__ . '/../interfaces/LoggerInterface.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/ReplicateClient.php';
require_once __DIR__ . '/ImageComposer.php';
require_once __DIR__ . '/FileManager.php';

class CharacterProcessor
{
    private LoggerInterface $logger;
    private ReplicateClient $replicateClient;
    private ImageComposer $imageComposer;
    private Config $config;
    private FileManager $fileManager;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->config = Config::getInstance();
        $this->replicateClient = new ReplicateClient($logger);
        $this->imageComposer = new ImageComposer($logger);
        $this->fileManager = FileManager::getInstance($logger);
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

            // Skip cartoonification if the image is already a Replicate URL
            if (strpos($character['image'], 'replicate.delivery') !== false) {
                return [
                    'id' => $character['id'],
                    'name' => $character['name'],
                    'description' => $character['description'],
                    'image' => $character['image'],
                    'cartoonified_image' => $character['image'],
                    'options' => $character['options'] ?? []
                ];
            }

            // Start cartoonification
            $result = $this->cartoonifyCharacter($character['image'], $character['options'] ?? []);
            $predictionId = $result['id'];

            // Create a pending file to track this cartoonification
            $tempPath = $this->config->getTempPath();
            $pendingFile = $tempPath . "pending_{$predictionId}.json";

            $panelData = [
                'characters' => [$character],
                'scene_description' => $character['scene_description'] ?? ''
            ];

            $pendingData = [
                'prediction_id' => $predictionId,
                'original_image' => $character['image'],
                'character_data' => $character,
                'panel_data' => json_encode($panelData),
                'started_at' => time()
            ];

            $this->logger->error("TEST_LOG - Creating pending file", [
                'file' => basename($pendingFile),
                'prediction_id' => $predictionId,
                'has_scene_description' => isset($character['scene_description']),
                'panel_data' => $panelData,
                'pending_data' => $pendingData
            ]);

            file_put_contents($pendingFile, json_encode($pendingData));

            // Return immediately with the prediction ID
            return array_merge($character, [
                'cartoonified_image' => null,
                'prediction_id' => $predictionId
            ]);
        } catch (Exception $e) {
            $this->logger->error("Character processing failed", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
}
