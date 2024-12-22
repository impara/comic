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

            // Check for explicit error first
            if (!result.success || result.error) {
                throw new Error(result.error || 'Comic generation failed');
            }

            // If we have a strip ID, consider it a success even if processing hasn't started
            if (result.id) {
                this.stripId = result.id;
                this.uiManager.showGeneratingState();
                this.startPolling();
                return;
            }

            // Only throw generic error if we have no ID and no specific error
            throw new Error('Invalid server response: missing strip ID');
        } catch (error) {
            console.error('Comic generation error:', error);
            this.handleError(error.message);
        }
    },

    startPolling() {
        if (this.pollInterval) {
            clearInterval(this.pollInterval);
        }

        const maxPollingTime = 5 * 60 * 1000; // 5 minutes
        const warningTime = 2 * 60 * 1000;    // 2 minutes
        const startTime = Date.now();
        let consecutiveErrors = 0;
        let warningShown = false;

        this.pollInterval = setInterval(async () => {
            try {
                // Check if we've exceeded max polling time
                const elapsedTime = Date.now() - startTime;
                if (elapsedTime > maxPollingTime) {
                    this.stopPolling();
                    this.handleError('Comic generation timed out after 5 minutes');
                    return;
                }

                // Show warning if taking longer than expected
                if (!warningShown && elapsedTime > warningTime) {
                    warningShown = true;
                    this.uiManager.updateDebugInfo({
                        stripId: this.stripId,
                        status: 'processing',
                        message: 'Processing is taking longer than usual, but still working...'
                    });
                }

                const response = await fetch(`/api.php?action=status&id=${this.stripId}`);
                if (!response.ok) {
                    throw new Error(`Server error: ${response.status}`);
                }

                const state = await response.json();
                console.log('Comic generation status:', state);

                // Reset error counter on successful response
                consecutiveErrors = 0;

                // Update UI with progress
                this.updateProgress(state);

                // Check character processing status
                if (state.characters) {
                    const failedCharacters = Object.values(state.characters)
                        .filter(char => char.status === 'failed');

                    if (failedCharacters.length > 0) {
                        const errors = failedCharacters
                            .map(char => `Character ${char.id}: ${char.error || 'Unknown error'}`)
                            .join('; ');
                        this.handleError(`Character processing failed: ${errors}`);
                        return;
                    }
                }

                // Check if processing is complete
                if (state.status === 'completed') {
                    this.handleCompletion(state);
                } else if (state.status === 'failed') {
                    this.handleError(state.error || 'Comic generation failed');
                }
            } catch (error) {
                console.error('Status polling error:', error);
                consecutiveErrors++;

                // Stop polling after 3 consecutive errors
                if (consecutiveErrors >= 3) {
                    this.stopPolling();
                    this.handleError('Failed to check generation status after multiple attempts');
                }
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
            const processingCharacters = Object.values(state.characters)
                .filter(c => c.status === 'processing').length;

            // Show more detailed status information
            this.uiManager.updateDebugInfo({
                stripId: this.stripId,
                totalCharacters,
                completedCharacters,
                processingCharacters,
                status: state.status,
                progress: `${completedCharacters}/${totalCharacters} characters completed`
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