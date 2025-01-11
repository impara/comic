import { UIManager } from './ui-manager.js';

export const ComicGenerator = {
    init(uiManager) {
        this.uiManager = uiManager;
        this.jobId = null;
        this.pollInterval = null;
    },

    async generateStrip(story, characters, options = {}) {
        try {
            console.log('Sending request with:', { story, characters, options });

            const response = await fetch('/api.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    story,
                    characters,
                    style: options.style,
                    background: options.background
                })
            });

            // Check for Cloudflare error page
            const contentType = response.headers.get('content-type');
            if (contentType && contentType.includes('text/html')) {
                throw new Error('Server is temporarily unavailable. Please try again in a few moments.');
            }

            const responseText = await response.text();
            console.log('Raw response:', responseText);

            let result;
            try {
                result = JSON.parse(responseText);
            } catch (parseError) {
                console.error('Failed to parse response:', responseText);
                throw new Error('Server returned an invalid response. Please try again.');
            }

            if (!response.ok) {
                const errorMessage = result?.error || `Server error: ${response.status}`;
                console.error('Server error details:', {
                    status: response.status,
                    statusText: response.statusText,
                    result
                });
                throw new Error(errorMessage);
            }

            if (!result.success || result.error) {
                throw new Error(result.error || 'Comic generation failed');
            }

            if (result.jobId) {
                this.jobId = result.jobId;
                if (this.uiManager) {
                    this.uiManager.showGeneratingState();
                }
                await new Promise(resolve => setTimeout(resolve, 1000));
                this.startPolling();
                return;
            }

            throw new Error('Invalid server response: missing job ID');
        } catch (error) {
            console.error('Comic generation error:', error);
            console.error('Error details:', {
                name: error.name,
                message: error.message,
                stack: error.stack
            });
            if (this.uiManager) {
                this.uiManager.showError(error.message);
            }
        }
    },

    startPolling() {
        if (this.pollInterval) {
            clearInterval(this.pollInterval);
        }

        const maxPollingTime = 5 * 60 * 1000; // 5 minutes
        const startTime = Date.now();
        let consecutiveErrors = 0;
        let lastState = null;

        const checkStatus = async () => {
            try {
                if (!this.jobId) {
                    console.error('No job ID available for polling');
                    this.stopPolling();
                    return;
                }

                const response = await fetch(`/api.php?action=status&jobId=${this.jobId}&t=${Date.now()}`);
                if (!response.ok) {
                    throw new Error(`Server error: ${response.status}`);
                }

                const result = await response.json();
                if (!result.success) {
                    throw new Error(result?.error || 'Invalid response from server');
                }

                // Only update UI if state has changed
                if (!lastState ||
                    lastState.status !== result.status ||
                    lastState.progress !== result.progress ||
                    lastState.output_url !== result.output_url) {

                    console.log('Comic generation status update:', result);
                    this.updateProgress(result);
                    lastState = result;
                }

                // Reset consecutive errors on successful response
                consecutiveErrors = 0;

                // Check for completion or failure
                if (result.status === 'completed') {
                    this.handleCompletion(result);
                    return;
                } else if (result.status === 'failed') {
                    throw new Error(result.error || 'Comic generation failed');
                }

                // Check for timeout
                if (Date.now() - startTime > maxPollingTime) {
                    throw new Error('Comic generation timed out after 5 minutes');
                }

                // Poll every 3 seconds
                setTimeout(checkStatus, 3000);

            } catch (error) {
                console.error('Error checking status:', error);
                consecutiveErrors++;

                if (consecutiveErrors >= 3) {
                    this.stopPolling();
                    if (this.uiManager) {
                        this.uiManager.showError(`Failed to check comic generation status: ${error.message}`);
                    }
                    return;
                }

                // Retry with backoff
                setTimeout(checkStatus, Math.min(2000 * Math.pow(2, consecutiveErrors), 10000));
            }
        };

        // Start the first check
        checkStatus();
    },

    stopPolling() {
        if (this.pollInterval) {
            clearInterval(this.pollInterval);
            this.pollInterval = null;
        }
    },

    updateProgress(state) {
        if (!state) {
            console.warn('No state provided to updateProgress');
            return;
        }

        const progress = state.progress || 0;
        if (this.uiManager) {
            this.uiManager.updateProgress(progress);
        }

        // Update UI with job status information
        if (this.uiManager) {
            this.uiManager.updateDebugInfo({
                jobId: this.jobId,
                status: state.status,
                progress: state.progress,
                output_url: state.output_url,
                error: state.error,
                last_update: new Date().toISOString()
            });
        }

        // Log progress info
        console.log('Comic generation progress:', {
            jobId: this.jobId,
            status: state.status,
            progress: state.progress,
            output_url: state.output_url
        });

        // Only log raw state in debug mode
        if (window.DEBUG_MODE) {
            console.debug('Raw comic generation state:', state);
        }
    },

    handleCompletion(state) {
        this.stopPolling();
        if (this.uiManager) {
            this.uiManager.showCompletion(state.output_url);
        }
    }
}; 