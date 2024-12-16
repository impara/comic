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
    public function generatePanel(array $characters, string $sceneDescription, ?string $originalPredictionId = null)
    {
        $this->logger->info("Generating panel", [
            'character_count' => count($characters),
            'scene_length' => strlen($sceneDescription),
            'original_prediction_id' => $originalPredictionId
        ]);

        try {
            // Create a unique ID for this panel if not provided
            $panelId = $originalPredictionId ?: 'panel_' . uniqid('', true);

            // Create state file for tracking
            $stateFile = $this->config->getTempPath() . "state_{$panelId}.json";
            file_put_contents($stateFile, json_encode([
                'id' => $panelId,
                'status' => 'initializing',
                'started_at' => time(),
                'cartoonification_requests' => []
            ]));

            $pendingCartoonifications = [];
            $processedCharacters = [];
            $characterImages = [];

            foreach ($characters as $character) {
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
                        $pendingFile = $this->config->getTempPath() . "pending_{$cartoonificationResult['id']}.json";
                        file_put_contents($pendingFile, json_encode([
                            'prediction_id' => $cartoonificationResult['id'],
                            'original_prediction_id' => $panelId,
                            'character_id' => $character['id'],
                            'panel_data' => json_encode([
                                'characters' => [$character],
                                'scene_description' => $sceneDescription
                            ]),
                            'state_file' => basename($stateFile),
                            'started_at' => time()
                        ]));

                        // Update state file with pending cartoonification
                        $state = json_decode(file_get_contents($stateFile), true);
                        $state['cartoonification_requests'][] = [
                            'prediction_id' => $cartoonificationResult['id'],
                            'character_id' => $character['id'],
                            'status' => 'pending',
                            'started_at' => time()
                        ];
                        file_put_contents($stateFile, json_encode($state));
                    }
                } catch (Exception $e) {
                    $this->logger->error("Failed to process character", [
                        'character_id' => $character['id'],
                        'error' => $e->getMessage()
                    ]);
                    throw $e;
                }
            }

            // Return result with pending predictions if any
            return [
                'id' => $panelId,
                'original_prediction_id' => $panelId,  // Always include original ID
                'status' => count($pendingCartoonifications) > 0 ? 'pending' : 'processing',
                'pending_predictions' => $pendingCartoonifications,
                'state_file' => basename($stateFile)
            ];
        } catch (Exception $e) {
            $this->logger->error("Failed to generate panel", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
}
