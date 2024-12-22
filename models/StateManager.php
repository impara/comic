<?php

class StateManager
{
    private $tempPath;
    private $logger;
    private $locks = [];

    // State constants
    const STATE_INIT = 'init';
    const STATE_CHARACTERS_PENDING = 'characters_pending';
    const STATE_CHARACTERS_PROCESSING = 'characters_processing';
    const STATE_CHARACTERS_COMPLETE = 'characters_complete';
    const STATE_STORY_SEGMENTING = 'story_segmenting';
    const STATE_PANELS_GENERATING = 'panels_generating';
    const STATE_PANELS_COMPOSING = 'panels_composing';
    const STATE_COMPLETE = 'complete';
    const STATE_FAILED = 'failed';

    // New panel states
    const PANEL_STATE_INIT = 'panel_init';
    const PANEL_STATE_BACKGROUND_PENDING = 'background_pending';
    const PANEL_STATE_BACKGROUND_PROCESSING = 'background_processing';
    const PANEL_STATE_BACKGROUND_READY = 'background_ready';
    const PANEL_STATE_COMPOSING = 'panel_composing';
    const PANEL_STATE_COMPLETE = 'panel_complete';
    const PANEL_STATE_FAILED = 'panel_failed';

    // Legacy status constants for backward compatibility
    const STATUS_INITIALIZING = self::STATE_INIT;
    const STATUS_PROCESSING = self::STATE_PANELS_GENERATING;
    const STATUS_COMPLETED = self::STATE_COMPLETE;
    const STATUS_FAILED = self::STATE_FAILED;

    // Lock timeout in seconds
    const LOCK_TIMEOUT = 5;

    // Define valid state transitions
    private const STATE_TRANSITIONS = [
        self::STATE_INIT => [
            self::STATE_CHARACTERS_PENDING,
            self::STATE_FAILED
        ],
        self::STATE_CHARACTERS_PENDING => [
            self::STATE_CHARACTERS_PROCESSING,
            self::STATE_FAILED
        ],
        self::STATE_CHARACTERS_PROCESSING => [
            self::STATE_CHARACTERS_COMPLETE,
            self::STATE_FAILED
        ],
        self::STATE_CHARACTERS_COMPLETE => [
            self::STATE_STORY_SEGMENTING,
            self::STATE_FAILED
        ],
        self::STATE_STORY_SEGMENTING => [
            self::STATE_PANELS_GENERATING,
            self::STATE_FAILED
        ],
        self::STATE_PANELS_GENERATING => [
            self::STATE_PANELS_COMPOSING,
            self::STATE_CHARACTERS_COMPLETE,
            self::STATE_FAILED
        ],
        self::STATE_PANELS_COMPOSING => [
            self::STATE_COMPLETE,
            self::STATE_FAILED
        ],
        self::STATE_COMPLETE => [],  // Terminal state
        self::STATE_FAILED => []     // Terminal state
    ];

    public function __construct(string $tempPath, LoggerInterface $logger)
    {
        $this->tempPath = rtrim($tempPath, '/');
        $this->logger = $logger;
    }

    /**
     * Atomic read operation with shared lock
     * @throws Exception if lock cannot be acquired
     */
    private function atomicRead(string $path): ?array
    {
        if (!file_exists($path)) {
            return null;
        }

        $fp = fopen($path, 'r');
        if (!$fp) {
            throw new Exception("Could not open state file for reading: $path");
        }

        try {
            $startTime = microtime(true);
            while (!flock($fp, LOCK_SH | LOCK_NB)) {
                if (microtime(true) - $startTime > self::LOCK_TIMEOUT) {
                    throw new Exception("Timeout waiting for read lock on: $path");
                }
                usleep(100000); // 100ms
            }

            $this->logger->debug('Acquired read lock', ['path' => $path]);

            $contents = fread($fp, filesize($path));
            $data = json_decode($contents, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("Invalid JSON in state file: " . json_last_error_msg());
            }

            return $data;
        } finally {
            if ($fp) {
                flock($fp, LOCK_UN);
                fclose($fp);
            }
        }
    }

    /**
     * Atomic write operation with exclusive lock
     * @throws Exception if lock cannot be acquired
     */
    private function atomicWrite(string $path, array $data): void
    {
        $tempPath = $path . '.tmp';
        $fp = fopen($path, 'c+');
        if (!$fp) {
            throw new Exception("Could not open state file for writing: $path");
        }

        try {
            $startTime = microtime(true);
            while (!flock($fp, LOCK_EX | LOCK_NB)) {
                if (microtime(true) - $startTime > self::LOCK_TIMEOUT) {
                    throw new Exception("Timeout waiting for write lock on: $path");
                }
                usleep(100000); // 100ms
            }

            $this->logger->debug('Acquired write lock', ['path' => $path]);

            // Write to temporary file first
            $jsonData = json_encode($data, JSON_PRETTY_PRINT);
            if (file_put_contents($tempPath, $jsonData) === false) {
                throw new Exception("Failed to write temporary state file");
            }

            // Atomic rename
            if (!rename($tempPath, $path)) {
                unlink($tempPath);
                throw new Exception("Failed to update state file");
            }

            $this->logger->debug('State file updated', [
                'path' => $path,
                'size' => strlen($jsonData)
            ]);
        } finally {
            if ($fp) {
                flock($fp, LOCK_UN);
                fclose($fp);
            }
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
        }
    }

    /**
     * Atomic update operation that combines read and write with proper locking
     * @throws Exception if lock cannot be acquired or update fails
     */
    private function atomicUpdate(string $path, callable $updater): array
    {
        $fp = fopen($path, 'c+');
        if (!$fp) {
            throw new Exception("Could not open state file for update: $path");
        }

        try {
            $startTime = microtime(true);
            while (!flock($fp, LOCK_EX | LOCK_NB)) {
                if (microtime(true) - $startTime > self::LOCK_TIMEOUT) {
                    throw new Exception("Timeout waiting for update lock on: $path");
                }
                usleep(100000); // 100ms
            }

            $this->logger->debug('Acquired update lock', ['path' => $path]);

            // Read current state
            $contents = fread($fp, filesize($path));
            $currentState = json_decode($contents, true) ?? [];

            // Apply update
            $newState = $updater($currentState);

            // Write back
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($newState, JSON_PRETTY_PRINT));
            fflush($fp);

            return $newState;
        } finally {
            if ($fp) {
                flock($fp, LOCK_UN);
                fclose($fp);
            }
        }
    }

    /**
     * Validate state transition
     */
    private function validateStateTransition(string $currentState, string $newState): bool
    {
        // Failed state can be reached from any state
        if ($newState === self::STATE_FAILED) {
            return true;
        }

        // Allow character updates during panel generation
        if (
            $currentState === self::STATE_PANELS_GENERATING &&
            ($newState === self::STATE_CHARACTERS_COMPLETE ||
                $newState === self::STATE_STORY_SEGMENTING)
        ) {
            return true;
        }

        // Check if transition is valid
        return isset(self::STATE_TRANSITIONS[$currentState]) &&
            in_array($newState, self::STATE_TRANSITIONS[$currentState]);
    }

    /**
     * Initialize a new strip state
     */
    public function initializeStrip(string $stripId, array $options = []): array
    {
        $state = [
            'id' => $stripId,
            'status' => self::STATE_INIT,
            'started_at' => time(),
            'panels' => [],
            'options' => $options,
            'progress' => 0,
            'state_history' => [
                [
                    'state' => self::STATE_INIT,
                    'timestamp' => time()
                ]
            ]
        ];

        $this->saveStripState($stripId, $state);
        return $state;
    }

    /**
     * Initialize a new panel state
     */
    public function initializePanel(string $stripId, string $panelId, string $description, array $options = []): array
    {
        $state = [
            'id' => $panelId,
            'strip_id' => $stripId,
            'description' => $description,
            'status' => self::PANEL_STATE_INIT,
            'options' => $options,
            'created_at' => time(),
            'updated_at' => time(),
            'progress' => 0
        ];

        $this->savePanelState($panelId, $state);
        return $state;
    }

    /**
     * Get strip state
     */
    public function getStripState(string $stripId): ?array
    {
        $path = $this->getStripStatePath($stripId);
        return $this->atomicRead($path);
    }

    /**
     * Get panel state
     */
    public function getPanelState(string $panelId): ?array
    {
        $path = $this->getPanelStatePath($panelId);
        return $this->atomicRead($path);
    }

    /**
     * Save strip state
     */
    private function saveStripState(string $stripId, array $state): void
    {
        $path = $this->getStripStatePath($stripId);
        $this->atomicWrite($path, $state);
    }

    /**
     * Save panel state
     */
    private function savePanelState(string $panelId, array $state): void
    {
        $path = $this->getPanelStatePath($panelId);
        $this->atomicWrite($path, $state);
    }

    /**
     * Update strip state with validation
     */
    public function updateStripState(string $stripId, array $update, bool $skipProgressUpdate = false): array
    {
        $path = $this->getStripStatePath($stripId);
        return $this->atomicUpdate($path, function ($state) use ($stripId, $update, $skipProgressUpdate) {
            $currentState = $state['status'] ?? self::STATE_INIT;

            // If new status is provided, validate transition
            if (isset($update['status']) && $update['status'] !== $currentState) {
                if (!$this->validateStateTransition($currentState, $update['status'])) {
                    $this->logger->error('Invalid state transition attempted', [
                        'strip_id' => $stripId,
                        'from_state' => $currentState,
                        'to_state' => $update['status']
                    ]);
                    throw new Exception("Invalid state transition from {$currentState} to {$update['status']}");
                }

                // Add to state history
                if (!isset($state['state_history'])) {
                    $state['state_history'] = [];
                }
                $state['state_history'][] = [
                    'state' => $update['status'],
                    'timestamp' => time()
                ];
            }

            // Handle panel updates separately to maintain structure
            if (isset($update['panels'])) {
                foreach ($update['panels'] as $panelId => $panelUpdate) {
                    if (!isset($state['panels'][$panelId])) {
                        $state['panels'][$panelId] = [];
                    }
                    $state['panels'][$panelId] = array_merge($state['panels'][$panelId], $panelUpdate);
                }
                unset($update['panels']);
            }

            // Merge remaining updates
            $newState = array_merge($state, $update, ['updated_at' => time()]);

            // Update progress if needed
            if (!$skipProgressUpdate && !empty($newState['panels'])) {
                $this->updateProgress($stripId, $newState);
            }

            return $newState;
        });
    }

    /**
     * Update panel state
     */
    public function updatePanelState(string $panelId, array $update): array
    {
        $path = $this->getPanelStatePath($panelId);
        return $this->atomicUpdate($path, function ($state) use ($update) {
            return array_merge($state, $update, ['updated_at' => time()]);
        });
    }

    /**
     * Update progress based on current state
     */
    private function updateProgress(string $stripId, array $state): void
    {
        $progress = 0;

        switch ($state['status']) {
            case self::STATE_CHARACTERS_PENDING:
                $progress = 10;
                break;
            case self::STATE_CHARACTERS_PROCESSING:
                $totalChars = count($state['characters'] ?? []);
                $completedChars = count(array_filter(
                    $state['characters'] ?? [],
                    fn($char) => ($char['status'] ?? '') === 'completed'
                ));
                $progress = $totalChars > 0 ? (20 + ($completedChars / $totalChars) * 30) : 20;
                break;
            case self::STATE_CHARACTERS_COMPLETE:
                $progress = 50;
                break;
            case self::STATE_STORY_SEGMENTING:
                $progress = 60;
                break;
            case self::STATE_PANELS_GENERATING:
                $totalPanels = count($state['panels']);
                $completedPanels = count(array_filter(
                    $state['panels'],
                    fn($panel) => ($panel['status'] ?? '') === 'completed'
                ));
                $progress = $totalPanels > 0 ? (70 + ($completedPanels / $totalPanels) * 20) : 70;
                break;
            case self::STATE_PANELS_COMPOSING:
                $progress = 90;
                break;
            case self::STATE_COMPLETE:
                $progress = 100;
                break;
        }

        $this->updateStripState($stripId, ['progress' => round($progress, 2)], true);
    }

    /**
     * Update strip progress based on panel states
     */
    private function updateStripProgress(string $stripId): void
    {
        $stripState = $this->getStripState($stripId);
        if (!$stripState) {
            return;
        }

        // Get all panel states for this strip
        $panelStates = glob($this->tempPath . 'state_panel_*.json');
        $stripPanels = [];
        $totalProgress = 0;

        foreach ($panelStates as $statePath) {
            $panelState = json_decode(file_get_contents($statePath), true);
            if (isset($panelState['strip_id']) && $panelState['strip_id'] === $stripId) {
                $stripPanels[] = $panelState;
                $totalProgress += $panelState['progress'] ?? 0;
            }
        }

        if (count($stripPanels) > 0) {
            $averageProgress = $totalProgress / count($stripPanels);
            $this->updateStripState($stripId, [
                'panel_progress' => round($averageProgress, 2)
            ], true);
        }
    }

    /**
     * Get strip state file path
     */
    private function getStripStatePath(string $stripId): string
    {
        return $this->tempPath . '/state_' . $stripId . '.json';
    }

    /**
     * Get panel state file path
     */
    private function getPanelStatePath(string $panelId): string
    {
        return $this->tempPath . '/state_panel_' . $panelId . '.json';
    }

    /**
     * Update character processing progress
     */
    public function updateCharacterProgress(string $stripId, string $charId, float $progress): void
    {
        $state = $this->getStripState($stripId);

        if (!isset($state['character_progress'])) {
            $state['character_progress'] = [];
        }

        $state['character_progress'][$charId] = [
            'progress' => round($progress, 2),
            'updated_at' => time()
        ];

        // Calculate overall character processing progress
        $totalProgress = 0;
        $characterCount = count($state['characters'] ?? []);

        if ($characterCount > 0) {
            foreach ($state['character_progress'] as $progress) {
                $totalProgress += $progress['progress'];
            }
            $state['character_processing_progress'] = round($totalProgress / $characterCount, 2);
        }

        $this->saveStripState($stripId, $state);
        $this->logger->info("Character progress updated", [
            'strip_id' => $stripId,
            'char_id' => $charId,
            'progress' => $progress,
            'overall_progress' => $state['character_processing_progress'] ?? 0
        ]);
    }

    /**
     * Get character processing progress
     */
    public function getCharacterProgress(string $stripId, string $charId): ?array
    {
        $state = $this->getStripState($stripId);
        return $state['character_progress'][$charId] ?? null;
    }

    /**
     * Get overall character processing progress
     */
    public function getOverallCharacterProgress(string $stripId): float
    {
        $state = $this->getStripState($stripId);
        return $state['character_processing_progress'] ?? 0.0;
    }

    /**
     * Get all panel states for a strip
     */
    public function getStripPanelStates(string $stripId): array
    {
        $panelStates = glob($this->tempPath . 'state_panel_*.json');
        $stripPanels = [];

        foreach ($panelStates as $statePath) {
            $state = json_decode(file_get_contents($statePath), true);
            if (isset($state['strip_id']) && $state['strip_id'] === $stripId) {
                $stripPanels[] = $state;
            }
        }

        return $stripPanels;
    }

    /**
     * Map internal states to frontend-friendly format
     */
    public function mapStateForApi(array $state): array
    {
        return [
            'status' => $this->mapStatusForFrontend($state['status'] ?? self::STATE_INIT),
            'progress' => $this->calculateOverallProgress($state),
            'characters' => array_map(fn($char) => [
                'status' => $char['status'],
                'image_url' => $char['image_url'] ?? null,
                'error' => $char['error'] ?? null,
                'progress' => $char['progress'] ?? 0
            ], $state['characters'] ?? []),
            'panels' => array_map(fn($panel) => [
                'status' => $this->mapPanelStatusForFrontend($panel['status'] ?? self::PANEL_STATE_INIT),
                'progress' => $panel['progress'] ?? 0,
                'error' => $panel['error'] ?? null,
                'output_path' => $panel['output_path'] ?? null
            ], $state['panels'] ?? []),
            'state_history' => $state['state_history'] ?? [],
            'current_operation' => $this->getCurrentOperation($state),
            'error' => $state['error'] ?? null,
            'updated_at' => $state['updated_at'] ?? time()
        ];
    }

    /**
     * Map internal status to frontend status
     */
    private function mapStatusForFrontend(string $status): string
    {
        return match ($status) {
            self::STATE_INIT => 'initializing',
            self::STATE_CHARACTERS_PENDING,
            self::STATE_CHARACTERS_PROCESSING,
            self::STATE_STORY_SEGMENTING,
            self::STATE_PANELS_GENERATING,
            self::STATE_PANELS_COMPOSING => 'processing',
            self::STATE_COMPLETE => 'completed',
            self::STATE_FAILED => 'failed',
            default => 'processing'
        };
    }

    /**
     * Map panel status to frontend status
     */
    private function mapPanelStatusForFrontend(string $status): string
    {
        return match ($status) {
            self::PANEL_STATE_INIT => 'initializing',
            self::PANEL_STATE_BACKGROUND_PENDING,
            self::PANEL_STATE_BACKGROUND_PROCESSING,
            self::PANEL_STATE_BACKGROUND_READY,
            self::PANEL_STATE_COMPOSING => 'processing',
            self::PANEL_STATE_COMPLETE => 'completed',
            self::PANEL_STATE_FAILED => 'failed',
            default => 'processing'
        };
    }

    /**
     * Get current operation description for frontend
     */
    private function getCurrentOperation(array $state): string
    {
        return match ($state['status'] ?? self::STATE_INIT) {
            self::STATE_INIT => 'Initializing comic generation',
            self::STATE_CHARACTERS_PENDING => 'Preparing character processing',
            self::STATE_CHARACTERS_PROCESSING => 'Processing characters',
            self::STATE_STORY_SEGMENTING => 'Analyzing story and planning panels',
            self::STATE_PANELS_GENERATING => 'Generating panel backgrounds',
            self::STATE_PANELS_COMPOSING => 'Composing final panels',
            self::STATE_COMPLETE => 'Comic generation completed',
            self::STATE_FAILED => 'Comic generation failed',
            default => 'Processing'
        };
    }

    /**
     * Calculate overall progress considering both characters and panels
     */
    private function calculateOverallProgress(array $state): float
    {
        $status = $state['status'] ?? self::STATE_INIT;

        // Base progress for each state
        $baseProgress = match ($status) {
            self::STATE_INIT => 0,
            self::STATE_CHARACTERS_PENDING => 5,
            self::STATE_CHARACTERS_PROCESSING => 10,
            self::STATE_STORY_SEGMENTING => 30,
            self::STATE_PANELS_GENERATING => 40,
            self::STATE_PANELS_COMPOSING => 80,
            self::STATE_COMPLETE => 100,
            self::STATE_FAILED => 0,
            default => 0
        };

        // Character progress (30% of total when in character processing state)
        $characterProgress = 0;
        if ($status === self::STATE_CHARACTERS_PROCESSING && !empty($state['characters'])) {
            $completed = count(array_filter(
                $state['characters'],
                fn($c) => ($c['status'] ?? '') === 'completed'
            ));
            $characterProgress = ($completed / count($state['characters'])) * 20;
        }

        // Panel progress (40% of total when in panel states)
        $panelProgress = 0;
        if (
            in_array($status, [self::STATE_PANELS_GENERATING, self::STATE_PANELS_COMPOSING])
            && !empty($state['panels'])
        ) {
            $totalPanelProgress = array_reduce(
                $state['panels'],
                fn($sum, $panel) => $sum + ($panel['progress'] ?? 0),
                0
            );
            $panelProgress = ($totalPanelProgress / (count($state['panels']) * 100)) * 40;
        }

        // Calculate total progress
        $totalProgress = $baseProgress + $characterProgress + $panelProgress;

        // Ensure progress stays within bounds
        return max(0, min(100, round($totalProgress, 2)));
    }

    /**
     * Update progress for a specific panel
     */
    public function updatePanelProgress(string $panelId, float $progress): void
    {
        $state = $this->getPanelState($panelId);
        if (!$state) {
            return;
        }

        $state['progress'] = max(0, min(100, $progress));
        $state['updated_at'] = time();

        $this->savePanelState($panelId, $state);

        // Update strip progress if this panel belongs to a strip
        if (isset($state['strip_id'])) {
            $this->updateStripProgress($state['strip_id']);
        }
    }

    private function acquireLock(string $lockFile, int $timeout = 10): bool
    {
        $startTime = time();
        $lockFp = fopen($lockFile, 'c+');

        if (!$lockFp) {
            throw new Exception("Could not create lock file: $lockFile");
        }

        // Try to acquire lock
        while (!flock($lockFp, LOCK_EX | LOCK_NB)) {
            if (time() - $startTime >= $timeout) {
                fclose($lockFp);
                throw new Exception("Timeout waiting for lock: $lockFile");
            }
            usleep(250000); // Wait 250ms before retrying
        }

        // Store file pointer for later release
        $this->locks[$lockFile] = $lockFp;
        return true;
    }

    private function releaseLock(string $lockFile): void
    {
        if (isset($this->locks[$lockFile])) {
            flock($this->locks[$lockFile], LOCK_UN);
            fclose($this->locks[$lockFile]);
            unset($this->locks[$lockFile]);
        }
    }
}
