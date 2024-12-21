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
        $this->logger->info("Starting comic strip generation", [
            'story_length' => strlen($story),
            'character_count' => count($characters)
        ]);

        try {
            // Generate strip ID and initialize state
            $stripId = 'strip_' . uniqid('', true);
            $state = $this->stateManager->initializeStrip($stripId, $options);

            // Process characters
            $processedCharacters = $this->characterProcessor->processCharacters($characters, array_merge($options, [
                'strip_id' => $stripId
            ]));
            $this->stateManager->updateStripState($stripId, ['characters' => $processedCharacters]);

            // Segment story into panels
            $panelDescriptions = $this->storyParser->segmentStory($story, $options);
            $this->stateManager->updateStripState($stripId, ['total_panels' => count($panelDescriptions)]);

            // Generate each panel
            $pendingPanels = [];
            foreach ($panelDescriptions as $index => $description) {
                $panelId = 'panel_' . uniqid('', true);
                $panelOptions = array_merge($options, [
                    'strip_id' => $stripId,
                    'panel_index' => $index
                ]);

                // Initialize panel state
                $this->stateManager->initializePanel($stripId, $panelId, $description, $panelOptions);

                // Generate panel
                $panelResult = $this->generatePanel($processedCharacters, $description, $panelId, $panelOptions);

                // Update strip state with panel info
                $stripState = $this->stateManager->getStripState($stripId);
                $stripState['panels'][$index] = [
                    'id' => $panelId,
                    'description' => $description,
                    'status' => $panelResult['status']
                ];
                $this->stateManager->updateStripState($stripId, ['panels' => $stripState['panels']]);

                if ($panelResult['status'] === StateManager::STATUS_PROCESSING) {
                    $pendingPanels[] = $panelId;
                }
            }

            return [
                'id' => $stripId,
                'status' => empty($pendingPanels) ? StateManager::STATUS_COMPLETED : StateManager::STATUS_PROCESSING,
                'pending_panels' => $pendingPanels,
                'total_panels' => count($panelDescriptions)
            ];
        } catch (Exception $e) {
            $this->logger->error("Failed to generate comic strip", [
                'error' => $e->getMessage()
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
            $this->stateManager->updatePanelState($panelId, ['status' => StateManager::STATUS_PROCESSING]);

            // Process character positions
            $characterPositions = $this->processCharacterInteractions($characters, $description, $options);
            $this->stateManager->updatePanelState($panelId, ['character_positions' => $characterPositions]);

            // Compose panel image
            $composedPanelPath = $this->imageComposer->composePanelImage(
                $this->preparePanelComposition($characters, $characterPositions),
                $description,
                $options
            );

            // Update final state
            $this->stateManager->updatePanelState($panelId, [
                'status' => StateManager::STATUS_COMPLETED,
                'output_path' => $composedPanelPath
            ]);

            return [
                'id' => $panelId,
                'status' => StateManager::STATUS_COMPLETED,
                'output' => $composedPanelPath
            ];
        } catch (Exception $e) {
            $this->stateManager->updatePanelState($panelId, [
                'status' => StateManager::STATUS_FAILED,
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
            $composition[$charId] = [
                'image' => $character['cartoonified_image'],
                'position' => $positions[$charId] ?? [],
                'character_data' => $character
            ];
        }
        return $composition;
    }
}
