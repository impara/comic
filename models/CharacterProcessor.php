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
     * Process multiple characters in a batch for a single panel
     * @param array $characters Array of character data
     * @param array $options Panel-specific options
     * @return array Array of processed character data
     */
    public function processCharacters(array $characters, array $options = []): array
    {
        $this->logger->info("Processing characters for panel", [
            'character_count' => count($characters),
            'options' => $options
        ]);

        try {
            // Validate input
            if (empty($characters)) {
                throw new Exception("At least one character is required");
            }

            // Process all characters in a batch
            $results = [];
            foreach ($characters as $character) {
                try {
                    if (!isset($character['image'])) {
                        throw new Exception("Character image is required");
                    }

                    if (!isset($character['id'])) {
                        throw new Exception("Character ID is required");
                    }

                    // Skip processing if already a Replicate URL
                    if (strpos($character['image'], 'replicate.delivery') !== false) {
                        $results[$character['id']] = [
                            'id' => $character['id'],
                            'output' => $character['image'],
                            'status' => 'succeeded',
                            'character_id' => $character['id']
                        ];
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
                    $result['character_id'] = $character['id'];
                    $results[$character['id']] = $result;
                } catch (Exception $e) {
                    $this->logger->error("Character processing failed", [
                        'character_id' => $character['id'],
                        'error' => $e->getMessage()
                    ]);
                    $results[$character['id']] = [
                        'id' => $character['id'],
                        'status' => 'failed',
                        'error' => $e->getMessage()
                    ];
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
