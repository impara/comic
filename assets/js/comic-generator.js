import { UIManager } from './ui-manager.js';

export const ComicGenerator = {
    init() {
        this.stripId = null;
        this.pollInterval = null;
        this.uiManager = UIManager;

        // Bind methods
        this.handleGenerationStart = this.handleGenerationStart.bind(this);
        this.handleGenerationError = this.handleGenerationError.bind(this);
        this.checkProgress = this.checkProgress.bind(this);
        this.handleCompletion = this.handleCompletion.bind(this);
    },

    /**
     * Start comic strip generation
     */
    async generateStrip(story, characters, options = {}) {
        try {
            const response = await fetch('/api.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ story, characters, options })
            });

            const result = await response.json();
            if (result.success) {
                this.handleGenerationStart(result.data);
            } else {
                this.handleGenerationError(result.message);
            }
        } catch (error) {
            this.handleGenerationError(error.message);
        }
    },

    /**
     * Handle successful generation start
     */
    handleGenerationStart(data) {
        this.stripId = data.id;
        this.uiManager.showGeneratingState();
        this.startProgressPolling();
    },

    /**
     * Handle generation error
     */
    handleGenerationError(message) {
        this.stopProgressPolling();
        this.uiManager.showError(message);
    },

    /**
     * Start polling for progress updates
     */
    startProgressPolling() {
        if (this.pollInterval) {
            clearInterval(this.pollInterval);
        }

        this.pollInterval = setInterval(this.checkProgress, 1000);
    },

    /**
     * Stop progress polling
     */
    stopProgressPolling() {
        if (this.pollInterval) {
            clearInterval(this.pollInterval);
            this.pollInterval = null;
        }
    },

    /**
     * Check generation progress
     */
    async checkProgress() {
        if (!this.stripId) return;

        try {
            const response = await fetch(`/api/status/${this.stripId}`);
            const state = await response.json();

            if (!state || typeof state !== 'object') {
                throw new Error('Invalid state format received');
            }

            // Update UI with progress
            this.updateProgress(state);

            // Check completion
            if (state.status === 'completed') {
                this.handleCompletion(state);
            } else if (state.status === 'failed') {
                this.handleGenerationError(state.error || 'Generation failed');
            }
        } catch (error) {
            this.handleGenerationError(error.message);
        }
    },

    /**
     * Update progress UI
     */
    updateProgress(state) {
        const progress = state.progress || 0;
        this.uiManager.updateProgress(progress);

        // Update debug info if available
        if (state.panels) {
            const totalPanels = state.panels.length;
            const completedPanels = state.panels.filter(p => p.status === 'completed').length;
            this.uiManager.updateDebugInfo({
                stripId: this.stripId,
                totalPanels,
                completedPanels,
                status: state.status
            });
        }
    },

    /**
     * Handle successful completion
     */
    handleCompletion(state) {
        this.stopProgressPolling();
        this.uiManager.showCompletionState();

        if (state.output_path) {
            this.uiManager.displayResult(state.output_path);
        }
    }
}; 