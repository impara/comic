<?php

require_once __DIR__ . '/../interfaces/LoggerInterface.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/CharacterProcessor.php';
require_once __DIR__ . '/ReplicateClient.php';
require_once __DIR__ . '/ImageComposer.php';
require_once __DIR__ . '/StoryParser.php';
require_once __DIR__ . '/FileManager.php';
require_once __DIR__ . '/StateManager.php';

class ComicGenerator
{
    private $stateManager;
    private $logger;
    private $config;
    private $imageComposer;
    private $characterProcessor;
    private $storyParser;

    public function __construct(
        StateManager $stateManager,
        Logger $logger,
        Config $config,
        ImageComposer $imageComposer,
        CharacterProcessor $characterProcessor,
        StoryParser $storyParser
    ) {
        $this->stateManager = $stateManager;
        $this->logger = $logger;
        $this->config = $config;
        $this->imageComposer = $imageComposer;
        $this->characterProcessor = $characterProcessor;
        $this->storyParser = $storyParser;
    }

    /**
     * Generate a complete comic strip from a story
     */
    public function generateComicStrip(string $story, array $characters, array $options = []): array
    {
        try {
            // Initialize strip ID and state
            $stripId = uniqid('strip_');
            $this->logger->info('Starting comic generation', [
                'strip_id' => $stripId,
                'story_length' => strlen($story),
                'character_count' => count($characters)
            ]);

            // Initialize strip state
            $this->stateManager->initializeStrip($stripId, array_merge($options, [
                'story' => $story
            ]));

            // Update to characters pending state
            $this->stateManager->updateStripState($stripId, [
                'status' => StateManager::STATE_CHARACTERS_PENDING
            ]);

            // Process characters
            $processedCharacters = $this->characterProcessor->processCharacters($characters, $stripId);

            // Update state with processed characters
            $this->stateManager->updateStripState($stripId, [
                'characters' => $processedCharacters,
                'status' => StateManager::STATE_CHARACTERS_PROCESSING
            ]);

            // Return success response with strip ID
            return [
                'success' => true,
                'data' => [
                    'id' => $stripId,
                    'status' => StateManager::STATE_CHARACTERS_PROCESSING,
                    'message' => 'Comic generation started',
                    'characters' => $processedCharacters
                ]
            ];
        } catch (Exception $e) {
            $this->logger->error('Comic generation failed', [
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Check if all characters are ready for panel generation
     */
    private function areCharactersReady(array $characters): bool
    {
        foreach ($characters as $character) {
            if ($character['status'] !== 'completed') {
                return false;
            }
        }
        return true;
    }

    /**
     * Start panel generation after characters are ready
     */
    public function startPanelGeneration(string $stripId): void
    {
        try {
            $this->logger->info('Starting panel generation', [
                'strip_id' => $stripId,
                'timestamp' => date('Y-m-d H:i:s')
            ]);

            // Get strip state
            $stripState = $this->stateManager->getStripState($stripId);
            if (!$stripState) {
                throw new Exception('Strip state not found');
            }

            $this->logger->debug('Retrieved strip state', [
                'strip_id' => $stripId,
                'status' => $stripState['status'],
                'has_characters' => isset($stripState['characters']),
                'character_count' => count($stripState['characters'] ?? []),
                'current_state' => $stripState
            ]);

            // Get story and options from state
            $story = $stripState['options']['story'] ?? null;
            $options = $stripState['options'] ?? [];

            if (!$story) {
                throw new Exception('Story not found in strip options');
            }

            $this->logger->debug('Starting story segmentation', [
                'strip_id' => $stripId,
                'story_length' => strlen($story),
                'story_content' => $story,
                'options' => $options
            ]);

            // Segment story into panels
            $segments = $this->storyParser->segmentStory($story, [
                'style' => $options['style'] ?? 'default',
                'panel_count' => $options['panel_count'] ?? 4,
                'characters' => $stripState['characters'] ?? []
            ]);

            $this->logger->debug('Story segmented into panels', [
                'strip_id' => $stripId,
                'segment_count' => count($segments),
                'segments' => array_map(fn($s, $i) => [
                    'index' => $i,
                    'length' => strlen($s),
                    'content' => $s
                ], $segments, array_keys($segments))
            ]);

            // Process panel descriptions
            $segments = $this->storyParser->processPanelDescriptions($segments, [
                'style' => $options['style'] ?? 'default'
            ]);

            $this->logger->info('Story segmented into panels', [
                'strip_id' => $stripId,
                'panel_count' => count($segments),
                'segments' => array_map(fn($s, $i) => [
                    'index' => $i,
                    'length' => strlen($s),
                    'content' => $s
                ], $segments, array_keys($segments))
            ]);

            // Update state to panels generating
            $this->stateManager->updateStripState($stripId, [
                'status' => StateManager::STATE_PANELS_GENERATING,
                'total_panels' => count($segments)
            ]);

            // Initialize and generate each panel
            $generatedPanels = [];
            foreach ($segments as $index => $segment) {
                $panelId = 'panel_' . uniqid();

                $this->logger->debug('Initializing panel', [
                    'strip_id' => $stripId,
                    'panel_id' => $panelId,
                    'index' => $index,
                    'segment_length' => strlen($segment),
                    'segment_content' => $segment
                ]);

                // Initialize panel
                $this->stateManager->initializePanel($stripId, $panelId, $segment, [
                    'panel_index' => $index,
                    'style' => $options['style'] ?? 'default',
                    'characters' => $stripState['characters'] ?? []
                ]);

                // Generate panel
                try {
                    $generatedPanel = $this->generatePanel(
                        $stripState['characters'],
                        $segment,
                        $panelId,
                        array_merge($options, ['panel_index' => $index])
                    );
                    $generatedPanels[] = $generatedPanel;

                    $this->logger->info('Panel generation initiated', [
                        'strip_id' => $stripId,
                        'panel_id' => $panelId,
                        'index' => $index,
                        'status' => $generatedPanel['status'],
                        'prediction_id' => $generatedPanel['prediction_id'] ?? null
                    ]);
                } catch (Exception $e) {
                    $this->logger->error('Failed to generate panel', [
                        'strip_id' => $stripId,
                        'panel_id' => $panelId,
                        'index' => $index,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    throw $e;
                }
            }

            if (empty($generatedPanels)) {
                throw new Exception('No panels were generated successfully');
            }

            $this->logger->debug('All panels initialized', [
                'strip_id' => $stripId,
                'panel_count' => count($generatedPanels),
                'panels' => array_map(fn($p) => [
                    'id' => $p['id'],
                    'status' => $p['status'],
                    'prediction_id' => $p['prediction_id'] ?? null
                ], $generatedPanels)
            ]);

            // Update state with panels
            $this->stateManager->updateStripState($stripId, [
                'status' => StateManager::STATE_PANELS_GENERATING,
                'panels' => $generatedPanels,
                'panel_count' => count($generatedPanels),
                'updated_at' => time()
            ]);

            $this->logger->info('Panel generation process started', [
                'strip_id' => $stripId,
                'panel_count' => count($generatedPanels),
                'current_state' => $this->stateManager->getStripState($stripId)
            ]);
        } catch (Exception $e) {
            $this->logger->error('Failed to start panel generation', [
                'strip_id' => $stripId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Update state to failed
            $this->stateManager->updateStripState($stripId, [
                'status' => StateManager::STATE_FAILED,
                'error' => 'Failed to generate panels: ' . $e->getMessage(),
                'failed_at' => time()
            ]);

            throw $e;
        }
    }

    /**
     * Generate a single panel
     */
    private function generatePanel(array $characters, string $description, string $panelId, array $options): array
    {
        try {
            $this->logger->debug('Starting panel generation', [
                'panel_id' => $panelId,
                'description_length' => strlen($description),
                'character_count' => count($characters),
                'options' => array_keys($options)
            ]);

            // Initialize panel state
            $this->stateManager->updatePanelState($panelId, [
                'status' => StateManager::PANEL_STATE_INIT,
                'description' => $description,
                'options' => $options,
                'started_at' => time()
            ]);

            // Process character positions
            $this->logger->debug('Processing character positions', [
                'panel_id' => $panelId,
                'character_count' => count($characters)
            ]);

            $characterPositions = $this->processCharacterInteractions($characters, $description, $options);
            $this->stateManager->updatePanelState($panelId, [
                'character_positions' => $characterPositions,
                'status' => StateManager::PANEL_STATE_BACKGROUND_PENDING,
                'progress' => 20
            ]);

            $this->logger->debug('Character positions processed', [
                'panel_id' => $panelId,
                'positions' => array_map(function ($pos) {
                    return [
                        'x' => $pos['x'],
                        'y' => $pos['y'],
                        'z_index' => $pos['z_index'],
                        'scale' => $pos['scale']
                    ];
                }, $characterPositions)
            ]);

            // Start background generation
            $this->logger->debug('Starting background generation', [
                'panel_id' => $panelId,
                'description' => $description,
                'style' => $options['style'] ?? 'default'
            ]);

            $response = $this->imageComposer->generateBackground($description, $options['style'] ?? []);

            if (!isset($response['id'])) {
                throw new Exception('Invalid response from background generation');
            }

            // Store prediction ID for webhook handling
            $this->stateManager->updatePanelState($panelId, [
                'background_prediction_id' => $response['id'],
                'status' => StateManager::PANEL_STATE_BACKGROUND_PROCESSING,
                'progress' => 30,
                'background_started_at' => time()
            ]);

            $this->logger->info('Panel background generation started', [
                'panel_id' => $panelId,
                'prediction_id' => $response['id'],
                'status' => StateManager::PANEL_STATE_BACKGROUND_PROCESSING
            ]);

            return [
                'id' => $panelId,
                'status' => StateManager::PANEL_STATE_BACKGROUND_PROCESSING,
                'prediction_id' => $response['id'],
                'description' => $description,
                'character_positions' => $characterPositions,
                'progress' => 30
            ];
        } catch (Exception $e) {
            $this->logger->error('Panel generation failed', [
                'panel_id' => $panelId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $this->stateManager->updatePanelState($panelId, [
                'status' => StateManager::PANEL_STATE_FAILED,
                'error' => $e->getMessage(),
                'failed_at' => time()
            ]);
            throw $e;
        }
    }

    /**
     * Check if panel background is ready and start composition
     */
    public function checkAndStartPanelComposition(string $panelId): void
    {
        $panelState = $this->stateManager->getPanelState($panelId);
        if (!$panelState) {
            throw new Exception("Panel state not found: $panelId");
        }

        if ($panelState['status'] !== StateManager::PANEL_STATE_BACKGROUND_READY) {
            return;
        }

        try {
            // Compose panel with background and characters
            $composedPanelPath = $this->imageComposer->composePanelImage(
                $this->preparePanelComposition($panelState['characters'], $panelState['character_positions']),
                $panelState['description'],
                $panelState['options']
            );

            // Update final state
            $this->stateManager->updatePanelState($panelId, [
                'status' => StateManager::PANEL_STATE_COMPLETE,
                'output_path' => $composedPanelPath,
                'progress' => 100
            ]);

            $this->logger->info('Panel composition completed', [
                'panel_id' => $panelId,
                'output_path' => $composedPanelPath
            ]);
        } catch (Exception $e) {
            $this->stateManager->updatePanelState($panelId, [
                'status' => StateManager::PANEL_STATE_FAILED,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Process character interactions and determine optimal positioning
     */
    private function processCharacterInteractions(array $characters, string $description, array $options): array
    {
        $this->logger->info("Processing character interactions", [
            'character_count' => count($characters),
            'description_length' => strlen($description)
        ]);

        $positions = [];
        $panelWidth = $options['panel_width'] ?? 800;
        $panelHeight = $options['panel_height'] ?? 600;
        $margin = 50; // Minimum margin from panel edges

        // Simple positioning logic - distribute characters evenly across the panel
        $characterCount = count($characters);
        $spacing = ($panelWidth - (2 * $margin)) / max(1, ($characterCount - 1));

        $index = 0;
        foreach ($characters as $charId => $character) {
            // Calculate x position with margin and spacing
            $x = $margin + ($spacing * $index);

            // Place characters at different depths for visual interest
            $y = $margin + (($index % 2) * ($panelHeight / 4));

            $positions[$charId] = [
                'x' => $x,
                'y' => $y,
                'z_index' => $index + 1, // Layer characters front to back
                'scale' => 1.0 - (($index % 2) * 0.2) // Vary scale slightly for depth
            ];

            $index++;
        }

        return $positions;
    }

    /**
     * Prepare panel composition data
     */
    private function preparePanelComposition(array $characters, array $positions): array
    {
        $composition = [];
        foreach ($characters as $charId => $character) {
            // Skip characters that don't have cartoonified images yet
            if (!isset($character['cartoonified_image']) && $character['status'] !== 'completed') {
                $this->logger->info("Waiting for character cartoonification", [
                    'character_id' => $charId,
                    'status' => $character['status'] ?? 'unknown'
                ]);
                throw new Exception("Character $charId is still being processed");
            }

            if (!isset($character['cartoonified_image'])) {
                $this->logger->error("Character missing image data", [
                    'character_id' => $charId,
                    'status' => $character['status'] ?? 'unknown'
                ]);
                throw new Exception("Character $charId is missing image data");
            }

            $composition[$charId] = [
                'image' => $character['cartoonified_image'],
                'position' => $positions[$charId] ?? [],
                'character_data' => $character
            ];
        }
        return $composition;
    }
}
