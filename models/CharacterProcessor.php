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
     * Process a batch of characters
     * @param array $characters Array of character data
     * @param array $options Processing options
     * @return array Processed character data
     */
    public function processCharacters(array $characters, array $options = []): array
    {
        $this->logger->info("Processing batch of characters", [
            'count' => count($characters),
            'options' => $options
        ]);

        try {
            $results = [];
            foreach ($characters as $character) {
                try {
                    // Skip processing if already a Replicate URL
                    if (strpos($character['image'], 'replicate.delivery') !== false) {
                        $character['cartoonified_image'] = $character['image'];
                        $results[$character['id']] = $character;
                        continue;
                    }

                    // Prepare character-specific options
                    $charOptions = array_merge(
                        $options,
                        $character['options'] ?? [],
                        [
                            'character_id' => $character['id'],
                            'panel_index' => $options['panel_index'] ?? 0
                        ]
                    );

                    // Process the character image
                    $result = $this->cartoonifyCharacter($character['image'], $charOptions);

                    // Store cartoonified image URL in character data
                    $character['cartoonified_image'] = $result['output'] ?? null;
                    if (!$character['cartoonified_image']) {
                        throw new Exception("Cartoonification failed - no output URL");
                    }

                    $results[$character['id']] = $character;
                } catch (Exception $e) {
                    $this->logger->error("Character processing failed", [
                        'character_id' => $character['id'],
                        'error' => $e->getMessage()
                    ]);
                    throw $e;
                }
            }

            return $results;
        } catch (Exception $e) {
            $this->logger->error("Batch character processing failed", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Convert a character image to cartoon style
     * @param string $imageData Image data (URL, base64, or file path)
     * @param array $options Processing options
     * @return array Processing result
     */
    private function cartoonifyCharacter(string $imageData, array $options = []): array
    {
        $this->logger->info("Cartoonifying character", [
            'image_length' => strlen($imageData),
            'options' => $options
        ]);

        try {
            // If image is already in public/generated, treat it as cartoonified
            if (strpos($imageData, '/public/generated/') !== false) {
                $this->logger->info("Using pre-cartoonified image", [
                    'image_path' => $imageData
                ]);
                return [
                    'status' => 'succeeded',
                    'output' => $imageData,
                    'id' => uniqid('local_'),
                    'character_id' => $options['character_id'] ?? null
                ];
            }

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
                'result' => $result,
                'character_id' => $options['character_id'] ?? null
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
}
