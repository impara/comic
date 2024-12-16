<?php

require_once __DIR__ . '/../interfaces/LoggerInterface.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/CharacterProcessor.php';
require_once __DIR__ . '/ReplicateClient.php';
require_once __DIR__ . '/ImageComposer.php';

class ComicGenerator
{
    private LoggerInterface $logger;
    private Config $config;
    private CharacterProcessor $characterProcessor;
    private ReplicateClient $replicateClient;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->config = Config::getInstance();
        $this->characterProcessor = new CharacterProcessor($logger);
        $this->replicateClient = new ReplicateClient($logger);
    }

    /**
     * Generate a comic panel with characters
     * @param array $characters Array of character data
     * @param string $sceneDescription Description of the scene
     * @param string|null $originalPredictionId Original prediction ID to update with final result
     * @return array Generated comic data
     */
    public function generatePanel(array $characters, string $sceneDescription, ?string $originalPredictionId = null): array
    {
        // Test log to confirm code execution
        $this->logger->error("TEST_LOG - generatePanel method started", [
            'time' => date('Y-m-d H:i:s'),
            'character_count' => count($characters)
        ]);

        // Unconditional logging of input parameters
        $this->logger->error("TEST_LOG - Input parameters", [
            'character_count' => count($characters),
            'description_length' => strlen($sceneDescription),
            'original_prediction_id' => $originalPredictionId,
            'first_character' => isset($characters[0]) ? [
                'id' => $characters[0]['id'] ?? 'unknown',
                'has_image' => isset($characters[0]['image']),
                'has_cartoonified' => isset($characters[0]['cartoonified_image'])
            ] : null
        ]);

        try {
            // Process each custom character
            $processedCharacters = [];
            $characterImages = [];
            $pendingCartoonifications = [];

            // Unconditional logging of initial character state
            $this->logger->error("TEST_LOG - Raw character data", [
                'characters' => array_map(function ($char) {
                    return [
                        'id' => $char['id'] ?? 'unknown',
                        'has_image' => isset($char['image']),
                        'has_cartoonified' => isset($char['cartoonified_image']),
                        'image_url' => $char['image'] ?? null,
                        'cartoonified_url' => $char['cartoonified_image'] ?? null,
                        'all_keys' => array_keys($char)
                    ];
                }, $characters)
            ]);

            foreach ($characters as $index => $character) {
                // Log every character being processed
                $this->logger->error("TEST_LOG - Processing character", [
                    'index' => $index,
                    'character' => [
                        'id' => $character['id'] ?? 'unknown',
                        'has_image' => isset($character['image']),
                        'has_cartoonified' => isset($character['cartoonified_image']),
                        'image_url' => $character['image'] ?? null,
                        'cartoonified_url' => $character['cartoonified_image'] ?? null
                    ]
                ]);

                if (!isset($character['image']) && !isset($character['cartoonified_image'])) {
                    throw new Exception("Character image is required");
                }

                // If character already has a cartoonified image, use it directly
                if (isset($character['cartoonified_image'])) {
                    $processedCharacters[] = $character;
                    $characterImages[$index] = $character['cartoonified_image'];
                    $this->logger->error("TEST_LOG - Using existing cartoonified image", [
                        'index' => $index,
                        'id' => $character['id'] ?? 'unknown',
                        'cartoonified_url' => $character['cartoonified_image']
                    ]);
                    continue;
                }

                // Otherwise process the character
                $processedCharacter = $this->characterProcessor->processCharacter($character);
                $processedCharacters[] = $processedCharacter;

                // Log processed character result
                $this->logger->error("TEST_LOG - Character processed", [
                    'index' => $index,
                    'id' => $processedCharacter['id'] ?? 'unknown',
                    'has_prediction_id' => isset($processedCharacter['prediction_id']),
                    'prediction_id' => $processedCharacter['prediction_id'] ?? null,
                    'has_cartoonified' => isset($processedCharacter['cartoonified_image']),
                    'cartoonified_url' => $processedCharacter['cartoonified_image'] ?? null
                ]);

                if (isset($processedCharacter['prediction_id'])) {
                    $pendingCartoonifications[] = $processedCharacter['prediction_id'];
                } else {
                    $characterImages[$index] = $processedCharacter['cartoonified_image'];
                }
            }

            // Log arrays before composition
            $this->logger->error("TEST_LOG - Arrays before composition", [
                'processed_characters' => array_map(function ($char) {
                    return [
                        'id' => $char['id'] ?? 'unknown',
                        'has_cartoonified' => isset($char['cartoonified_image']),
                        'cartoonified_url' => $char['cartoonified_image'] ?? null
                    ];
                }, $processedCharacters),
                'character_images' => $characterImages,
                'pending_cartoonifications' => $pendingCartoonifications
            ]);

            // Log the final arrays before composition
            $this->logger->info("DEBUG_VERIFY - Final arrays before composition", [
                'processed_characters' => array_map(function ($char) {
                    return [
                        'id' => $char['id'] ?? 'unknown',
                        'has_cartoonified' => isset($char['cartoonified_image']),
                        'cartoonified_url' => $char['cartoonified_image'] ?? null
                    ];
                }, $processedCharacters),
                'character_images' => array_map(function ($url, $index) {
                    return [
                        'index' => $index,
                        'url' => $url,
                        'is_replicate_url' => strpos($url, 'replicate.delivery') !== false
                    ];
                }, $characterImages, array_keys($characterImages)),
                'pending_cartoonifications' => $pendingCartoonifications
            ]);

            // If there are pending cartoonifications, return early with status
            if (!empty($pendingCartoonifications)) {
                return [
                    'status' => 'processing',
                    'message' => 'Waiting for cartoonification to complete',
                    'pending_predictions' => $pendingCartoonifications
                ];
            }

            // Prepare scene context
            $sceneContext = [
                'style' => $characters[0]['options']['style'] ?? 'modern'
            ];

            // Always log the full state of characters and images before composition
            $this->logger->info("VERIFICATION - Full character state before composition", [
                'characters' => array_map(function ($char) {
                    return [
                        'id' => $char['id'] ?? 'unknown',
                        'name' => $char['name'] ?? 'unknown',
                        'has_image' => isset($char['image']),
                        'image_url' => $char['image'] ?? null,
                        'has_cartoonified' => isset($char['cartoonified_image']),
                        'cartoonified_url' => $char['cartoonified_image'] ?? null,
                        'has_prediction_id' => isset($char['prediction_id']),
                        'prediction_id' => $char['prediction_id'] ?? null
                    ];
                }, $characters)
            ]);

            // Log the exact state of characterImages array
            $this->logger->info("VERIFICATION - Character images being sent to panel composition", [
                'character_count' => count($characterImages),
                'character_images' => array_map(function ($url, $index) {
                    return [
                        'index' => $index,
                        'url' => $url,
                        'is_replicate_url' => strpos($url, 'replicate.delivery') !== false
                    ];
                }, $characterImages, array_keys($characterImages))
            ]);

            // Compose the panel using ImageComposer
            $imageComposer = new ImageComposer($this->logger);

            // Log right before calling composePanel
            $this->logger->info("VERIFICATION - Calling composePanel", [
                'character_images_count' => count($characterImages),
                'scene_context' => $sceneContext,
                'first_image_url' => reset($characterImages) ?: 'none'
            ]);

            $composedPanelPath = $imageComposer->composePanel($characterImages, $sceneContext);

            // Log after panel composition
            $this->logger->info("VERIFICATION - Panel composition completed", [
                'composed_panel_path' => $composedPanelPath,
                'exists' => file_exists($composedPanelPath),
                'size' => file_exists($composedPanelPath) ? filesize($composedPanelPath) : 0
            ]);

            // Generate the final panel with background and composition
            $result = $this->replicateClient->generateImage([
                'prompt' => $sceneDescription,
                'characters' => $processedCharacters,
                'composed_panel' => $composedPanelPath,
                'options' => [
                    'style' => $sceneContext['style']
                ]
            ]);

            // If we have an original prediction ID, store the final result
            if ($originalPredictionId && isset($result['id'])) {
                $tempPath = $this->config->getTempPath();
                $resultFile = $tempPath . "{$originalPredictionId}.json";

                // Store the mapping between the new prediction and original
                $mappingFile = $tempPath . "mapping_{$result['id']}.json";
                file_put_contents($mappingFile, json_encode([
                    'original_prediction_id' => $originalPredictionId,
                    'panel_prediction_id' => $result['id'],
                    'created_at' => date('c')
                ]));

                $this->logger->info("Stored prediction mapping", [
                    'original_id' => $originalPredictionId,
                    'panel_id' => $result['id'],
                    'mapping_file' => $mappingFile
                ]);
            }

            $this->logger->info("Panel generation completed", [
                'result' => $result,
                'original_prediction_id' => $originalPredictionId
            ]);

            return $result;
        } catch (Exception $e) {
            $this->logger->error("Panel generation failed", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'original_prediction_id' => $originalPredictionId
            ]);
            throw $e;
        }
    }
}
