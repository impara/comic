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
     * @return array Generated comic data
     */
    public function generatePanel(array $characters, string $sceneDescription): array
    {
        $this->logger->info("Starting comic panel generation", [
            'character_count' => count($characters),
            'description_length' => strlen($sceneDescription)
        ]);

        try {
            // Process each custom character
            $processedCharacters = [];
            $characterImages = [];
            $pendingCartoonifications = [];
            foreach ($characters as $index => $character) {
                if (!isset($character['image'])) {
                    throw new Exception("Character image is required");
                }
                $processedCharacter = $this->characterProcessor->processCharacter($character);
                $processedCharacters[] = $processedCharacter;

                // If character has a prediction_id, it means cartoonification is pending
                if (isset($processedCharacter['prediction_id'])) {
                    $pendingCartoonifications[] = $processedCharacter['prediction_id'];
                } else {
                    // Use cartoonified image for composition
                    $characterImages[$index] = $processedCharacter['cartoonified_image'];
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

            $this->logger->info("Panel generation completed", [
                'result' => $result
            ]);

            return $result;
        } catch (Exception $e) {
            $this->logger->error("Panel generation failed", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
}
