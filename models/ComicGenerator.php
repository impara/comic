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

            // Process characters
            $processedCharacters = $this->characterProcessor->processCharacters($characters, $stripId);

            // Initialize strip state
            $stripState = [
                'id' => $stripId,
                'status' => 'processing',
                'characters' => $processedCharacters,
                'story' => $story,
                'progress' => 0,
                'created_at' => time()
            ];

            $this->stateManager->updateStripState($stripId, $stripState);

            // Return success response with strip ID
            return [
                'success' => true,
                'data' => [
                    'id' => $stripId,
                    'status' => 'processing',
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
