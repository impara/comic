<?php

class StateManager
{
    private $tempPath;
    private $logger;

    // Main states
    const STATE_INIT = 'init';
    const STATE_PROCESSING = 'processing';
    const STATE_COMPLETED = 'completed';
    const STATE_FAILED = 'failed';

    // Process phases
    const PHASE_NLP = 'nlp';
    const PHASE_CHARACTERS = 'characters';
    const PHASE_BACKGROUNDS = 'backgrounds';

    // Lock timeout in seconds
    const LOCK_TIMEOUT = 30;

    public function __construct(string $tempPath, LoggerInterface $logger)
    {
        $this->tempPath = rtrim($tempPath, '/');
        $this->logger = $logger;

        // Ensure temp directory exists
        if (!file_exists($this->tempPath)) {
            mkdir($this->tempPath, 0755, true);
        }
    }

    /**
     * Initialize a new strip state
     */
    public function initializeStrip(string $stripId, array $options = []): void
    {
        $state = [
            'id' => $stripId,
            'status' => self::STATE_INIT,
            'phase' => null,
            'progress' => 0,
            'created_at' => time(),
            'updated_at' => time(),
            'processes' => [
                self::PHASE_NLP => [
                    'status' => 'pending',
                    'result' => null,
                    'error' => null
                ],
                self::PHASE_CHARACTERS => [
                    'status' => 'pending',
                    'items' => [],
                    'error' => null
                ],
                self::PHASE_BACKGROUNDS => [
                    'status' => 'pending',
                    'items' => [],
                    'error' => null
                ]
            ],
            'options' => $options,
            'error' => null
        ];

        $this->saveStripState($stripId, $state);
    }

    /**
     * Transition strip to a new state
     */
    public function transitionTo(string $jobId, string $status): void
    {
        $state = $this->getStripState($jobId);
        if (!$state) {
            throw new Exception('Job not found');
        }

        // Allow transitioning to the same state if we're in processing
        // This is needed because we may need to update progress within the same state
        if ($state['status'] === $status && $status === self::STATE_PROCESSING) {
            return;
        }

        // Validate state transition
        if ($state['status'] === $status) {
            throw new Exception("Invalid state transition from {$state['status']} to {$status}");
        }

        // Update the state
        $state['status'] = $status;
        $this->saveStripState($jobId, $state);
    }

    /**
     * Update phase status and data
     */
    public function updatePhase(string $stripId, string $phase, string $status, array $data = []): void
    {
        $state = $this->getStripState($stripId);
        if (!$state) {
            throw new Exception("Strip state not found: $stripId");
        }

        if (!isset($state['processes'][$phase])) {
            throw new Exception("Invalid phase: $phase");
        }

        $state['processes'][$phase]['status'] = $status;
        if (!empty($data)) {
            $state['processes'][$phase] = array_merge($state['processes'][$phase], $data);
        }

        // Update progress
        $state['progress'] = $this->calculateProgress($state);

        $this->saveStripState($stripId, $state);
    }

    /**
     * Handle error in a specific phase
     */
    public function handleError(string $stripId, string $phase, string $error): void
    {
        $state = $this->getStripState($stripId);
        if (!$state) {
            throw new Exception("Strip state not found: $stripId");
        }

        // Update phase error
        if (isset($state['processes'][$phase])) {
            $state['processes'][$phase]['error'] = $error;
            $state['processes'][$phase]['status'] = 'failed';
        }

        // Set main error and transition to failed state
        $updates = [
            'status' => self::STATE_FAILED,
            'error' => $error,
            'processes' => $state['processes']
        ];

        $this->updateStripState($stripId, $updates);
    }

    /**
     * Calculate overall progress based on phase weights
     */
    private function calculateProgress(array $state): int
    {
        if ($state['status'] === self::STATE_COMPLETED) {
            return 100;
        }

        if ($state['status'] === self::STATE_FAILED) {
            return $state['progress']; // Preserve last progress
        }

        $phaseWeights = [
            self::PHASE_NLP => 0.2,
            self::PHASE_CHARACTERS => 0.4,
            self::PHASE_BACKGROUNDS => 0.4
        ];

        $progress = 0;
        foreach ($state['processes'] as $phase => $process) {
            if ($process['status'] === 'completed') {
                $progress += $phaseWeights[$phase] * 100;
            } elseif ($process['status'] === 'processing' && !empty($process['items'])) {
                $completed = count(array_filter($process['items'], fn($item) => $item['status'] === 'completed'));
                $total = count($process['items']);
                if ($total > 0) {
                    $progress += $phaseWeights[$phase] * ($completed / $total) * 100;
                }
            }
        }

        return (int)$progress;
    }

    /**
     * Check if state transition is valid
     */
    private function isValidTransition(string $currentState, string $newState): bool
    {
        $validTransitions = [
            self::STATE_INIT => [self::STATE_PROCESSING, self::STATE_FAILED],
            self::STATE_PROCESSING => [self::STATE_COMPLETED, self::STATE_FAILED],
            self::STATE_COMPLETED => [],
            self::STATE_FAILED => []
        ];

        return in_array($newState, $validTransitions[$currentState] ?? []);
    }

    /**
     * Get strip state with file locking
     */
    public function getStripState(string $stripId): ?array
    {
        $filePath = $this->getStripFilePath($stripId);
        if (!file_exists($filePath)) {
            return null;
        }

        $lockFile = $filePath . '.lock';
        $fp = null;
        
        try {
            // Clean up stale lock file if it exists and is older than LOCK_TIMEOUT
            if (file_exists($lockFile)) {
                $lockAge = time() - filemtime($lockFile);
                if ($lockAge > self::LOCK_TIMEOUT) {
                    $this->logger->warning('Cleaning up stale lock file', [
                        'job_id' => $stripId,
                        'lock_file' => $lockFile,
                        'age' => $lockAge
                    ]);
                    @unlink($lockFile);
                }
            }
            
            // Try to get an exclusive lock with exponential backoff
            $start = time();
            $wait = 200000; // Start with 0.2 second
            $maxWait = 1000000; // Max 1 second wait between attempts
            
            $fp = fopen($lockFile, 'c+');
            if (!$fp) {
                throw new Exception("Failed to create lock file: $lockFile");
            }

            while (!flock($fp, LOCK_EX | LOCK_NB)) {
                $elapsed = time() - $start;
                if ($elapsed >= self::LOCK_TIMEOUT) {
                    throw new Exception("Failed to acquire lock after " . self::LOCK_TIMEOUT . " seconds");
                }

                $this->logger->debug('Waiting for lock', [
                    'job_id' => $stripId,
                    'wait_time' => $wait,
                    'elapsed' => $elapsed
                ]);

                usleep($wait);
                $wait = min($wait * 2, $maxWait);
            }

            // Read state file
            $content = file_get_contents($filePath);
            if ($content === false) {
                throw new Exception("Failed to read state file: $filePath");
            }

            $state = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("Invalid JSON in state file: " . json_last_error_msg());
            }

            return $state;
        } finally {
            // Release lock and close file
            if ($fp) {
                flock($fp, LOCK_UN);
                fclose($fp);
                // Clean up lock file immediately after use
                @unlink($lockFile);
            }
        }
    }

    /**
     * Update strip state with file locking and atomic updates
     * @param string $stripId The strip ID
     * @param array|callable $updates Either an array of updates or a callback function that receives and returns the state
     * @throws Exception If the update fails
     */
    public function updateStripState(string $stripId, array|callable $updates): bool
    {
        $filePath = $this->getStripFilePath($stripId);
        $lockFile = $filePath . '.lock';
        $fp = null;

        try {
            // Clean up stale lock file if it exists
            if (file_exists($lockFile)) {
                $lockAge = time() - filemtime($lockFile);
                if ($lockAge > self::LOCK_TIMEOUT) {
                    $this->logger->warning('Cleaning up stale lock file', [
                        'job_id' => $stripId,
                        'lock_file' => $lockFile,
                        'age' => $lockAge
                    ]);
                    @unlink($lockFile);
                }
            }

            // Try to get an exclusive lock with exponential backoff
            $start = time();
            $wait = 200000; // Start with 0.2 second
            $maxWait = 1000000; // Max 1 second wait between attempts

            $fp = fopen($lockFile, 'c+');
            if (!$fp) {
                throw new Exception("Failed to create lock file: $lockFile");
            }

            while (!flock($fp, LOCK_EX | LOCK_NB)) {
                $elapsed = time() - $start;
                if ($elapsed >= self::LOCK_TIMEOUT) {
                    throw new Exception("Failed to acquire lock after " . self::LOCK_TIMEOUT . " seconds");
                }

                $this->logger->debug('Waiting for lock', [
                    'job_id' => $stripId,
                    'wait_time' => $wait,
                    'elapsed' => $elapsed
                ]);

                usleep($wait);
                $wait = min($wait * 2, $maxWait);
            }

            // Read current state directly since we have the lock
            if (!file_exists($filePath)) {
                throw new Exception("Strip state not found: $stripId");
            }

            $content = file_get_contents($filePath);
            if ($content === false) {
                throw new Exception("Failed to read state file: $filePath");
            }

            $state = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("Invalid JSON in state file: " . json_last_error_msg());
            }

            // Apply updates
            if (is_callable($updates)) {
                $state = $updates($state);
                if (!is_array($state)) {
                    throw new Exception("Update callback must return an array");
                }
            } else {
                $state = array_merge($state, $updates, [
                    'updated_at' => time()
                ]);
            }

            // Save updated state
            $content = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if (file_put_contents($filePath, $content) === false) {
                throw new Exception("Failed to write state file: $filePath");
            }

            return true;
        } finally {
            // Release lock and close file
            if ($fp) {
                flock($fp, LOCK_UN);
                fclose($fp);
                // Clean up lock file immediately after use
                @unlink($lockFile);
            }
        }
    }

    /**
     * Save strip state with file locking
     */
    private function saveStripState(string $stripId, array $state): void
    {
        $filePath = $this->getStripFilePath($stripId);
        $lockFile = $filePath . '.lock';
        $fp = null;
        
        try {
            // Clean up stale lock file if it exists
            if (file_exists($lockFile)) {
                $lockAge = time() - filemtime($lockFile);
                if ($lockAge > self::LOCK_TIMEOUT) {
                    $this->logger->warning('Cleaning up stale lock file', [
                        'job_id' => $stripId,
                        'lock_file' => $lockFile,
                        'age' => $lockAge
                    ]);
                    @unlink($lockFile);
                }
            }
            
            // Try to get an exclusive lock with exponential backoff
            $start = time();
            $wait = 200000; // Start with 0.2 second
            $maxWait = 1000000; // Max 1 second wait between attempts
            
            $fp = fopen($lockFile, 'c+');
            if (!$fp) {
                throw new Exception("Failed to create lock file: $lockFile");
            }

            while (!flock($fp, LOCK_EX | LOCK_NB)) {
                $elapsed = time() - $start;
                if ($elapsed >= self::LOCK_TIMEOUT) {
                    throw new Exception("Failed to acquire lock after " . self::LOCK_TIMEOUT . " seconds");
                }

                $this->logger->debug('Waiting for lock', [
                    'job_id' => $stripId,
                    'wait_time' => $wait,
                    'elapsed' => $elapsed
                ]);

                usleep($wait);
                $wait = min($wait * 2, $maxWait);
            }

            // Write state file
            $content = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if (file_put_contents($filePath, $content) === false) {
                throw new Exception("Failed to write state file: $filePath");
            }
        } finally {
            // Release lock and close file
            if ($fp) {
                flock($fp, LOCK_UN);
                fclose($fp);
                // Clean up lock file immediately after use
                @unlink($lockFile);
            }
        }
    }

    /**
     * Get the file path for a strip's state
     */
    private function getStripFilePath(string $stripId): string
    {
        // Ensure the job ID has the correct prefix
        if (strpos($stripId, 'job_') !== 0) {
            $stripId = 'job_' . $stripId;
        }
        return $this->tempPath . "/{$stripId}.json";
    }

    /**
     * Find a job ID by its prediction ID
     */
    public function findJobByPredictionId(string $predictionId): ?string
    {
        $this->logger->debug('Looking for job with prediction ID', [
            'prediction_id' => $predictionId
        ]);

        $files = glob($this->tempPath . '/*.json');
        foreach ($files as $file) {
            $jobId = basename($file, '.json');
            $state = $this->getStripState($jobId);
            
            if (!$state) {
                continue;
            }

            // Check NLP phase
            if (isset($state['processes'][self::PHASE_NLP]['prediction_id']) && 
                $state['processes'][self::PHASE_NLP]['prediction_id'] === $predictionId) {
                $this->logger->debug('Found job for prediction ID in NLP phase', [
                    'prediction_id' => $predictionId,
                    'job_id' => $jobId
                ]);
                return $jobId;
            }

            // Check Characters phase
            if (isset($state['processes'][self::PHASE_CHARACTERS]['prediction_id']) && 
                $state['processes'][self::PHASE_CHARACTERS]['prediction_id'] === $predictionId) {
                $this->logger->debug('Found job for prediction ID in Characters phase', [
                    'prediction_id' => $predictionId,
                    'job_id' => $jobId
                ]);
                return $jobId;
            }

            // Check individual character items
            if (isset($state['processes'][self::PHASE_CHARACTERS]['items'])) {
                foreach ($state['processes'][self::PHASE_CHARACTERS]['items'] as $item) {
                    if (isset($item['prediction_id']) && $item['prediction_id'] === $predictionId) {
                        $this->logger->debug('Found job for prediction ID in character item', [
                            'prediction_id' => $predictionId,
                            'job_id' => $jobId
                        ]);
                        return $jobId;
                    }
                }
            }
        }

        $this->logger->warning('No job found for prediction ID', [
            'prediction_id' => $predictionId,
            'searched_files' => count($files)
        ]);
        return null;
    }
}
