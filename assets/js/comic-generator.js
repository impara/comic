import { UIManager } from './ui-manager.js';

export const ComicGenerator = {
    init() {
        this.stripId = null;
        this.pollInterval = null;
        this.uiManager = UIManager;
    },

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

            if (!response.ok) {
                throw new Error(`Server error: ${response.status}`);
            }

            const result = await response.json();
            if (result.success && result.data.id) {
                this.stripId = result.data.id;
                this.uiManager.showGeneratingState();
                this.startPolling();
            } else {
                throw new Error(result.error || 'Failed to start comic generation');
            }
        } catch (error) {
            console.error('Comic generation error:', error);
            this.handleError(error.message);
        }
    },

    startPolling() {
        if (this.pollInterval) {
            clearInterval(this.pollInterval);
        }

        this.pollInterval = setInterval(async () => {
            try {
                const response = await fetch(`/api.php?action=status&id=${this.stripId}`);
                if (!response.ok) {
                    throw new Error('Failed to fetch status');
                }

                const state = await response.json();
                console.log('Comic generation status:', state);

                // Update UI with progress
                this.updateProgress(state);

                // Check if processing is complete
                if (state.status === 'completed') {
                    this.handleCompletion(state);
                } else if (state.status === 'failed') {
                    this.handleError(state.error || 'Comic generation failed');
                }
            } catch (error) {
                console.error('Status polling error:', error);
                this.handleError('Failed to check generation status');
            }
        }, 2000);
    },

    stopPolling() {
        if (this.pollInterval) {
            clearInterval(this.pollInterval);
            this.pollInterval = null;
        }
    },

    updateProgress(state) {
        const progress = state.progress || 0;
        this.uiManager.updateProgress(progress);

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

    handleCompletion(state) {
        this.stopPolling();
        this.uiManager.showCompletionState();

        if (state.output_path) {
            this.uiManager.displayResult(state.output_path);
        } else {
            console.warn('No output path in completion state:', state);
            this.handleError('Comic generation completed but no output URL found');
        }
    },

    handleError(error) {
        this.stopPolling();
        this.uiManager.showError(error);
    }
}; 