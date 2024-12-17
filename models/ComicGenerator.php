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
     * @param string|null $originalPanelId Original prediction ID to update with final result
     * @return array Generated comic data
     */
    public function generatePanel(array $characters, string $sceneDescription, ?string $originalPanelId = null)
    {
        $this->logger->info("Generating panel", [
            'character_count' => count($characters),
            'scene_length' => strlen($sceneDescription),
            'original_panel_id' => $originalPanelId
        ]);

        try {
            // Always use the provided ID or generate a new one, but store it consistently
            $panelId = $originalPanelId ?: 'panel_' . uniqid('', true);

            // Store the original panel ID in a mapping file for reference
            $idMappingFile = $this->config->getTempPath() . "id_mapping_{$panelId}.json";
            file_put_contents($idMappingFile, json_encode([
                'panel_id' => $panelId,
                'created_at' => time(),
                'related_predictions' => []
            ]));

            // Create state file for tracking
            $stateFile = $this->config->getTempPath() . "state_{$panelId}.json";
            $initialState = [
                'id' => $panelId,
                'original_panel_id' => $panelId,
                'status' => 'initializing',
                'started_at' => time(),
                'cartoonification_requests' => [],
                'sdxl_status' => 'pending',
                'updated_at' => time()
            ];
            file_put_contents($stateFile, json_encode($initialState));

            $this->logger->error("TEST_LOG - Created initial state", [
                'panel_id' => $panelId,
                'state_file' => basename($stateFile),
                'initial_state' => $initialState
            ]);

            $pendingCartoonifications = [];
            $processedCharacters = [];

            foreach ($characters as $character) {
                // Process the character for cartoonification
                try {
                    // Start cartoonification process
                    $cartoonificationResult = $this->characterProcessor->processCharacter($character);

                    $this->logger->error("TEST_LOG - Cartoonification initiated", [
                        'character_id' => $character['id'],
                        'prediction_id' => $cartoonificationResult['id'],
                        'original_panel_id' => $panelId
                    ]);

                    if (isset($cartoonificationResult['output'])) {
                        // If cartoonification completed synchronously
                        $character['cartoonified_image'] = $cartoonificationResult['output'];
                        $processedCharacters[] = $character;

                        // Update state for sync completion
                        $state = json_decode(file_get_contents($stateFile), true);
                        $state['cartoonification_requests'][] = [
                            'prediction_id' => $cartoonificationResult['id'],
                            'character_id' => $character['id'],
                            'status' => 'succeeded',
                            'output' => $cartoonificationResult['output'],
                            'started_at' => time(),
                            'completed_at' => time(),
                            'original_panel_id' => $panelId
                        ];
                        file_put_contents($stateFile, json_encode($state));
                    } else {
                        // Handle async cartoonification
                        $pendingCartoonifications[] = $cartoonificationResult['id'];

                        // Create pending file for webhook processing
                        $pendingFile = $this->config->getTempPath() . "pending_{$cartoonificationResult['id']}.json";
                        file_put_contents($pendingFile, json_encode([
                            'prediction_id' => $cartoonificationResult['id'],
                            'original_panel_id' => $panelId,
                            'stage' => 'cartoonify',
                            'next_stage' => 'sdxl',
                            'character_id' => $character['id'],
                            'panel_data' => [
                                'characters' => [$character],
                                'scene_description' => $sceneDescription,
                                'original_panel_id' => $panelId
                            ],
                            'state_file' => basename($stateFile),
                            'started_at' => time()
                        ]));

                        $this->logger->error("TEST_LOG - Created prediction mapping with panel data", [
                            'prediction_id' => $cartoonificationResult['id'],
                            'character_id' => $character['id'],
                            'original_panel_id' => $panelId,
                            'pending_file' => basename($pendingFile),
                            'state_file' => basename($stateFile),
                            'has_scene_description' => isset($sceneDescription),
                            'has_character_style' => isset($character['options']['style'])
                        ]);

                        // Update state file with pending cartoonification
                        $state = json_decode(file_get_contents($stateFile), true);
                        if (!isset($state['cartoonification_requests'])) {
                            $state['cartoonification_requests'] = [];
                        }
                        $state['cartoonification_requests'][] = [
                            'prediction_id' => $cartoonificationResult['id'],
                            'character_id' => $character['id'],
                            'status' => 'pending',
                            'started_at' => time(),
                            'original_panel_id' => $panelId
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
