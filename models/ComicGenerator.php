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
            // Always use the provided ID or generate a new one, but store it consistently
            $panelId = $originalPredictionId ?: 'panel_' . uniqid('', true);

            // Store the original panel ID in a mapping file for reference
            $idMappingFile = $this->config->getTempPath() . "id_mapping_{$panelId}.json";
            file_put_contents($idMappingFile, json_encode([
                'panel_id' => $panelId,
                'created_at' => time(),
                'related_predictions' => []
            ]));

            // Create state file for tracking
            $stateFile = $this->config->getTempPath() . "state_{$panelId}.json";
            file_put_contents($stateFile, json_encode([
                'id' => $panelId,
                'original_panel_id' => $panelId, // Always store the original ID
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
                        'completed_at' => time(),
                        'original_panel_id' => $panelId // Add original panel ID reference
                    ];
                    file_put_contents($stateFile, json_encode($state));

                    continue;
                }

                // Process the character for cartoonification
                try {
                    // Start cartoonification process
                    $cartoonificationResult = $this->characterProcessor->processCharacter($character);

                    // Update ID mapping with new prediction
                    $idMapping = json_decode(file_get_contents($idMappingFile), true);
                    $idMapping['related_predictions'][] = [
                        'prediction_id' => $cartoonificationResult['id'],
                        'type' => 'cartoonification',
                        'character_id' => $character['id'],
                        'created_at' => time()
                    ];
                    file_put_contents($idMappingFile, json_encode($idMapping));

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
                            'original_panel_id' => $panelId, // Always use original panel ID
                            'character_id' => $character['id'],
                            'panel_data' => json_encode([
                                'characters' => [$character],
                                'scene_description' => $sceneDescription,
                                'original_panel_id' => $panelId // Include in panel data
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
                            'started_at' => time(),
                            'original_panel_id' => $panelId // Add original panel ID reference
                        ];
                        file_put_contents($stateFile, json_encode($state));
                    }
                } catch (Exception $e) {
                    $this->logger->error("Failed to process character", [
                        'character_id' => $character['id'],
                        'original_panel_id' => $panelId,
                        'error' => $e->getMessage()
                    ]);
                    throw $e;
                }
            }

            // Return result with consistent ID usage
            return [
                'id' => $panelId,
                'original_panel_id' => $panelId,
                'status' => count($pendingCartoonifications) > 0 ? 'pending' : 'processing',
                'pending_predictions' => $pendingCartoonifications,
                'state_file' => basename($stateFile)
            ];
        } catch (Exception $e) {
            $this->logger->error("Failed to generate panel", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'original_panel_id' => $panelId ?? null
            ]);
            throw $e;
        }
    }
}
