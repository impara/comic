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

        try {
            // Process each custom character
            $processedCharacters = [];
            $characterImages = [];
            $pendingCartoonifications = [];

            foreach ($characters as $character) {
                $this->logger->info("Processing character", [
                    'character_id' => $character['id'],
                    'has_image' => isset($character['image'])
                ]);

                // Skip if no image provided
                if (!isset($character['image'])) {
                    continue;
                }

                // If character is already cartoonified, use it directly
                if (isset($character['cartoonified_image'])) {
                    $processedCharacters[] = $character;
                    $characterImages[] = $character['cartoonified_image'];

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
                    // Start cartoonification process
                    $cartoonificationResult = $this->characterProcessor->processCharacter($character);

                    if (isset($cartoonificationResult['output'])) {
                        // If cartoonification completed synchronously
                        $character['cartoonified_image'] = $cartoonificationResult['output'];
                        $processedCharacters[] = $character;
                        $characterImages[] = $cartoonificationResult['output'];
                    } else {
                        // Handle async cartoonification
                        $pendingCartoonifications[] = $cartoonificationResult['id'];

                        // Create pending file for webhook processing
                        $pendingFile = $tempPath . "pending_{$cartoonificationResult['id']}.json";
                        file_put_contents($pendingFile, json_encode([
                            'prediction_id' => $cartoonificationResult['id'],
                            'original_prediction_id' => $originalPredictionId,
                            'character_id' => $character['id'],
                            'panel_data' => json_encode([
                                'characters' => [$character],
                                'scene_description' => $sceneDescription
                            ]),
                            'state_file' => basename($stateFile),
                            'started_at' => time()
                        ]));
                    }
                } catch (Exception $e) {
                    $this->logger->error("Failed to process character", [
                        'character_id' => $character['id'],
                        'error' => $e->getMessage()
                    ]);
                    throw $e;
                }
            }

            // If we have pending cartoonifications, return early with pending status
            if (!empty($pendingCartoonifications)) {
                return [
                    'id' => $originalPredictionId,
                    'status' => 'processing',
                    'pending_predictions' => $pendingCartoonifications
                ];
            }

            // Now validate that all characters have cartoonified images
            foreach ($processedCharacters as $character) {
                if (!isset($character['cartoonified_image'])) {
                    throw new Exception("Cannot generate panel: Missing cartoonified image for character " . ($character['id'] ?? 'unknown'));
                }
                $characterImages[] = $character['cartoonified_image'];
            }

            // Generate the final panel using SDXL with cartoonified images
            $result = $this->replicateClient->generateImage([
                'prompt' => $sceneDescription,
                'cartoonified_image' => $characterImages[0], // Currently handling one character
                'options' => [
                    'style' => $characters[0]['options']['style'] ?? 'default'
                ]
            ]);

            // Add original prediction ID to result
            $result['original_prediction_id'] = $originalPredictionId;

            // Update state file with completion
            $state = json_decode(file_get_contents($stateFile), true);
            $state['status'] = 'completed';
            $state['completed_at'] = time();
            $state['result'] = $result;
            file_put_contents($stateFile, json_encode($state));

            return $result;
        } catch (Exception $e) {
            $this->logger->error("Failed to generate panel", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
}
