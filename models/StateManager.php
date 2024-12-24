<?php

class StateManager
{
    private $tempPath;
    private $logger;

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

    // Panel states
    const PANEL_STATE_INIT = 'panel_init';
    const PANEL_STATE_BACKGROUND_PENDING = 'background_pending';
    const PANEL_STATE_BACKGROUND_PROCESSING = 'background_processing';
    const PANEL_STATE_BACKGROUND_READY = 'background_ready';
    const PANEL_STATE_COMPOSING = 'panel_composing';
    const PANEL_STATE_COMPLETE = 'panel_complete';
    const PANEL_STATE_FAILED = 'panel_failed';

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
            'created_at' => time(),
            'updated_at' => time(),
            'options' => $options,
            'error' => null
        ];

        $this->saveStripState($stripId, $state);
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
        $fp = fopen($lockFile, 'c+');
        if (!$fp) {
            throw new Exception("Failed to create lock file: $lockFile");
        }

        try {
            // Try to get an exclusive lock
            if (!flock($fp, LOCK_EX | LOCK_NB)) {
                // Wait up to LOCK_TIMEOUT seconds
                $start = time();
                while (!flock($fp, LOCK_EX | LOCK_NB)) {
                    if (time() - $start > self::LOCK_TIMEOUT) {
                        throw new Exception("Failed to acquire lock after " . self::LOCK_TIMEOUT . " seconds");
                    }
                    usleep(100000); // Sleep for 0.1 seconds
                }
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
            }
            // Clean up lock file
            if (file_exists($lockFile)) {
                unlink($lockFile);
            }
        }
    }

    /**
     * Update strip state with file locking
     */
    public function updateStripState(string $stripId, array $updates): void
    {
        $filePath = $this->getStripFilePath($stripId);
        $lockFile = $filePath . '.lock';
        $fp = fopen($lockFile, 'c+');
        if (!$fp) {
            throw new Exception("Failed to create lock file: $lockFile");
        }

        try {
            // Try to get an exclusive lock
            if (!flock($fp, LOCK_EX | LOCK_NB)) {
                // Wait up to LOCK_TIMEOUT seconds
                $start = time();
                while (!flock($fp, LOCK_EX | LOCK_NB)) {
                    if (time() - $start > self::LOCK_TIMEOUT) {
                        throw new Exception("Failed to acquire lock after " . self::LOCK_TIMEOUT . " seconds");
                    }
                    usleep(100000); // Sleep for 0.1 seconds
                }
            }

            // Get current state
            $state = $this->getStripState($stripId);
            if (!$state) {
                throw new Exception("Strip state not found: $stripId");
            }

            // Update state
            $state = array_merge($state, $updates, [
                'updated_at' => time()
            ]);

            // Save updated state
            $this->saveStripState($stripId, $state);
        } finally {
            // Release lock and close file
            if ($fp) {
                flock($fp, LOCK_UN);
                fclose($fp);
            }
            // Clean up lock file
            if (file_exists($lockFile)) {
                unlink($lockFile);
            }
        }
    }

    /**
     * Save strip state to file
     */
    private function saveStripState(string $stripId, array $state): void
    {
        $filePath = $this->getStripFilePath($stripId);
        if (file_put_contents($filePath, json_encode($state, JSON_PRETTY_PRINT)) === false) {
            throw new Exception("Failed to save state to file: $filePath");
        }
    }

    /**
     * Get the file path for a strip's state
     */
    private function getStripFilePath(string $stripId): string
    {
        return $this->tempPath . "/job_{$stripId}.json";
    }
}
