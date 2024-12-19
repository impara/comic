<?php

require_once __DIR__ . '/../interfaces/LoggerInterface.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/CharacterProcessor.php';
require_once __DIR__ . '/ReplicateClient.php';
require_once __DIR__ . '/ImageComposer.php';
require_once __DIR__ . '/StoryParser.php';
require_once __DIR__ . '/FileManager.php';

class ComicGenerator
{
    private LoggerInterface $logger;
    private Config $config;
    private CharacterProcessor $characterProcessor;
    private ReplicateClient $replicateClient;
    private StoryParser $storyParser;
    private ImageComposer $imageComposer;
    private FileManager $fileManager;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->config = Config::getInstance();
        $this->characterProcessor = new CharacterProcessor($logger);
        $this->replicateClient = new ReplicateClient($logger);
        $this->storyParser = new StoryParser($logger);
        $this->imageComposer = new ImageComposer($logger);
        $this->fileManager = FileManager::getInstance($logger);
    }

    /**
     * Generate a complete comic strip from a story
     * @param string $story The complete story to convert into a comic
     * @param array $characters Array of character data
     * @param array $options Additional options for generation
     * @return array Comic strip generation status
     */
    public function generateComicStrip(string $story, array $characters, array $options = []): array
    {
        $this->logger->info("Starting comic strip generation", [
            'story_length' => strlen($story),
            'character_count' => count($characters),
            'options' => $options
        ]);

        try {
            // Generate a unique ID for this comic strip
            $stripId = 'strip_' . uniqid('', true);

            // Create state file for the entire strip
            $stripStateFile = $this->config->getTempPath() . "strip_state_{$stripId}.json";
            $initialState = [
                'id' => $stripId,
                'status' => 'processing',
                'started_at' => time(),
                'panels' => [],
                'options' => $options,
                'updated_at' => time(),
                'composition_status' => 'pending',
                'progress' => 0
            ];
            file_put_contents($stripStateFile, json_encode($initialState));

            // Segment the story into panels
            $panelDescriptions = $this->storyParser->segmentStory($story, $options);

            // Validate panel transitions for continuity
            $this->validatePanelTransitions($panelDescriptions, $characters);

            // Update strip state with panel information
            $state = json_decode(file_get_contents($stripStateFile), true);
            $state['total_panels'] = count($panelDescriptions);
            file_put_contents($stripStateFile, json_encode($state));

            // Generate each panel
            $pendingPanels = [];
            $completedPanels = [];
            foreach ($panelDescriptions as $index => $description) {
                // Prepare panel-specific options with consistent styling
                $panelOptions = $this->preparePanelOptions($options, $index, count($panelDescriptions));

                // Generate the panel
                $panelResult = $this->generatePanel(
                    $characters,
                    $description,
                    null,
                    array_merge($panelOptions, ['strip_id' => $stripId, 'panel_index' => $index])
                );

                // Update strip state with panel information
                $state = json_decode(file_get_contents($stripStateFile), true);
                $state['panels'][$index] = [
                    'id' => $panelResult['id'],
                    'description' => $description,
                    'status' => $panelResult['status'],
                    'started_at' => time()
                ];
                file_put_contents($stripStateFile, json_encode($state));

                if ($panelResult['status'] === 'pending') {
                    $pendingPanels[] = $panelResult['id'];
                } else {
                    $completedPanels[$index] = $panelResult['id'];
                }
            }

            // If all panels are completed, compose the strip
            if (empty($pendingPanels)) {
                $this->composeCompletedStrip($stripId, $completedPanels);
            }

            return [
                'id' => $stripId,
                'status' => count($pendingPanels) > 0 ? 'processing' : 'completed',
                'total_panels' => count($panelDescriptions),
                'pending_panels' => $pendingPanels,
                'completed_panels' => $completedPanels,
                'state_file' => basename($stripStateFile)
            ];
        } catch (Exception $e) {
            $this->logger->error("Failed to generate comic strip", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Compose a completed comic strip from generated panels
     * @param string $stripId Strip ID
     * @param array $completedPanels Array of completed panel IDs
     * @throws Exception on composition failure
     */
    private function composeCompletedStrip(string $stripId, array $completedPanels): void
    {
        try {
            // Get panel paths
            $panelPaths = [];
            foreach ($completedPanels as $index => $panelId) {
                $stateFile = $this->config->getTempPath() . "state_{$panelId}.json";
                if (!file_exists($stateFile)) {
                    throw new RuntimeException("Panel state file not found: $stateFile");
                }

                $panelState = json_decode(file_get_contents($stateFile), true);
                if (!isset($panelState['composed_panel_path'])) {
                    throw new RuntimeException("Panel image path not found for panel: $panelId");
                }

                $panelPaths[$index] = $panelState['composed_panel_path'];
            }

            // Sort panels by index
            ksort($panelPaths);

            // Compose the strip
            $stripPath = $this->imageComposer->composeStrip($panelPaths, $stripId);

            // Update strip state
            $stripStateFile = $this->config->getTempPath() . "strip_state_{$stripId}.json";
            if (file_exists($stripStateFile)) {
                $state = json_decode(file_get_contents($stripStateFile), true);
                $state['status'] = 'completed';
                $state['composition_status'] = 'completed';
                $state['output_path'] = $stripPath;
                $state['completed_at'] = time();
                file_put_contents($stripStateFile, json_encode($state));
            }
        } catch (Exception $e) {
            $this->logger->error("Failed to compose comic strip", [
                'strip_id' => $stripId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Generate a single panel of the comic strip
     * @param array $characters Array of character data
     * @param string $sceneDescription Description of the scene
     * @param string|null $originalPanelId Original prediction ID to update
     * @param array $options Additional options for panel generation
     * @return array Generated panel data
     */
    public function generatePanel(array $characters, string $sceneDescription, ?string $originalPanelId = null, array $options = []): array
    {
        $this->logger->info("Generating panel", [
            'character_count' => count($characters),
            'scene_length' => strlen($sceneDescription),
            'original_panel_id' => $originalPanelId,
            'options' => $options
        ]);

        try {
            // Use provided ID or generate new one
            $panelId = $originalPanelId ?: 'panel_' . uniqid('', true);

            // Create state file for tracking
            $stateFile = $this->config->getTempPath() . "state_{$panelId}.json";
            $initialState = [
                'id' => $panelId,
                'strip_id' => $options['strip_id'] ?? null,
                'panel_index' => $options['panel_index'] ?? null,
                'status' => 'initializing',
                'started_at' => time(),
                'cartoonification_requests' => [],
                'sdxl_status' => 'pending',
                'style_options' => $options['style'] ?? [],
                'updated_at' => time()
            ];
            file_put_contents($stateFile, json_encode($initialState));

            // Process character interactions and determine optimal positioning
            $characterPositions = $this->processCharacterInteractions($characters, $sceneDescription, $options);

            // Update state with character positions
            $state = json_decode(file_get_contents($stateFile), true);
            $state['character_positions'] = $characterPositions;
            file_put_contents($stateFile, json_encode($state));

            $pendingCartoonifications = [];
            $processedCharacters = [];

            // Process each character with consistent styling and positioning
            foreach ($characters as $character) {
                // Merge strip-wide style options with character-specific options
                $character['options'] = array_merge(
                    $character['options'] ?? [],
                    $options['style'] ?? [],
                    ['panel_index' => $options['panel_index'] ?? 0],
                    // Add positioning information
                    $characterPositions[$character['id']] ?? []
                );

                // Process character
                $cartoonificationResult = $this->processCharacterForPanel($character, $panelId, $stateFile);

                if ($cartoonificationResult['status'] === 'pending') {
                    $pendingCartoonifications[] = $cartoonificationResult['prediction_id'];
                } else {
                    $processedCharacters[] = $cartoonificationResult['character'];
                }
            }

            // Return result
            return [
                'id' => $panelId,
                'strip_id' => $options['strip_id'] ?? null,
                'panel_index' => $options['panel_index'] ?? null,
                'status' => count($pendingCartoonifications) > 0 ? 'pending' : 'processing',
                'pending_predictions' => $pendingCartoonifications,
                'state_file' => basename($stateFile),
                'character_positions' => $characterPositions
            ];
        } catch (Exception $e) {
            $this->logger->error("Failed to generate panel", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'panel_id' => $panelId ?? null
            ]);
            throw $e;
        }
    }

    /**
     * Process a character for panel inclusion with consistent styling
     * @param array $character Character data
     * @param string $panelId Panel ID
     * @param string $stateFile State file path
     * @return array Processing result
     */
    private function processCharacterForPanel(array $character, string $panelId, string $stateFile): array
    {
        try {
            // Start cartoonification process
            $cartoonificationResult = $this->characterProcessor->processCharacter($character);

            if (isset($cartoonificationResult['output'])) {
                // Synchronous completion
                $character['cartoonified_image'] = $cartoonificationResult['output'];
                $this->updatePanelState($stateFile, [
                    'prediction_id' => $cartoonificationResult['id'],
                    'character_id' => $character['id'],
                    'status' => 'succeeded',
                    'output' => $cartoonificationResult['output']
                ]);

                return [
                    'status' => 'completed',
                    'character' => $character,
                    'prediction_id' => $cartoonificationResult['id']
                ];
            } else {
                // Asynchronous processing
                $this->createPendingFile($cartoonificationResult['id'], $panelId, $character);
                $this->updatePanelState($stateFile, [
                    'prediction_id' => $cartoonificationResult['id'],
                    'character_id' => $character['id'],
                    'status' => 'pending'
                ]);

                return [
                    'status' => 'pending',
                    'prediction_id' => $cartoonificationResult['id']
                ];
            }
        } catch (Exception $e) {
            $this->logger->error("Failed to process character for panel", [
                'character_id' => $character['id'],
                'panel_id' => $panelId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Prepare consistent style options for a panel
     * @param array $options Strip-wide options
     * @param int $panelIndex Current panel index
     * @param int $totalPanels Total number of panels
     * @return array Panel-specific options
     */
    private function preparePanelOptions(array $options, int $panelIndex, int $totalPanels): array
    {
        // Get panel dimensions from config
        $panelDimensions = $this->config->get('comic_strip.panel_dimensions');

        // Base style options
        $styleOptions = [
            'width' => $panelDimensions['width'],
            'height' => $panelDimensions['height'],
            'style' => $options['style'] ?? 'modern',
            'panel_position' => [
                'index' => $panelIndex,
                'total' => $totalPanels
            ],
            // Enhanced style consistency options
            'art_style' => [
                'line_weight' => $options['line_weight'] ?? 'medium',
                'shading_style' => $options['shading_style'] ?? 'cel',
                'color_palette' => $options['color_palette'] ?? 'vibrant',
                'perspective' => $this->determinePanelPerspective($panelIndex, $totalPanels),
                'composition_type' => $this->determinePanelComposition($panelIndex, $totalPanels)
            ],
            'consistency_anchors' => [
                'character_scale' => $options['character_scale'] ?? 1.0,
                'background_detail' => $options['background_detail'] ?? 'medium',
                'lighting_scheme' => $options['lighting_scheme'] ?? 'neutral',
                'panel_mood' => $this->determinePanelMood($panelIndex, $totalPanels)
            ],
            'transition_hints' => [
                'previous_panel_style' => $panelIndex > 0 ? $options['previous_panel_style'] ?? null : null,
                'next_panel_style' => $panelIndex < $totalPanels - 1 ? $options['next_panel_style'] ?? null : null
            ]
        ];

        // Add any additional style parameters from options
        if (isset($options['style_params'])) {
            $styleOptions = array_merge($styleOptions, $options['style_params']);
        }

        return [
            'style' => $styleOptions,
            'maintain_consistency' => true
        ];
    }

    /**
     * Determine appropriate perspective for panel based on position
     * @param int $panelIndex Current panel index
     * @param int $totalPanels Total number of panels
     * @return string Perspective type
     */
    private function determinePanelPerspective(int $panelIndex, int $totalPanels): string
    {
        // Establish a pattern of perspectives for visual interest while maintaining coherence
        $perspectives = ['medium-shot', 'close-up', 'wide-shot'];

        // Use panel position to influence perspective choice
        if ($panelIndex === 0) {
            // Opening panel often benefits from a wider shot to establish scene
            return 'wide-shot';
        } elseif ($panelIndex === $totalPanels - 1) {
            // Closing panel might want medium or close-up for impact
            return 'medium-shot';
        }

        // Middle panels rotate through perspectives
        return $perspectives[$panelIndex % count($perspectives)];
    }

    /**
     * Determine panel composition based on position and total panels
     * @param int $panelIndex Current panel index
     * @param int $totalPanels Total number of panels
     * @return string Composition type
     */
    private function determinePanelComposition(int $panelIndex, int $totalPanels): string
    {
        // Basic composition types
        $compositions = [
            'balanced',      // Equal weight distribution
            'dynamic',       // Diagonal or asymmetric
            'centered',      // Focus on center
            'rule-of-thirds' // Using the rule of thirds grid
        ];

        // Use panel context to determine composition
        if ($panelIndex === 0) {
            // First panel: establish scene with balanced composition
            return 'balanced';
        } elseif ($panelIndex === $totalPanels - 1) {
            // Last panel: impactful centered composition
            return 'centered';
        }

        // Middle panels: alternate between dynamic and rule-of-thirds
        return $panelIndex % 2 === 0 ? 'dynamic' : 'rule-of-thirds';
    }

    /**
     * Determine panel mood based on position in sequence
     * @param int $panelIndex Current panel index
     * @param int $totalPanels Total number of panels
     * @return string Panel mood
     */
    private function determinePanelMood(int $panelIndex, int $totalPanels): string
    {
        // Default mood progression
        $moodProgression = [
            'establishing',  // Setting the scene
            'developing',    // Building the story
            'transitional', // Moving the story forward
            'climactic',    // Building to climax
            'resolving'     // Resolution
        ];

        // Calculate relative position in story (0 to 1)
        $position = $panelIndex / ($totalPanels - 1);

        // Map position to mood
        if ($position < 0.2) {
            return 'establishing';
        } elseif ($position < 0.4) {
            return 'developing';
        } elseif ($position < 0.6) {
            return 'transitional';
        } elseif ($position < 0.8) {
            return 'climactic';
        } else {
            return 'resolving';
        }
    }

    /**
     * Update the panel state file
     * @param string $stateFile Path to state file
     * @param array $update Update data
     */
    private function updatePanelState(string $stateFile, array $update): void
    {
        $state = json_decode(file_get_contents($stateFile), true);
        if (!isset($state['cartoonification_requests'])) {
            $state['cartoonification_requests'] = [];
        }
        $state['cartoonification_requests'][] = array_merge($update, [
            'updated_at' => time()
        ]);
        file_put_contents($stateFile, json_encode($state));
    }

    /**
     * Create a pending file for async processing
     * @param string $predictionId Prediction ID
     * @param string $panelId Panel ID
     * @param array $character Character data
     */
    private function createPendingFile(string $predictionId, string $panelId, array $character): void
    {
        $pendingFile = $this->config->getTempPath() . "pending_{$predictionId}.json";
        file_put_contents($pendingFile, json_encode([
            'prediction_id' => $predictionId,
            'panel_id' => $panelId,
            'stage' => 'cartoonify',
            'next_stage' => 'sdxl',
            'character_id' => $character['id'],
            'character_data' => $character,
            'started_at' => time()
        ]));
    }

    /**
     * Validate transitions between panels for continuity and consistency
     * @param array $panelDescriptions Array of panel descriptions
     * @param array $characters Array of characters to track
     * @throws RuntimeException if validation fails
     */
    private function validatePanelTransitions(array $panelDescriptions, array $characters): void
    {
        $this->logger->info("Validating panel transitions", [
            'panel_count' => count($panelDescriptions),
            'character_count' => count($characters)
        ]);

        // Create character lookup for quick reference
        $characterLookup = [];
        foreach ($characters as $character) {
            $characterLookup[$character['id']] = $character['name'];
        }

        // Track character appearances and validate transitions
        $characterPresence = [];
        for ($i = 0; $i < count($panelDescriptions) - 1; $i++) {
            $currentPanel = $panelDescriptions[$i];
            $nextPanel = $panelDescriptions[$i + 1];

            // Check for character continuity
            foreach ($characters as $character) {
                $name = preg_quote($character['name'], '/');
                $currentHasCharacter = preg_match("/\b$name\b/i", $currentPanel) === 1;
                $nextHasCharacter = preg_match("/\b$name\b/i", $nextPanel) === 1;

                if ($currentHasCharacter) {
                    $characterPresence[$character['id']][$i] = true;
                }

                // Check for abrupt character disappearance
                if ($currentHasCharacter && !$nextHasCharacter) {
                    // Log character transition for debugging
                    $this->logger->info("Character transition detected", [
                        'character' => $character['name'],
                        'from_panel' => $i,
                        'to_panel' => $i + 1,
                        'current_description' => $currentPanel,
                        'next_description' => $nextPanel
                    ]);
                }
            }

            // Validate scene transition logic
            if (!$this->isValidSceneTransition($currentPanel, $nextPanel)) {
                throw new RuntimeException(
                    "Invalid scene transition detected between panels " . ($i + 1) . " and " . ($i + 2)
                );
            }
        }

        // Update character presence in the last panel
        foreach ($characters as $character) {
            $name = preg_quote($character['name'], '/');
            if (preg_match("/\b$name\b/i", end($panelDescriptions)) === 1) {
                $characterPresence[$character['id']][count($panelDescriptions) - 1] = true;
            }
        }

        $this->logger->info("Panel transitions validated", [
            'character_presence' => $characterPresence
        ]);
    }

    /**
     * Check if the transition between two panels is valid
     * @param string $currentPanel Current panel description
     * @param string $nextPanel Next panel description
     * @return bool Whether the transition is valid
     */
    private function isValidSceneTransition(string $currentPanel, string $nextPanel): bool
    {
        // Check for drastic location changes without explanation
        $currentLocation = $this->extractLocation($currentPanel);
        $nextLocation = $this->extractLocation($nextPanel);

        if ($currentLocation && $nextLocation && $currentLocation !== $nextLocation) {
            // Check if the transition is explained in the next panel
            $transitionWords = ['moves to', 'enters', 'arrives at', 'goes to', 'leaves for'];
            foreach ($transitionWords as $word) {
                if (stripos($nextPanel, $word) !== false) {
                    return true;
                }
            }

            $this->logger->warning("Potentially abrupt location change", [
                'from' => $currentLocation,
                'to' => $nextLocation,
                'current_panel' => $currentPanel,
                'next_panel' => $nextPanel
            ]);
        }

        return true;
    }

    /**
     * Extract location information from panel description
     * @param string $description Panel description
     * @return string|null Extracted location or null if not found
     */
    private function extractLocation(string $description): ?string
    {
        // Common location indicators
        $indicators = ['in the', 'at the', 'inside', 'outside'];

        foreach ($indicators as $indicator) {
            if (preg_match("/$indicator\s+([^,.]+)/i", $description, $matches)) {
                return trim($matches[1]);
            }
        }

        return null;
    }

    /**
     * Clean up resources for a comic strip
     * @param string $stripId Strip ID to clean up
     * @param bool $force Whether to force cleanup even if not completed/failed
     */
    public function cleanupStripResources(string $stripId, bool $force = false): void
    {
        $this->logger->info("Starting resource cleanup", [
            'strip_id' => $stripId,
            'force' => $force
        ]);

        try {
            $tempPath = $this->config->getTempPath();
            $stripStateFile = $tempPath . "strip_state_{$stripId}.json";

            if (!file_exists($stripStateFile)) {
                throw new RuntimeException("Strip state file not found: $stripId");
            }

            $state = json_decode(file_get_contents($stripStateFile), true);
            if (!$state) {
                throw new RuntimeException("Invalid strip state file: $stripId");
            }

            // Only cleanup if strip is completed, failed, or forced
            if (!$force && !in_array($state['status'], ['completed', 'failed'])) {
                $this->logger->info("Skipping cleanup for active strip", [
                    'strip_id' => $stripId,
                    'status' => $state['status']
                ]);
                return;
            }

            // Collect all files associated with this strip
            $filesToKeep = [];
            if (!$force && isset($state['output_path']) && file_exists($state['output_path'])) {
                $filesToKeep[] = $state['output_path'];
            }

            // Clean up panel resources
            foreach ($state['panels'] as $panel) {
                if (isset($panel['id'])) {
                    $this->cleanupPanelResources($panel['id']);
                }
            }

            // Use FileManager to clean up temporary files
            $this->fileManager->cleanupTempFiles($filesToKeep, 0); // Immediate cleanup for this strip's files

            $this->logger->info("Strip resources cleaned up successfully", [
                'strip_id' => $stripId
            ]);
        } catch (Exception $e) {
            $this->logger->error("Failed to clean up strip resources", [
                'strip_id' => $stripId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Clean up resources for a single panel
     * @param string $panelId Panel ID to clean up
     */
    private function cleanupPanelResources(string $panelId): void
    {
        $tempPath = $this->config->getTempPath();
        $filesToKeep = [];

        // Get all files associated with this panel
        $stateFile = $tempPath . "state_{$panelId}.json";
        $resultFile = $tempPath . "{$panelId}.json";
        $composedPanelPattern = $tempPath . "composed_panel_*_{$panelId}.png";
        $pendingFiles = glob($tempPath . "pending_*.json");

        // Check if any files need to be kept
        $state = file_exists($stateFile) ? json_decode(file_get_contents($stateFile), true) : null;
        if ($state && isset($state['composed_panel_path']) && file_exists($state['composed_panel_path'])) {
            $filesToKeep[] = $state['composed_panel_path'];
        }

        // Use FileManager to clean up temporary files
        $this->fileManager->cleanupTempFiles($filesToKeep, 0); // Immediate cleanup for this panel's files
    }

    /**
     * Cleanup old resources based on age and status
     * @param int $maxAge Maximum age in seconds for resources to keep
     */
    public function cleanupOldResources(int $maxAge = 86400): void
    {
        $this->logger->info("Starting old resource cleanup", [
            'max_age' => $maxAge
        ]);

        try {
            $tempPath = $this->config->getTempPath();
            $filesToKeep = [];

            // Identify files that should be kept
            $stripStates = glob($tempPath . "strip_state_*.json");
            foreach ($stripStates as $stateFile) {
                $state = json_decode(file_get_contents($stateFile), true);
                if (!$state) continue;

                if (isset($state['output_path']) && file_exists($state['output_path'])) {
                    $age = time() - ($state['updated_at'] ?? $state['started_at'] ?? 0);
                    if ($age <= $maxAge) {
                        $filesToKeep[] = $state['output_path'];
                    }
                }
            }

            // Use FileManager to clean up old files
            $this->fileManager->cleanupTempFiles($filesToKeep, $maxAge);

            $this->logger->info("Old resource cleanup completed");
        } catch (Exception $e) {
            $this->logger->error("Failed to clean up old resources", [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Retry failed panels in a comic strip
     * @param string $stripId Strip ID
     * @param array $options Optional parameters for retry
     * @return array Updated strip status
     */
    public function retryFailedPanels(string $stripId, array $options = []): array
    {
        $this->logger->info("Retrying failed panels", [
            'strip_id' => $stripId,
            'options' => $options
        ]);

        try {
            $tempPath = $this->config->getTempPath();
            $stripStateFile = $tempPath . "strip_state_{$stripId}.json";

            if (!file_exists($stripStateFile)) {
                throw new RuntimeException("Strip state file not found: $stripId");
            }

            $state = json_decode(file_get_contents($stripStateFile), true);
            if (!$state) {
                throw new RuntimeException("Invalid strip state file: $stripId");
            }

            // Find failed panels
            $failedPanels = [];
            foreach ($state['panels'] as $index => $panel) {
                if ($panel['status'] === 'failed') {
                    $failedPanels[$index] = $panel;
                }
            }

            if (empty($failedPanels)) {
                $this->logger->info("No failed panels found to retry", [
                    'strip_id' => $stripId
                ]);
                return $state;
            }

            // Retry each failed panel
            $pendingPanels = [];
            $completedPanels = [];
            foreach ($failedPanels as $index => $panel) {
                // Clean up old panel resources
                $this->cleanupPanelResources($panel['id']);

                // Prepare retry options
                $retryOptions = array_merge($options, [
                    'strip_id' => $stripId,
                    'panel_index' => $index,
                    'is_retry' => true,
                    'retry_count' => ($panel['retry_count'] ?? 0) + 1
                ]);

                // Generate new panel
                $panelResult = $this->generatePanel(
                    $state['characters'],
                    $panel['description'],
                    null,
                    $retryOptions
                );

                // Update strip state
                $state['panels'][$index] = [
                    'id' => $panelResult['id'],
                    'description' => $panel['description'],
                    'status' => $panelResult['status'],
                    'started_at' => time(),
                    'retry_count' => $retryOptions['retry_count']
                ];

                if ($panelResult['status'] === 'pending') {
                    $pendingPanels[] = $panelResult['id'];
                } else {
                    $completedPanels[$index] = $panelResult['id'];
                }
            }

            // Update strip status
            $state['status'] = count($pendingPanels) > 0 ? 'processing' : 'completed';
            $state['updated_at'] = time();
            file_put_contents($stripStateFile, json_encode($state));

            return [
                'id' => $stripId,
                'status' => $state['status'],
                'retried_panels' => count($failedPanels),
                'pending_panels' => $pendingPanels,
                'completed_panels' => $completedPanels
            ];
        } catch (Exception $e) {
            $this->logger->error("Failed to retry panels", [
                'strip_id' => $stripId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Update progress for a comic strip
     * @param string $stripId Strip ID
     * @param array $state Current strip state
     */
    private function updateStripProgress(string $stripId, array $state): void
    {
        if (!isset($state['total_panels']) || $state['total_panels'] === 0) {
            return;
        }

        $completedCount = 0;
        foreach ($state['panels'] as $panel) {
            if ($panel['status'] === 'succeeded') {
                $completedCount++;
            }
        }

        $progress = ($completedCount / $state['total_panels']) * 100;

        // Update state file with progress
        $state['progress'] = round($progress, 2);
        $state['updated_at'] = time();

        $stripStateFile = $this->config->getTempPath() . "strip_state_{$stripId}.json";
        file_put_contents($stripStateFile, json_encode($state));

        $this->logger->info("Strip progress updated", [
            'strip_id' => $stripId,
            'progress' => $progress,
            'completed' => $completedCount,
            'total' => $state['total_panels']
        ]);
    }

    /**
     * Process character interactions for a panel
     * @param array $characters Array of characters in the panel
     * @param string $sceneDescription Scene description
     * @param array $options Panel options
     * @return array Processed character data with interaction info
     */
    private function processCharacterInteractions(array $characters, string $sceneDescription, array $options): array
    {
        $this->logger->info("Processing character interactions", [
            'character_count' => count($characters),
            'scene_length' => strlen($sceneDescription)
        ]);

        // Extract interaction keywords from scene description
        $interactionKeywords = [
            'talking' => ['talks to', 'speaking with', 'conversing', 'says to'],
            'facing' => ['faces', 'looking at', 'turns to', 'watching'],
            'action' => ['gives', 'hands', 'shows', 'helps', 'pushes', 'pulls'],
            'emotion' => ['smiles at', 'frowns at', 'laughs with', 'angry at'],
            'proximity' => ['next to', 'beside', 'near', 'close to', 'far from']
        ];

        $characterInteractions = [];
        foreach ($characters as $char1) {
            $characterInteractions[$char1['id']] = [
                'interactions' => [],
                'position_weight' => 0,
                'focal_point' => false,
                'emotional_state' => $this->extractEmotionalState($char1['name'], $sceneDescription)
            ];

            // Analyze interactions with other characters
            foreach ($characters as $char2) {
                if ($char1['id'] === $char2['id']) continue;

                $interaction = $this->analyzeCharacterPairInteraction(
                    $char1,
                    $char2,
                    $sceneDescription,
                    $interactionKeywords
                );

                if ($interaction) {
                    $characterInteractions[$char1['id']]['interactions'][] = [
                        'with' => $char2['id'],
                        'type' => $interaction['type'],
                        'intensity' => $interaction['intensity'],
                        'direction' => $interaction['direction']
                    ];
                }
            }
        }

        // Calculate focal points and position weights
        $this->calculateCharacterImportance($characterInteractions, $sceneDescription);

        // Determine optimal positioning based on interactions
        return $this->determineCharacterPositioning($characterInteractions, $options);
    }

    /**
     * Analyze interaction between two characters
     * @param array $char1 First character
     * @param array $char2 Second character
     * @param string $sceneDescription Scene description
     * @param array $interactionKeywords Interaction keyword mapping
     * @return array|null Interaction details or null if no interaction
     */
    private function analyzeCharacterPairInteraction(
        array $char1,
        array $char2,
        string $sceneDescription,
        array $interactionKeywords
    ): ?array {
        $char1Name = preg_quote($char1['name'], '/');
        $char2Name = preg_quote($char2['name'], '/');

        foreach ($interactionKeywords as $type => $keywords) {
            foreach ($keywords as $keyword) {
                // Check both directions of interaction
                $pattern1 = "/\b$char1Name\b.*?\b$keyword\b.*?\b$char2Name\b/i";
                $pattern2 = "/\b$char2Name\b.*?\b$keyword\b.*?\b$char1Name\b/i";

                if (preg_match($pattern1, $sceneDescription)) {
                    return [
                        'type' => $type,
                        'intensity' => $this->calculateInteractionIntensity($type, $sceneDescription),
                        'direction' => 'forward'
                    ];
                } elseif (preg_match($pattern2, $sceneDescription)) {
                    return [
                        'type' => $type,
                        'intensity' => $this->calculateInteractionIntensity($type, $sceneDescription),
                        'direction' => 'reverse'
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Calculate interaction intensity based on type and context
     * @param string $type Interaction type
     * @param string $sceneDescription Scene description
     * @return float Interaction intensity (0.0 to 1.0)
     */
    private function calculateInteractionIntensity(string $type, string $sceneDescription): float
    {
        $intensityModifiers = [
            'excitedly' => 0.8,
            'loudly' => 0.9,
            'quietly' => 0.3,
            'gently' => 0.4,
            'forcefully' => 0.9,
            'casually' => 0.5
        ];

        $baseIntensity = match ($type) {
            'talking' => 0.6,
            'facing' => 0.4,
            'action' => 0.7,
            'emotion' => 0.8,
            'proximity' => 0.5,
            default => 0.5
        };

        // Adjust intensity based on modifiers in description
        foreach ($intensityModifiers as $modifier => $adjustment) {
            if (stripos($sceneDescription, $modifier) !== false) {
                $baseIntensity = min(1.0, $baseIntensity * $adjustment);
                break;
            }
        }

        return $baseIntensity;
    }

    /**
     * Extract emotional state for a character
     * @param string $characterName Character name
     * @param string $sceneDescription Scene description
     * @return string Emotional state
     */
    private function extractEmotionalState(string $characterName, string $sceneDescription): string
    {
        $emotions = [
            'happy' => ['smiling', 'laughing', 'joyful', 'excited', 'pleased'],
            'sad' => ['frowning', 'crying', 'upset', 'depressed', 'miserable'],
            'angry' => ['furious', 'mad', 'annoyed', 'irritated', 'enraged'],
            'surprised' => ['shocked', 'startled', 'amazed', 'astonished'],
            'neutral' => ['calm', 'composed', 'steady', 'normal']
        ];

        $characterName = preg_quote($characterName, '/');
        foreach ($emotions as $emotion => $keywords) {
            foreach ($keywords as $keyword) {
                if (preg_match("/\b$characterName\b.*?\b$keyword\b|\b$keyword\b.*?\b$characterName\b/i", $sceneDescription)) {
                    return $emotion;
                }
            }
        }

        return 'neutral';
    }

    /**
     * Calculate importance and focal points for characters
     * @param array &$characterInteractions Character interaction data
     * @param string $sceneDescription Scene description
     */
    private function calculateCharacterImportance(array &$characterInteractions, string $sceneDescription): void
    {
        foreach ($characterInteractions as $charId => &$data) {
            // Base weight on number of interactions
            $weight = count($data['interactions']) * 0.3;

            // Add weight for emotional intensity
            $weight += match ($data['emotional_state']) {
                'happy', 'angry' => 0.3,
                'sad', 'surprised' => 0.25,
                default => 0.1
            };

            // Add weight for interaction intensity
            foreach ($data['interactions'] as $interaction) {
                $weight += $interaction['intensity'] * 0.2;
            }

            $data['position_weight'] = min(1.0, $weight);
            $data['focal_point'] = $weight > 0.6; // Character becomes focal point if weight is high
        }
    }

    /**
     * Determine optimal character positioning based on interactions
     * @param array $characterInteractions Character interaction data
     * @param array $options Panel options
     * @return array Processed character data with positions
     */
    private function determineCharacterPositioning(array $characterInteractions, array $options): array
    {
        $panelWidth = $options['style']['width'] ?? 1024;
        $panelHeight = $options['style']['height'] ?? 1024;
        $positions = [];

        // Sort characters by weight for priority positioning
        uasort($characterInteractions, function ($a, $b) {
            return $b['position_weight'] <=> $a['position_weight'];
        });

        // Calculate base positions
        foreach ($characterInteractions as $charId => $data) {
            $baseX = $panelWidth * 0.5; // Start from center
            $baseY = $panelHeight * 0.7; // Lower portion of panel

            // Adjust position based on interactions
            foreach ($data['interactions'] as $interaction) {
                if (isset($positions[$interaction['with']])) {
                    // Position relative to interacting character
                    $otherPos = $positions[$interaction['with']];
                    $baseX = $this->calculateRelativePosition(
                        $baseX,
                        $otherPos['x'],
                        $interaction['type'],
                        $interaction['direction']
                    );
                }
            }

            // Adjust for focal point characters
            if ($data['focal_point']) {
                $baseY -= $panelHeight * 0.1; // Move important characters slightly forward
            }

            $positions[$charId] = [
                'x' => max(100, min($panelWidth - 100, $baseX)),
                'y' => max(100, min($panelHeight - 100, $baseY)),
                'scale' => $data['focal_point'] ? 1.2 : 1.0,
                'z_index' => $data['focal_point'] ? 2 : 1
            ];
        }

        return $positions;
    }

    /**
     * Calculate relative position based on interaction
     * @param float $baseX Base X position
     * @param float $otherX Other character's X position
     * @param string $interactionType Type of interaction
     * @param string $direction Interaction direction
     * @return float New X position
     */
    private function calculateRelativePosition(
        float $baseX,
        float $otherX,
        string $interactionType,
        string $direction
    ): float {
        $distance = match ($interactionType) {
            'talking', 'emotion' => 200,
            'action' => 150,
            'facing' => 250,
            'proximity' => 180,
            default => 200
        };

        return $direction === 'forward'
            ? $otherX - $distance
            : $otherX + $distance;
    }
}
