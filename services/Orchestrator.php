<?php

require_once __DIR__ . '/../models/ComicGenerator.php';
require_once __DIR__ . '/../models/CharacterProcessor.php';
require_once __DIR__ . '/../models/StoryParser.php';
require_once __DIR__ . '/../interfaces/LoggerInterface.php';
require_once __DIR__ . '/../models/Config.php';
require_once __DIR__ . '/../models/ImageComposer.php';

class Orchestrator
{
    private LoggerInterface $logger;
    private ComicGenerator $comicGenerator;
    private CharacterProcessor $characterProcessor;
    private StoryParser $storyParser;
    private string $jobsDirectory;
    private Config $config;
    private StateManager $stateManager;
    private ImageComposer $imageComposer;

    public function __construct(
        LoggerInterface $logger,
        ComicGenerator $comicGenerator,
        CharacterProcessor $characterProcessor,
        StoryParser $storyParser,
        string $jobsDirectory
    ) {
        $this->logger = $logger;
        $this->comicGenerator = $comicGenerator;
        $this->characterProcessor = $characterProcessor;
        $this->storyParser = $storyParser;
        $this->jobsDirectory = rtrim($jobsDirectory, '/');
        $this->config = Config::getInstance();
        $this->stateManager = new StateManager($jobsDirectory, $logger);
        $this->imageComposer = new ImageComposer($logger, $this->config);

        if (!file_exists($this->jobsDirectory)) {
            if (!mkdir($this->jobsDirectory, 0775, true)) {
                throw new RuntimeException("Failed to create jobs directory: {$this->jobsDirectory}");
            }
        }

        if (!is_writable($this->jobsDirectory)) {
            throw new RuntimeException("Jobs directory is not writable: {$this->jobsDirectory}");
        }
    }

    /**
     * Start a new comic generation job
     */
    public function startJob(array $requestData): array
    {
        try {
            // Validate request data
            if (empty($requestData['story'])) {
                throw new InvalidArgumentException('Story is required');
            }
            if (empty($requestData['characters']) || !is_array($requestData['characters'])) {
                throw new InvalidArgumentException('Characters array is required');
            }
            foreach ($requestData['characters'] as $index => $character) {
                if (empty($character['name'])) {
                    throw new InvalidArgumentException("Character at index $index is missing name");
                }
                if (empty($character['image'])) {
                    throw new InvalidArgumentException("Character at index $index is missing image");
                }
                if (empty($character['id'])) {
                    throw new InvalidArgumentException("Character at index $index is missing id");
                }
            }

            // Generate unique job ID
            $jobId = 'job_' . uniqid('', true);

            // Initialize job with characters
            $result = $this->comicGenerator->initializeComicStrip(
                $jobId,
                $requestData['story'],
                $requestData['characters'],
                [
                    'style' => $requestData['style'] ?? 'default',
                    'background' => $requestData['background'] ?? 'default'
                ]
            );

            if (!$result['success']) {
                throw new Exception($result['error'] ?? 'Failed to initialize comic generation');
            }

            // Start processing
            try {
                $this->handleNextStep($jobId);
            } catch (Exception $e) {
                $this->logger->error('Failed to start job processing', [
                    'job_id' => $jobId,
                    'error' => $e->getMessage()
                ]);
                $this->stateManager->handleError($jobId, StateManager::PHASE_NLP, $e->getMessage());
            }

            $state = $this->stateManager->getStripState($jobId);
            return [
                'success' => true,
                'data' => [
                    'job_id' => $jobId,
                    'status' => $state['status'],
                    'progress' => $state['progress']
                ]
            ];
        } catch (Exception $e) {
            $this->logger->error('Failed to start job', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Handle the next step in the job processing pipeline
     */
    public function handleNextStep(string $jobId): void
    {
        $state = $this->stateManager->getStripState($jobId);
        if (!$state) {
            throw new Exception('Job not found');
        }

        $this->logger->debug('Processing next step', [
            'job_id' => $jobId,
            'status' => $state['status'],
            'phase' => $state['phase'] ?? null,
            'progress' => $state['progress']
        ]);

        // Handle initial state
        if ($state['status'] === StateManager::STATE_INIT) {
            $this->stateManager->transitionTo($jobId, StateManager::STATE_PROCESSING);
            $this->processNLP($jobId, $state);
            return;
        }

        // Handle processing state
        if ($state['status'] === StateManager::STATE_PROCESSING) {
            // Check NLP phase
            if ($state['processes'][StateManager::PHASE_NLP]['status'] === 'completed' &&
                $state['processes'][StateManager::PHASE_CHARACTERS]['status'] === 'pending') {
                $this->processCharacters($jobId, $state);
                return;
            }

            // Check characters phase
            if ($state['processes'][StateManager::PHASE_CHARACTERS]['status'] === 'completed' &&
                $state['processes'][StateManager::PHASE_BACKGROUNDS]['status'] === 'pending') {
                $this->processBackgrounds($jobId, $state);
                return;
            }

            // Check if all phases are complete and ready for composition
            if ($state['processes'][StateManager::PHASE_NLP]['status'] === 'completed' &&
                $state['processes'][StateManager::PHASE_CHARACTERS]['status'] === 'completed') {
                
                // Check if all backgrounds are complete
                $backgrounds = $state['processes'][StateManager::PHASE_BACKGROUNDS]['items'] ?? [];
                if ($this->areAllPanelsComplete($backgrounds)) {
                    // Mark backgrounds phase as completed
                    $this->stateManager->updatePhase($jobId, StateManager::PHASE_BACKGROUNDS, 'completed');
                    
                    // Start final composition
                    $this->logger->debug('Starting final composition', [
                        'job_id' => $jobId
                    ]);
                    
                    try {
                        // Collect all panel URLs before completing
                        $panels = $state['processes'][StateManager::PHASE_NLP]['result'] ?? [];
                        $backgrounds = $state['processes'][StateManager::PHASE_BACKGROUNDS]['items'] ?? [];
                        $characters = $state['options']['characters'] ?? [];
                        
                        // Compose final panels
                        $composedPanels = [];
                        $compositionErrors = [];
                        
                        foreach ($panels as &$panel) {
                            if (isset($backgrounds[$panel['id']])) {
                                try {
                                    // Get background URL
                                    $backgroundUrl = $backgrounds[$panel['id']]['background_url'];
                                    if (!$backgroundUrl) {
                                        throw new Exception('No background URL found');
                                    }

                                    // Create character map
                                    $characterMap = [];
                                    foreach ($characters as $character) {
                                        if (!empty($character['cartoonify_url'])) {
                                            $characterMap[] = [
                                                'cartoonify_url' => $character['cartoonify_url'],
                                                'position' => null // Let ImageComposer handle positioning
                                            ];
                                        }
                                    }

                                    if (empty($characterMap)) {
                                        throw new Exception('No valid character URLs found');
                                    }

                                    // Compose panel with background and character
                                    $composedUrl = $this->imageComposer->composePanelImage(
                                        $backgroundUrl,
                                        $characterMap,
                                        $panel['id']
                                    );

                                    // Update panel with composed URL and status
                                    $panel['background_url'] = $backgroundUrl;
                                    $panel['composed_url'] = $composedUrl;
                                    $panel['status'] = 'completed';

                                    $composedPanels[] = [
                                        'id' => $panel['id'],
                                        'description' => $panel['description'],
                                        'background_url' => $backgroundUrl,
                                        'composed_url' => $composedUrl
                                    ];

                                    $this->logger->debug('Panel composition completed', [
                                        'panel_id' => $panel['id'],
                                        'composed_url' => $composedUrl
                                    ]);
                                } catch (Exception $e) {
                                    $compositionErrors[] = [
                                        'panel_id' => $panel['id'],
                                        'error' => $e->getMessage()
                                    ];
                                    $this->logger->error('Panel composition failed', [
                                        'panel_id' => $panel['id'],
                                        'error' => $e->getMessage()
                                    ]);
                                    continue;
                                }
                            }
                        }
                        
                        if (empty($composedPanels)) {
                            throw new Exception('No panels were successfully composed: ' . 
                                json_encode($compositionErrors));
                        }
                        
                        // Get the first successfully composed URL
                        $outputUrl = $composedPanels[0]['composed_url'] ?? null;
                        if (!$outputUrl) {
                            throw new Exception('No valid output URL found after composition');
                        }
                        
                        // Transition to completed state with output URL
                        $this->stateManager->transitionTo($jobId, StateManager::STATE_COMPLETED);
                        
                        // Update final state with output URL and panels
                        $this->stateManager->updateStripState($jobId, [
                            'phase' => StateManager::PHASE_BACKGROUNDS,
                            'output_url' => $outputUrl,
                            'output' => [
                                'panels' => $composedPanels,
                                'characters' => $characters,
                                'errors' => $compositionErrors
                            ]
                        ]);
                        
                        $this->logger->debug('Job completed successfully', [
                            'job_id' => $jobId,
                            'output_url' => $outputUrl,
                            'total_panels' => count($composedPanels),
                            'error_count' => count($compositionErrors)
                        ]);
                        
                    } catch (Exception $e) {
                        $this->logger->error('Final composition failed', [
                            'job_id' => $jobId,
                            'error' => $e->getMessage()
                        ]);
                        
                        // Update state to error
                        $this->stateManager->handleError($jobId, StateManager::PHASE_BACKGROUNDS, $e->getMessage());
                        throw $e;
                    }
                }
                return;
            }
        }
    }

    /**
     * Process NLP phase
     */
    private function processNLP(string $jobId, array $state): void
    {
        $this->stateManager->updatePhase($jobId, StateManager::PHASE_NLP, 'processing');

        $result = $this->storyParser->parseStory($state['options']['story'], $jobId);
        if (!isset($result['id'])) {
            throw new Exception('Missing prediction ID in NLP processor response');
        }

        // Update NLP phase with prediction ID
        $this->stateManager->updatePhase($jobId, StateManager::PHASE_NLP, 'processing', [
            'prediction_id' => $result['id']
        ]);
    }

    /**
     * Process characters phase
     */
    private function processCharacters(string $jobId, array $state): void
    {
        try {
            $this->logger->info('Processing characters', [
                'job_id' => $jobId,
                'character_count' => count($state['options']['characters'] ?? [])
            ]);

            // Update phase status to processing
            $this->stateManager->updatePhase($jobId, StateManager::PHASE_CHARACTERS, 'processing');

            // Process each character
            foreach ($state['options']['characters'] as $character) {
                if ($character['status'] === 'pending') {
                    $result = $this->characterProcessor->processCharacter($character, $jobId);
                    if (!isset($result['id'])) {
                        throw new Exception('Missing prediction ID in character processor response');
                    }

                    // Update character status in state
                    $items = $state['processes'][StateManager::PHASE_CHARACTERS]['items'] ?? [];
                    $items[$character['id']] = [
                        'status' => 'processing',
                        'prediction_id' => $result['id']
                    ];

                    $this->stateManager->updatePhase($jobId, StateManager::PHASE_CHARACTERS, 'processing', [
                        'items' => $items
                    ]);
                }
            }
        } catch (Exception $e) {
            $this->logger->error('Character processing failed', [
                'job_id' => $jobId,
                'error' => $e->getMessage()
            ]);
            $this->stateManager->handleError($jobId, StateManager::PHASE_CHARACTERS, $e->getMessage());
            throw $e;
        }
    }

    /**
     * Process backgrounds phase
     */
    private function processBackgrounds(string $jobId, array $state): void
    {
        $this->stateManager->updatePhase($jobId, StateManager::PHASE_BACKGROUNDS, 'processing');

        $panels = $state['processes'][StateManager::PHASE_NLP]['result'] ?? [];
        foreach ($panels as $panel) {
            if ($panel['status'] === 'pending') {
                try {
                    $result = $this->comicGenerator->generatePanelBackground(
                        $jobId,
                        $panel['description'],
                        $state['options']['style'],
                        $state['options']['background'],
                        ['panel_id' => $panel['id']]
                    );

                    // Update panel status
                    $items = $state['processes'][StateManager::PHASE_BACKGROUNDS]['items'] ?? [];
                    $items[$panel['id']] = [
                        'status' => 'processing',
                        'prediction_id' => $result['id']
                    ];

                    $this->stateManager->updatePhase($jobId, StateManager::PHASE_BACKGROUNDS, 'processing', [
                        'items' => $items
                    ]);
                } catch (Exception $e) {
                    $this->logger->error('Panel background generation failed', [
                        'panel_id' => $panel['id'],
                        'error' => $e->getMessage()
                    ]);
                    throw $e;
                }
            }
        }
    }

    /**
     * Check if all panels in a phase are complete
     */
    private function areAllPanelsComplete(array $items): bool
    {
        foreach ($items as $item) {
            if ($item['status'] !== 'completed') {
                return false;
            }
        }
        return !empty($items);
    }

    /**
     * Start the composition phase
     */
    private function startComposition(string $jobId, array $state): void
    {
        try {
            $this->logger->debug('Starting composition phase', [
                'job_id' => $jobId
            ]);

            // Get required data
            $panels = $state['processes'][StateManager::PHASE_NLP]['result'] ?? [];
            $backgrounds = $state['processes'][StateManager::PHASE_BACKGROUNDS]['items'] ?? [];
            $characters = $state['options']['characters'] ?? [];

            // Compose panels
            $composedPanels = [];
            $compositionErrors = [];

            foreach ($panels as $panel) {
                try {
                    // Get background URL
                    $backgroundUrl = $backgrounds[$panel['id']]['background_url'] ?? null;
                    if (!$backgroundUrl) {
                        throw new Exception('No background URL found');
                    }

                    // Get character URL
                    $characterUrl = $characters[0]['cartoonify_url'] ?? null;
                    if (!$characterUrl) {
                        throw new Exception('No character URL found');
                    }

                    // Compose panel
                    $composedUrl = $this->imageComposer->composePanelImage(
                        $backgroundUrl,
                        array_map(function($character) {
                            return [
                                'cartoonify_url' => $character['cartoonify_url'],
                                'position' => ['x' => 0, 'y' => 0] // Default position
                            ];
                        }, $characters),
                        $panel['id']
                    );

                    $composedPanels[] = [
                        'id' => $panel['id'],
                        'description' => $panel['description'],
                        'background_url' => $backgroundUrl,
                        'composed_url' => $composedUrl
                    ];

                } catch (Exception $e) {
                    $compositionErrors[] = [
                        'panel_id' => $panel['id'],
                        'error' => $e->getMessage()
                    ];
                    $this->logger->error('Panel composition failed', [
                        'panel_id' => $panel['id'],
                        'error' => $e->getMessage()
                    ]);
                }
            }

            if (empty($composedPanels)) {
                throw new Exception('No panels were successfully composed');
            }

            // Get the first composed URL as output
            $outputUrl = $composedPanels[0]['composed_url'] ?? null;
            if (!$outputUrl) {
                throw new Exception('No valid output URL found');
            }

            // Update state with results
            $this->stateManager->updateStripState($jobId, [
                'status' => StateManager::STATE_COMPLETED,
                'phase' => StateManager::PHASE_COMPOSITION,
                'output_url' => $outputUrl,
                'output' => [
                    'panels' => $composedPanels,
                    'errors' => $compositionErrors
                ]
            ]);

        } catch (Exception $e) {
            $this->logger->error('Composition failed', [
                'job_id' => $jobId,
                'error' => $e->getMessage()
            ]);
            $this->stateManager->handleError($jobId, StateManager::PHASE_COMPOSITION, $e->getMessage());
            throw $e;
        }
    }

    /**
     * Handle webhook callback from external services
     */
    public function onWebhookReceived(array $payload): array
    {
        try {
            $metadata = $payload['input']['metadata'] ?? [];
            $type = $metadata['type'] ?? null;
            $jobId = $metadata['job_id'] ?? null;

            if (!$jobId) {
                throw new Exception('Missing job_id in webhook payload');
            }

            $state = $this->stateManager->getStripState($jobId);
            if (!$state) {
                throw new Exception("Job state not found: $jobId");
            }

            $this->logger->debug('Processing webhook', [
                'job_id' => $jobId,
                'type' => $type,
                'metadata' => $metadata
            ]);

            switch ($type) {
                case 'background_complete':
                    // Update background status
                    $panelId = $metadata['panel_id'] ?? null;
                    if (!$panelId) {
                        throw new Exception('Missing panel_id in webhook metadata');
                    }

                    $items = $state['processes'][StateManager::PHASE_BACKGROUNDS]['items'] ?? [];
                    
                    // Skip if already completed
                    if (isset($items[$panelId]) && $items[$panelId]['status'] === 'completed') {
                        return [
                            'status' => $state['status'],
                            'phase' => $state['phase'],
                            'progress' => $state['progress']
                        ];
                    }

                    // Just update the panel status
                    $items[$panelId] = [
                        'status' => 'completed',
                        'background_url' => $payload['output'][0] ?? null
                    ];

                    // Update items and check completion
                    $this->stateManager->updatePhase($jobId, StateManager::PHASE_BACKGROUNDS, 'processing', [
                        'items' => $items
                    ]);

                    // If all panels complete, move to composition
                    if ($this->areAllPanelsComplete($items)) {
                        $this->stateManager->updatePhase($jobId, StateManager::PHASE_COMPOSITION, 'pending');
                    }
                    break;

                // ... other webhook cases remain the same ...
            }

            // Process next step
            $this->handleNextStep($jobId);

            // Return updated state
            $updatedState = $this->stateManager->getStripState($jobId);
            return [
                'status' => $updatedState['status'],
                'phase' => $updatedState['phase'],
                'progress' => $updatedState['progress']
            ];

        } catch (Exception $e) {
            $this->logger->error('Failed to process webhook', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
}
