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
                body: JSON.stringify({
                    story,
                    characters,
                    style: options.style,
                    background: options.background,
                    metadata: options.metadata
                })
            });

            // First check if response is ok
            if (!response.ok) {
                const errorText = await response.text();
                console.error('Server error response:', errorText);
                throw new Error(`Server error: ${response.status}`);
            }

            // Then try to parse JSON
            let result;
            try {
                result = await response.json();
            } catch (parseError) {
                console.error('JSON parse error:', parseError);
                throw new Error('Invalid response format from server');
            }

            if (result.success) {
                this.handleGenerationStart(result.data);
            } else {
                // Handle error from backend
                this.handleGenerationError(result.error || 'An unexpected error occurred');
            }
        } catch (error) {
            console.error('Comic generation error:', error);
            this.handleGenerationError(error.message || 'Failed to connect to the server. Please try again.');
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
        console.error('Generation error:', message);
        this.stopProgressPolling();
        if (this.uiManager) {
            this.uiManager.showError(message);
        } else {
            console.error('UIManager not initialized');
            alert(message); // Fallback if UIManager is not available
        }
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
            const response = await fetch(`/api.php?action=status&id=${this.stripId}`);
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
        // Update progress bar
        const progress = state.progress || 0;
        this.uiManager.updateProgress(progress);

        // Update debug info if available
        if (state.characters) {
            const totalCharacters = Object.keys(state.characters).length;
            const completedCharacters = Object.values(state.characters)
                .filter(c => c.status === 'completed').length;

            this.uiManager.updateDebugInfo({
                stripId: this.stripId,
                totalCharacters,
                completedCharacters,
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
        } else {
            console.warn('No output path in completion state:', state);
        }
    }
}; 