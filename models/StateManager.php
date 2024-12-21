<?php

class StateManager
{
    private $tempPath;
    private $logger;

    // State constants
    const STATUS_INITIALIZING = 'initializing';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';

    public function __construct(string $tempPath, Logger $logger)
    {
        $this->tempPath = $tempPath;
        $this->logger = $logger;
    }

    /**
     * Initialize a new strip state
     */
    public function initializeStrip(string $stripId, array $options = []): array
    {
        $state = [
            'id' => $stripId,
            'status' => self::STATUS_INITIALIZING,
            'started_at' => time(),
            'panels' => [],
            'options' => $options,
            'progress' => 0
        ];

        $this->saveStripState($stripId, $state);
        return $state;
    }

    /**
     * Initialize a new panel state
     */
    public function initializePanel(string $stripId, string $panelId, string $description, array $options = []): array
    {
        // Initialize panel state
        $state = [
            'id' => $panelId,
            'strip_id' => $stripId,
            'description' => $description,
            'status' => self::STATUS_INITIALIZING,
            'started_at' => time(),
            'options' => $options
        ];

        // Save panel state
        $this->savePanelState($panelId, $state);

        // Update strip state to include this panel
        $stripState = $this->getStripState($stripId);
        if (!isset($stripState['panels'])) {
            $stripState['panels'] = [];
        }
        $stripState['panels'][$panelId] = [
            'id' => $panelId,
            'description' => $description,
            'status' => self::STATUS_INITIALIZING
        ];
        $this->saveStripState($stripId, $stripState);

        $this->logger->info("Panel initialized", [
            'panel_id' => $panelId,
            'strip_id' => $stripId,
            'status' => self::STATUS_INITIALIZING
        ]);

        return $state;
    }

    /**
     * Update strip state
     */
    public function updateStripState(string $stripId, array $update): array
    {
        $state = $this->getStripState($stripId);

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
        $state = array_merge($state, $update, ['updated_at' => time()]);
        $this->saveStripState($stripId, $state);

        // Recalculate progress
        if (!empty($state['panels'])) {
            $this->updateStripProgress($stripId);
        }

        $this->logger->info("Strip state updated", [
            'strip_id' => $stripId,
            'state' => $state
        ]);

        return $state;
    }

    /**
     * Update panel state and its parent strip
     */
    public function updatePanelState(string $panelId, array $update): array
    {
        $state = $this->getPanelState($panelId);
        $state = array_merge($state, $update, ['updated_at' => time()]);
        $this->savePanelState($panelId, $state);

        // Update strip progress if strip_id exists
        if (isset($state['strip_id'])) {
            $this->updateStripProgress($state['strip_id']);
        }

        return $state;
    }

    /**
     * Get current strip state
     */
    public function getStripState(string $stripId): array
    {
        $path = $this->getStripStatePath($stripId);
        return file_exists($path) ? json_decode(file_get_contents($path), true) : [];
    }

    /**
     * Get current panel state
     */
    public function getPanelState(string $panelId): array
    {
        $path = $this->getPanelStatePath($panelId);
        return file_exists($path) ? json_decode(file_get_contents($path), true) : [];
    }

    /**
     * Update strip progress based on panel states
     */
    private function updateStripProgress(string $stripId): void
    {
        $state = $this->getStripState($stripId);
        if (empty($state['panels'])) {
            return;
        }

        $totalPanels = count($state['panels']);
        $completedPanels = count(array_filter($state['panels'], function ($panel) {
            return $panel['status'] === self::STATUS_COMPLETED;
        }));

        $progress = ($completedPanels / $totalPanels) * 100;
        $this->updateStripState($stripId, ['progress' => round($progress, 2)]);
    }

    /**
     * Save strip state to file
     */
    private function saveStripState(string $stripId, array $state): void
    {
        $path = $this->getStripStatePath($stripId);
        file_put_contents($path, json_encode($state));
        $this->logger->info("Strip state updated", ['strip_id' => $stripId, 'status' => $state['status']]);
    }

    /**
     * Save panel state to file
     */
    private function savePanelState(string $panelId, array $state): void
    {
        $path = $this->getPanelStatePath($panelId);
        file_put_contents($path, json_encode($state));
        $this->logger->info("Panel state updated", ['panel_id' => $panelId, 'status' => $state['status']]);
    }

    /**
     * Get strip state file path
     */
    private function getStripStatePath(string $stripId): string
    {
        return $this->tempPath . "strip_state_{$stripId}.json";
    }

    /**
     * Get panel state file path
     */
    private function getPanelStatePath(string $panelId): string
    {
        return $this->tempPath . "state_{$panelId}.json";
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
}
