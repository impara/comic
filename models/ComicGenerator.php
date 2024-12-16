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
        $this->logger->info("Starting comic panel generation", [
            'character_count' => count($characters),
            'description_length' => strlen($sceneDescription),
            'original_prediction_id' => $originalPredictionId
        ]);

        try {
            // Process each custom character
            $processedCharacters = [];
            $characterImages = [];
            $pendingCartoonifications = [];
            foreach ($characters as $index => $character) {
                if (!isset($character['image']) && !isset($character['cartoonified_image'])) {
                    throw new Exception("Character image is required");
                }

                // If character already has a cartoonified image, use it directly
                if (isset($character['cartoonified_image'])) {
                    $processedCharacters[] = $character;
                    $characterImages[$index] = $character['cartoonified_image'];
                    $this->logger->info("Using existing cartoonified image", [
                        'character_index' => $index,
                        'cartoonified_url' => $character['cartoonified_image']
                    ]);
                    continue;
                }

                // Otherwise process the character
                $processedCharacter = $this->characterProcessor->processCharacter($character);
                $processedCharacters[] = $processedCharacter;

                // If character has a prediction_id, it means cartoonification is pending
                if (isset($processedCharacter['prediction_id'])) {
                    $pendingCartoonifications[] = $processedCharacter['prediction_id'];
                    $this->logger->info("Cartoonification pending", [
                        'character_index' => $index,
                        'prediction_id' => $processedCharacter['prediction_id']
                    ]);
                } else {
                    // Use cartoonified image for composition
                    $characterImages[$index] = $processedCharacter['cartoonified_image'];
                    $this->logger->info("Using newly processed cartoonified image", [
                        'character_index' => $index,
                        'cartoonified_url' => $processedCharacter['cartoonified_image']
                    ]);
                }
            }

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

            // Compose the panel using ImageComposer
            $imageComposer = new ImageComposer($this->logger);
            $composedPanelPath = $imageComposer->composePanel($characterImages, $sceneContext);

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
