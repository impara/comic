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

            // Check if all phases are complete
            if ($state['processes'][StateManager::PHASE_NLP]['status'] === 'completed' &&
                $state['processes'][StateManager::PHASE_CHARACTERS]['status'] === 'completed' &&
                $state['processes'][StateManager::PHASE_BACKGROUNDS]['status'] === 'completed') {
                
                $this->logger->debug('All phases completed, starting final composition', [
                    'job_id' => $jobId
                ]);
                
                // Collect all panel URLs before completing
                $panels = $state['processes'][StateManager::PHASE_NLP]['result'] ?? [];
                $backgrounds = $state['processes'][StateManager::PHASE_BACKGROUNDS]['items'] ?? [];
                $characters = $state['options']['characters'] ?? [];
                
                // Compose final panels
                $composedPanels = [];
                foreach ($panels as &$panel) {
                    if (isset($backgrounds[$panel['id']])) {
                        // Get background URL
                        $backgroundUrl = $backgrounds[$panel['id']]['background_url'];
                        
                        // For now, just use the first character's URL
                        // TODO: Support multiple characters per panel
                        $characterUrl = $characters[0]['cartoonify_url'] ?? null;
                        if (!$characterUrl) {
                            $this->logger->error('No character URL found for panel', [
                                'panel_id' => $panel['id']
                            ]);
                            continue;
                        }
                        
                        try {
                            // Compose panel with background and character
                            $composedUrl = $this->imageComposer->composePanelImage(
                                $backgroundUrl,
                                $characterUrl,
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
                            $this->logger->error('Panel composition failed', [
                                'panel_id' => $panel['id'],
                                'error' => $e->getMessage()
                            ]);
                            continue;
                        }
                    }
                }
                
                if (empty($composedPanels)) {
                    throw new Exception('No panels were successfully composed');
                }
                
                // First update the backgrounds phase with composed panels
                $this->stateManager->updatePhase($jobId, StateManager::PHASE_BACKGROUNDS, 'completed', [
                    'result' => $panels,
                    'composed_panels' => $composedPanels
                ]);
                
                // Get the first successfully composed URL
                $outputUrl = $composedPanels[0]['composed_url'] ?? null;
                if (!$outputUrl) {
                    throw new Exception('No valid output URL found after composition');
                }
                
                $this->logger->debug('Transitioning to completed state', [
                    'job_id' => $jobId,
                    'output_url' => $outputUrl
                ]);
                
                // Then transition to completed state with output URL
                $this->stateManager->transitionTo($jobId, StateManager::STATE_COMPLETED);
                
                // Finally update with output URL and panels
                $this->stateManager->updateStripState($jobId, [
                    'phase' => StateManager::PHASE_BACKGROUNDS,
                    'output_url' => $outputUrl,
                    'output' => [
                        'panels' => $composedPanels,
                        'characters' => $characters
                    ]
                ]);
                
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

            if (!$type) {
                throw new Exception('Missing webhook type in metadata');
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
                case 'nlp_complete':
                    // Update NLP phase with results
                    $panels = array_map(function ($segment) {
                        return [
                            'id' => uniqid('panel_'),
                            'description' => $segment,
                            'status' => 'pending',
                            'background_url' => null,
                            'error' => null
                        ];
                    }, $payload['output'] ?? []);

                    $this->stateManager->updatePhase($jobId, StateManager::PHASE_NLP, 'completed', [
                        'result' => $panels
                    ]);
                    break;

                case 'cartoonify_complete':
                    // Update character status
                    $characterId = $metadata['character_id'] ?? null;
                    if (!$characterId) {
                        throw new Exception('Missing character_id in webhook metadata');
                    }

                    $items = $state['processes'][StateManager::PHASE_CHARACTERS]['items'] ?? [];
                    $items[$characterId] = [
                        'status' => 'completed',
                        'cartoonify_url' => $payload['output'][0] ?? null
                    ];

                    $this->stateManager->updatePhase($jobId, StateManager::PHASE_CHARACTERS, 'processing', [
                        'items' => $items
                    ]);

                    // Check if all characters are completed
                    $allCompleted = true;
                    foreach ($items as $item) {
                        if ($item['status'] !== 'completed') {
                            $allCompleted = false;
                            break;
                        }
                    }

                    if ($allCompleted) {
                        $this->stateManager->updatePhase($jobId, StateManager::PHASE_CHARACTERS, 'completed');
                    }
                    break;

                case 'background_complete':
                    // Update background status
                    $panelId = $metadata['panel_id'] ?? null;
                    if (!$panelId) {
                        throw new Exception('Missing panel_id in webhook metadata');
                    }

                    $items = $state['processes'][StateManager::PHASE_BACKGROUNDS]['items'] ?? [];
                    
                    // Skip if this panel is already completed
                    if (isset($items[$panelId]) && $items[$panelId]['status'] === 'completed') {
                        $this->logger->debug('Skipping duplicate background webhook', [
                            'job_id' => $jobId,
                            'panel_id' => $panelId
                        ]);
                        break;
                    }

                    $items[$panelId] = [
                        'status' => 'completed',
                        'background_url' => $payload['output'][0] ?? null
                    ];

                    // Check if all backgrounds are completed
                    $allCompleted = true;
                    $totalPanels = count($state['processes'][StateManager::PHASE_NLP]['result'] ?? []);
                    $completedPanels = 0;
                    
                    foreach ($items as $item) {
                        if ($item['status'] === 'completed') {
                            $completedPanels++;
                        }
                        if ($item['status'] !== 'completed') {
                            $allCompleted = false;
                        }
                    }

                    $this->logger->debug('Background completion status', [
                        'job_id' => $jobId,
                        'completed_panels' => $completedPanels,
                        'total_panels' => $totalPanels,
                        'all_completed' => $allCompleted
                    ]);

                    if ($allCompleted && $completedPanels === $totalPanels) {
                        // All panels are complete, update phase to completed
                        $this->stateManager->updatePhase($jobId, StateManager::PHASE_BACKGROUNDS, 'completed', [
                            'items' => $items
                        ]);
                    } else {
                        // Still processing some panels
                        $this->stateManager->updatePhase($jobId, StateManager::PHASE_BACKGROUNDS, 'processing', [
                            'items' => $items
                        ]);
                    }
                    break;

                default:
                    throw new Exception("Unknown webhook type: $type");
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
                'trace' => $e->getTraceAsString(),
                'payload' => $payload
            ]);
            throw $e;
        }
    }
}
