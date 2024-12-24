<?php

require_once __DIR__ . '/../interfaces/LoggerInterface.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/CharacterProcessor.php';
require_once __DIR__ . '/StoryParser.php';
require_once __DIR__ . '/ImageComposer.php';
require_once __DIR__ . '/StateManager.php';
require_once __DIR__ . '/ReplicateClient.php';

class ComicGenerator
{
    private $stateManager;
    private $logger;
    private $config;
    private $imageComposer;
    private $characterProcessor;
    private $storyParser;
    private $replicateClient;

    public function __construct(
        StateManager $stateManager,
        LoggerInterface $logger,
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
        $this->replicateClient = new ReplicateClient($logger);
    }

    /**
     * Initialize a new comic strip generation
     */
    public function initializeComicStrip(string $story, array $characters, array $options = []): array
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

            // Return success response with strip ID
            return [
                'success' => true,
                'data' => [
                    'id' => $stripId,
                    'status' => 'initialized',
                    'message' => 'Comic generation initialized',
                    'characters' => $characters
                ]
            ];
        } catch (Exception $e) {
            $this->logger->error('Comic initialization failed', [
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Process character positions and interactions for a panel
     */
    public function processCharacterInteractions(array $characters, string $description, array $options = []): array
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
    public function preparePanelComposition(array $characters, array $positions): array
    {
        $composition = [];
        foreach ($characters as $charId => $character) {
            // Skip characters that don't have cartoonified images yet
            if (!isset($character['cartoonified_image']) && $character['status'] !== 'completed') {
                $this->logger->info("Character not ready for composition", [
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

    /**
     * Compose the final panel image
     */
    public function composePanelImage(array $composition, string $description, array $options = []): string
    {
        return $this->imageComposer->composePanelImage($composition, $description, $options);
    }

    /**
     * Generate background for a panel using the specified style and background type
     */
    public function generatePanelBackground(string $panelId, string $description, string $style, string $background): void
    {
        $this->logger->info('Generating panel background', [
            'panel_id' => $panelId,
            'style' => $style,
            'background' => $background
        ]);

        try {
            // Create pending file for webhook
            $pendingData = [
                'job_id' => $panelId,
                'type' => 'background_complete',
                'panel_id' => $panelId
            ];

            $this->createPendingFile($panelId, $pendingData);

            // Start background generation
            $prediction = $this->startBackgroundGeneration($description, [
                'style' => $style,
                'background' => $background,
                'panel_id' => $panelId
            ]);

            if (!$prediction || !isset($prediction['id'])) {
                throw new Exception('Failed to start background generation');
            }

            $this->logger->debug('Background generation started', [
                'panel_id' => $panelId,
                'prediction_id' => $prediction['id']
            ]);
        } catch (Exception $e) {
            $this->logger->error('Background generation failed', [
                'panel_id' => $panelId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Start background generation with the AI model
     */
    private function startBackgroundGeneration(string $description, array $options): array
    {
        $modelId = $this->config->getBackgroundModel();
        $webhookUrl = rtrim($this->config->getBaseUrl(), '/') . '/webhook.php';

        return $this->replicateClient->createPrediction([
            'model' => $modelId,
            'input' => [
                'prompt' => $this->buildBackgroundPrompt($description, $options),
                'style' => $options['style'],
                'background' => $options['background']
            ],
            'webhook' => $webhookUrl,
            'webhook_events_filter' => ['completed']
        ]);
    }

    /**
     * Build the prompt for background generation
     */
    private function buildBackgroundPrompt(string $description, array $options): string
    {
        return sprintf(
            'Generate a %s style background for a comic panel showing: %s. The scene should be set in a %s environment.',
            $options['style'],
            $description,
            $options['background']
        );
    }

    /**
     * Create a pending file for webhook callbacks
     */
    private function createPendingFile(string $predictionId, array $data): void
    {
        $pendingFile = $this->config->getTempPath() . "/pending_{$predictionId}.json";
        if (file_put_contents($pendingFile, json_encode($data)) === false) {
            throw new Exception('Failed to create pending file');
        }
    }
}
