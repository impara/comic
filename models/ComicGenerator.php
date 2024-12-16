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
        // Generate a prediction ID if not provided
        if (!$originalPredictionId) {
            $originalPredictionId = uniqid('panel_', true);
            $this->logger->error("TEST_LOG - Generated new prediction ID", [
                'original_prediction_id' => $originalPredictionId
            ]);
        }

        // Create state file immediately
        $tempPath = $this->config->getTempPath();
        $stateFile = $tempPath . "state_{$originalPredictionId}.json";
        $initialState = [
            'prediction_id' => $originalPredictionId,
            'started_at' => time(),
            'scene_description' => $sceneDescription,
            'character_count' => count($characters),
            'cartoonification_requests' => [],
            'status' => 'processing'
        ];
        file_put_contents($stateFile, json_encode($initialState));

        // Test log to confirm code execution
        $this->logger->error("TEST_LOG - generatePanel method started", [
            'time' => date('Y-m-d H:i:s'),
            'character_count' => count($characters),
            'original_prediction_id' => $originalPredictionId,
            'state_file' => basename($stateFile)
        ]);

        // Process each custom character
        $processedCharacters = [];
        $characterImages = [];
        $pendingCartoonifications = [];

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
                ],
                'original_prediction_id' => $originalPredictionId
            ]);

            if (!isset($character['image']) && !isset($character['cartoonified_image'])) {
                throw new Exception("Character image is required");
            }

            // If character already has a cartoonified image, use it directly
            if (isset($character['cartoonified_image'])) {
                $processedCharacters[] = $character;
                $characterImages[$index] = $character['cartoonified_image'];

                // Update state to reflect pre-cartoonified character
                $state = json_decode(file_get_contents($stateFile), true);
                $state['cartoonification_requests'][] = [
                    'character_id' => $character['id'],
                    'status' => 'succeeded',
                    'cartoonified_url' => $character['cartoonified_image'],
                    'started_at' => time(),
                    'completed_at' => time()
                ];
                file_put_contents($stateFile, json_encode($state));

                continue;
            }

            // Otherwise process the character for cartoonification
            try {
                // Create panel data for cartoonification
                $panelData = [
                    'characters' => [$character],
                    'scene_description' => $sceneDescription
                ];

                // Start cartoonification process
                $cartoonificationResult = $this->characterProcessor->processCharacter($character);

                if (isset($cartoonificationResult['prediction_id'])) {
                    // Update state with new cartoonification request
                    $state = json_decode(file_get_contents($stateFile), true);
                    $state['cartoonification_requests'][] = [
                        'prediction_id' => $cartoonificationResult['prediction_id'],
                        'character_id' => $character['id'],
                        'started_at' => time(),
                        'status' => 'pending'
                    ];
                    file_put_contents($stateFile, json_encode($state));

                    // Store pending data for webhook processing
                    $pendingFile = $tempPath . "pending_{$cartoonificationResult['prediction_id']}.json";
                    file_put_contents($pendingFile, json_encode([
                        'prediction_id' => $cartoonificationResult['prediction_id'],
                        'original_image' => $character['image'],
                        'character_data' => $character,
                        'panel_data' => json_encode($panelData),
                        'original_prediction_id' => $originalPredictionId,
                        'started_at' => time(),
                        'state_file' => basename($stateFile)
                    ]));

                    $pendingCartoonifications[] = $cartoonificationResult['prediction_id'];

                    $this->logger->error("TEST_LOG - Started cartoonification process", [
                        'character_id' => $character['id'],
                        'prediction_id' => $cartoonificationResult['prediction_id'],
                        'pending_file' => basename($pendingFile),
                        'state_file' => basename($stateFile),
                        'original_prediction_id' => $originalPredictionId
                    ]);
                }

                $processedCharacters[] = $cartoonificationResult;
            } catch (Exception $e) {
                // Update state with error
                $state = json_decode(file_get_contents($stateFile), true);
                $state['status'] = 'failed';
                $state['error'] = $e->getMessage();
                file_put_contents($stateFile, json_encode($state));

                $this->logger->error("Failed to process character for cartoonification", [
                    'character_id' => $character['id'],
                    'error' => $e->getMessage(),
                    'original_prediction_id' => $originalPredictionId
                ]);
                throw $e;
            }
        }

        // If there are pending cartoonifications, return early with status
        if (!empty($pendingCartoonifications)) {
            // Update state
            $state = json_decode(file_get_contents($stateFile), true);
            $state['status'] = 'waiting_for_cartoonification';
            $state['pending_cartoonifications'] = $pendingCartoonifications;
            file_put_contents($stateFile, json_encode($state));

            return [
                'status' => 'processing',
                'message' => 'Waiting for cartoonification to complete',
                'pending_predictions' => $pendingCartoonifications,
                'original_prediction_id' => $originalPredictionId
            ];
        }

        // Now validate that all characters have cartoonified images
        foreach ($processedCharacters as $character) {
            if (!isset($character['cartoonified_image'])) {
                $this->logger->error("Missing cartoonified image for character", [
                    'character_id' => $character['id'] ?? 'unknown',
                    'has_original' => isset($character['image'])
                ]);
                throw new Exception("Cannot generate panel: Missing cartoonified image for character " . ($character['id'] ?? 'unknown'));
            }
            $characterImages[] = $character['cartoonified_image'];
        }

        // Prepare scene context
        $sceneContext = [
            'style' => $characters[0]['options']['style'] ?? 'default'
        ];

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

        // Verify all cartoonified images are valid URLs
        $validCartoonifiedImages = array_filter($characterImages, function ($url) {
            return filter_var($url, FILTER_VALIDATE_URL) !== false;
        });

        if (count($validCartoonifiedImages) !== count($characterImages)) {
            $this->logger->error("Invalid cartoonified image URLs found", [
                'valid_count' => count($validCartoonifiedImages),
                'total_count' => count($characterImages),
                'invalid_urls' => array_diff($characterImages, $validCartoonifiedImages)
            ]);
            throw new Exception("Some cartoonified images have invalid URLs");
        }

        // Compose the panel using ImageComposer
        $imageComposer = new ImageComposer($this->logger);

        // Log right before calling composePanel
        $this->logger->info("VERIFICATION - Calling composePanel", [
            'character_images_count' => count($validCartoonifiedImages),
            'scene_context' => $sceneContext,
            'first_image_url' => reset($validCartoonifiedImages) ?: 'none'
        ]);

        $composedPanelPath = $imageComposer->composePanel($validCartoonifiedImages, $sceneContext);

        // Log after panel composition
        $this->logger->info("VERIFICATION - Panel composition completed", [
            'composed_panel_path' => $composedPanelPath,
            'exists' => file_exists($composedPanelPath),
            'size' => file_exists($composedPanelPath) ? filesize($composedPanelPath) : 0,
            'cartoonified_images_used' => $validCartoonifiedImages
        ]);

        // Generate the final panel with background and composition
        $result = $this->replicateClient->generateImage([
            'prompt' => $sceneDescription,
            'characters' => $processedCharacters,
            'composed_panel' => $composedPanelPath,
            'options' => [
                'style' => $sceneContext['style']
            ],
            'cartoonified_images' => $validCartoonifiedImages
        ]);

        // Add original prediction ID to result
        $result['original_prediction_id'] = $originalPredictionId;

        // If we have an original prediction ID, store the final result
        if ($originalPredictionId && isset($result['id'])) {
            $tempPath = $this->config->getTempPath();
            $resultFile = $tempPath . "{$originalPredictionId}.json";

            // Store the mapping between the new prediction and original
            $mappingFile = $tempPath . "mapping_{$result['id']}.json";
            file_put_contents($mappingFile, json_encode([
                'original_prediction_id' => $originalPredictionId,
                'panel_prediction_id' => $result['id'],
                'cartoonified_images' => $validCartoonifiedImages,
                'created_at' => date('c')
            ]));

            $this->logger->info("Stored prediction mapping", [
                'original_id' => $originalPredictionId,
                'panel_id' => $result['id'],
                'mapping_file' => $mappingFile,
                'cartoonified_images' => $validCartoonifiedImages
            ]);
        }

        $this->logger->info("Panel generation completed", [
            'result' => $result,
            'original_prediction_id' => $originalPredictionId,
            'cartoonified_images' => $validCartoonifiedImages
        ]);

        return $result;
    }
}
