<?php

require_once __DIR__ . '/../models/ComicGenerator.php';
require_once __DIR__ . '/../models/CharacterProcessor.php';
require_once __DIR__ . '/../models/StoryParser.php';
require_once __DIR__ . '/../interfaces/LoggerInterface.php';
require_once __DIR__ . '/../models/Config.php';

class Orchestrator
{
    private const JOB_STATUS_LLAMA_PENDING = 'llama_pending';
    private const JOB_STATUS_LLAMA_IN_PROGRESS = 'llama_in_progress';
    private const JOB_STATUS_CARTOONIFY_PENDING = 'cartoonify_pending';
    private const JOB_STATUS_CARTOONIFY_IN_PROGRESS = 'cartoonify_in_progress';
    private const JOB_STATUS_BACKGROUND_PENDING = 'background_pending';
    private const JOB_STATUS_BACKGROUND_IN_PROGRESS = 'background_in_progress';
    private const JOB_STATUS_COMPLETED = 'completed';
    private const JOB_STATUS_FAILED = 'failed';

    private LoggerInterface $logger;
    private ComicGenerator $comicGenerator;
    private CharacterProcessor $characterProcessor;
    private StoryParser $storyParser;
    private string $jobsDirectory;
    private Config $config;

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

        // Ensure jobs directory exists with proper permissions
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
     * @param array $requestData The request data containing story and characters
     * @return array The response containing job status and ID
     * @throws Exception If the job cannot be started
     */
    public function startJob(array $requestData): array
    {
        try {
            $this->logger->debug('Starting new job', [
                'request_data' => $requestData
            ]);

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
                if (empty($character['description'])) {
                    throw new InvalidArgumentException("Character at index $index is missing description");
                }
            }

            // Generate unique job ID
            $jobId = uniqid('job_', true);

            // Initialize job data
            $jobData = [
                'id' => $jobId,
                'created_at' => time(),
                'updated_at' => time(),
                'status' => self::JOB_STATUS_LLAMA_PENDING,
                'progress' => 0,
                'request' => $requestData,
                'story' => $requestData['story'],
                'characters' => array_map(function ($char) {
                    return [
                        'name' => $char['name'],
                        'description' => $char['description'],
                        'status' => 'pending',
                        'cartoonify_url' => null,
                        'error' => null
                    ];
                }, $requestData['characters']),
                'style' => $requestData['style'] ?? 'default',
                'background' => $requestData['background'] ?? 'default',
                'panels' => [],
                'error' => null,
                'output_url' => null
            ];

            // Save initial job state
            $this->saveJobState($jobId, $jobData);

            // Verify the job file was created
            $jobFile = $this->getJobFilePath($jobId);
            if (!file_exists($jobFile)) {
                throw new RuntimeException("Failed to create job file: $jobFile");
            }

            $this->logger->info('Job created successfully', [
                'job_id' => $jobId,
                'status' => $jobData['status'],
                'file_path' => $jobFile
            ]);

            // Start processing asynchronously
            try {
                $this->handleNextStep($jobId);
            } catch (Exception $e) {
                $this->logger->error('Failed to start job processing', [
                    'job_id' => $jobId,
                    'error' => $e->getMessage()
                ]);

                // Update job status to failed
                $jobData['status'] = self::JOB_STATUS_FAILED;
                $jobData['error'] = $e->getMessage();
                $this->saveJobState($jobId, $jobData);
            }

            return [
                'success' => true,
                'data' => [
                    'job_id' => $jobId,
                    'status' => $jobData['status']
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
        try {
            $jobData = $this->loadJobState($jobId);
            if (!$jobData) {
                throw new Exception("Job not found: $jobId");
            }

            $this->logger->debug('Processing next step', [
                'job_id' => $jobId,
                'current_status' => $jobData['status']
            ]);

            switch ($jobData['status']) {
                case self::JOB_STATUS_LLAMA_PENDING:
                    // Start Llama processing for story segmentation
                    $jobData['status'] = self::JOB_STATUS_LLAMA_IN_PROGRESS;
                    $this->saveJobState($jobId, $jobData);

                    $result = $this->storyParser->segmentStory($jobData['story'], [
                        'job_id' => $jobId,
                        'style' => $jobData['style'],
                        'panel_count' => 4,
                        'characters' => $jobData['characters'],
                        'webhook' => rtrim($this->config->getBaseUrl(), '/') . '/webhook.php'
                    ]);

                    // Update job with segments
                    if ($result['status'] === 'completed') {
                        $jobData['panels'] = array_map(function ($segment) {
                            return [
                                'id' => uniqid('panel_'),
                                'description' => $segment,
                                'status' => 'pending',
                                'background_url' => null,
                                'error' => null
                            ];
                        }, $result['segments']);
                        $jobData['status'] = self::JOB_STATUS_CARTOONIFY_PENDING;
                        $jobData['progress'] = 25;
                    } else {
                        $jobData['status'] = self::JOB_STATUS_FAILED;
                        $jobData['error'] = $result['error'] ?? 'Story segmentation failed';
                    }
                    break;

                case self::JOB_STATUS_CARTOONIFY_PENDING:
                    // Start cartoonify for each character
                    $jobData['status'] = self::JOB_STATUS_CARTOONIFY_IN_PROGRESS;
                    $this->saveJobState($jobId, $jobData);

                    $pendingCharacters = false;
                    foreach ($jobData['characters'] as &$character) {
                        if ($character['status'] === 'pending') {
                            try {
                                $result = $this->characterProcessor->processCharacter($character, $jobId);
                                $character['status'] = 'processing';
                                $character['prediction_id'] = $result['prediction_id'] ?? null;
                                $pendingCharacters = true;
                            } catch (Exception $e) {
                                $character['status'] = 'failed';
                                $character['error'] = $e->getMessage();
                                $this->logger->error('Character processing failed', [
                                    'character' => $character['name'],
                                    'error' => $e->getMessage()
                                ]);
                            }
                        } elseif ($character['status'] === 'processing') {
                            $pendingCharacters = true;
                        }
                    }

                    // Update progress
                    $completedCharacters = count(array_filter($jobData['characters'], fn($c) => $c['status'] === 'completed'));
                    $totalCharacters = count($jobData['characters']);
                    $jobData['progress'] = 25 + (25 * ($completedCharacters / $totalCharacters));

                    // If no characters are pending or processing, move to next stage
                    if (!$pendingCharacters) {
                        $allSuccessful = !array_filter($jobData['characters'], fn($c) => $c['status'] === 'failed');
                        if ($allSuccessful) {
                            $jobData['status'] = self::JOB_STATUS_BACKGROUND_PENDING;
                        } else {
                            $jobData['status'] = self::JOB_STATUS_FAILED;
                            $jobData['error'] = 'One or more characters failed to process';
                        }
                    }
                    break;

                case self::JOB_STATUS_BACKGROUND_PENDING:
                    // Start background generation for each panel
                    $jobData['status'] = self::JOB_STATUS_BACKGROUND_IN_PROGRESS;
                    $this->saveJobState($jobId, $jobData);

                    $pendingPanels = false;
                    foreach ($jobData['panels'] as &$panel) {
                        if ($panel['status'] === 'pending') {
                            try {
                                $result = $this->comicGenerator->generatePanelBackground(
                                    $panel['id'],
                                    $panel['description'],
                                    $jobData['style'],
                                    $jobData['background']
                                );
                                $panel['status'] = 'processing';
                                $panel['prediction_id'] = $result['prediction_id'] ?? null;
                                $pendingPanels = true;
                            } catch (Exception $e) {
                                $panel['status'] = 'failed';
                                $panel['error'] = $e->getMessage();
                                $this->logger->error('Panel background generation failed', [
                                    'panel_id' => $panel['id'],
                                    'error' => $e->getMessage()
                                ]);
                            }
                        } elseif ($panel['status'] === 'processing') {
                            $pendingPanels = true;
                        }
                    }

                    // Update progress
                    $completedPanels = count(array_filter($jobData['panels'], fn($p) => $p['status'] === 'completed'));
                    $totalPanels = count($jobData['panels']);
                    $jobData['progress'] = 50 + (50 * ($completedPanels / $totalPanels));

                    // If no panels are pending or processing, move to next stage
                    if (!$pendingPanels) {
                        $allSuccessful = !array_filter($jobData['panels'], fn($p) => $p['status'] === 'failed');
                        if ($allSuccessful) {
                            $jobData['status'] = self::JOB_STATUS_COMPLETED;
                            $jobData['progress'] = 100;
                        } else {
                            $jobData['status'] = self::JOB_STATUS_FAILED;
                            $jobData['error'] = 'One or more panels failed to generate';
                        }
                    }
                    break;
            }

            $this->saveJobState($jobId, $jobData);
        } catch (Exception $e) {
            $this->logger->error('Failed to handle next step', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            try {
                $jobData['status'] = self::JOB_STATUS_FAILED;
                $jobData['error'] = $e->getMessage();
                $this->saveJobState($jobId, $jobData);
            } catch (Exception $saveError) {
                $this->logger->error('Failed to save error state', [
                    'job_id' => $jobId,
                    'error' => $saveError->getMessage()
                ]);
            }
        }
    }

    /**
     * Handle webhook callbacks from various services
     */
    public function onWebhookReceived(array $webhookPayload): void
    {
        try {
            $jobId = $webhookPayload['job_id'] ?? null;
            if (!$jobId) {
                throw new Exception('Missing job_id in webhook payload');
            }

            $jobData = $this->loadJobState($jobId);
            if (!$jobData) {
                throw new Exception("Job not found: $jobId");
            }

            switch ($webhookPayload['type']) {
                case 'llama_complete':
                    $jobData['panels'] = array_map(function ($segment) {
                        return [
                            'id' => uniqid('panel_'),
                            'description' => $segment,
                            'status' => 'pending',
                            'background_url' => null,
                            'error' => null
                        ];
                    }, $webhookPayload['segments']);
                    $jobData['status'] = self::JOB_STATUS_CARTOONIFY_PENDING;
                    break;

                case 'cartoonify_complete':
                    $characterName = $webhookPayload['character_name'];
                    foreach ($jobData['characters'] as &$character) {
                        if ($character['name'] === $characterName) {
                            $character['status'] = 'completed';
                            $character['cartoonify_url'] = $webhookPayload['output_url'];
                        }
                    }

                    // Check if all characters are done
                    $allComplete = true;
                    foreach ($jobData['characters'] as $character) {
                        if ($character['status'] !== 'completed') {
                            $allComplete = false;
                            break;
                        }
                    }

                    if ($allComplete) {
                        $jobData['status'] = self::JOB_STATUS_BACKGROUND_PENDING;
                    }
                    break;

                case 'background_complete':
                    $panelId = $webhookPayload['panel_id'];
                    foreach ($jobData['panels'] as &$panel) {
                        if ($panel['id'] === $panelId) {
                            $panel['status'] = 'completed';
                            $panel['background_url'] = $webhookPayload['output_url'];
                        }
                    }

                    // Check if all panels are done
                    $allComplete = true;
                    foreach ($jobData['panels'] as $panel) {
                        if ($panel['status'] !== 'completed') {
                            $allComplete = false;
                            break;
                        }
                    }

                    if ($allComplete) {
                        $jobData['status'] = self::JOB_STATUS_COMPLETED;
                    }
                    break;
            }

            $this->saveJobState($jobId, $jobData);

            // If not completed or failed, trigger next step
            if (!in_array($jobData['status'], [self::JOB_STATUS_COMPLETED, self::JOB_STATUS_FAILED])) {
                $this->handleNextStep($jobId);
            }
        } catch (Exception $e) {
            $this->logger->error('Failed to process webhook', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payload' => $webhookPayload
            ]);
        }
    }

    /**
     * Get the file path for a job's state file
     * @param string $jobId The job ID
     * @return string The full path to the job file
     */
    private function getJobFilePath(string $jobId): string
    {
        return $this->jobsDirectory . '/' . $jobId . '.json';
    }

    /**
     * Load job state from JSON file
     */
    private function loadJobState(string $jobId): ?array
    {
        $filePath = $this->getJobFilePath($jobId);

        if (!file_exists($filePath)) {
            $this->logger->error('Job state file not found', [
                'job_id' => $jobId,
                'file_path' => $filePath,
                'jobs_directory' => $this->jobsDirectory,
                'directory_exists' => file_exists($this->jobsDirectory),
                'directory_writable' => is_writable($this->jobsDirectory),
                'files_in_directory' => scandir($this->jobsDirectory)
            ]);
            return null;
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new Exception("Failed to read job state from file: $filePath");
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON in job state file: " . json_last_error_msg());
        }

        return $data;
    }

    /**
     * Get the current state of a job
     * @param string $jobId The job ID
     * @return array|null The job data or null if not found
     */
    public function getJobState(string $jobId): ?array
    {
        $jobFile = $this->getJobFilePath($jobId);
        if (!file_exists($jobFile)) {
            return null;
        }

        try {
            $jobData = json_decode(file_get_contents($jobFile), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException('Invalid job data: ' . json_last_error_msg());
            }
            return $jobData;
        } catch (Exception $e) {
            $this->logger->error('Failed to read job state', [
                'job_id' => $jobId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Save the state of a job
     * @param string $jobId The job ID
     * @param array $jobData The job data to save
     * @throws Exception If the job state cannot be saved
     */
    private function saveJobState(string $jobId, array $jobData): void
    {
        $jobFile = $this->getJobFilePath($jobId);

        // Use atomic write to prevent corruption
        $tempFile = $jobFile . '.tmp';

        // Ensure the job data is complete
        $jobData['updated_at'] = time();

        $jsonData = json_encode($jobData, JSON_PRETTY_PRINT);
        if ($jsonData === false) {
            throw new RuntimeException("Failed to encode job data: " . json_last_error_msg());
        }

        if (file_put_contents($tempFile, $jsonData) === false) {
            throw new RuntimeException("Failed to write job data to temp file: $tempFile");
        }

        if (!rename($tempFile, $jobFile)) {
            unlink($tempFile);
            throw new RuntimeException("Failed to save job state: $jobFile");
        }

        chmod($jobFile, 0664);

        $this->logger->debug('Job state saved', [
            'job_id' => $jobId,
            'file_path' => $jobFile,
            'file_size' => filesize($jobFile),
            'file_exists' => file_exists($jobFile),
            'file_content' => file_get_contents($jobFile)
        ]);
    }
}
