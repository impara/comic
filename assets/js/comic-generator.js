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
                    if (result.error?.includes('NSFW')) {
                        throw new Error('Content blocked: Please modify your story to remove any violent or sensitive elements');
                    } else {
                        throw new Error(result.error || 'Comic generation failed');
                    }
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

        if (!state || !state.output) {
            console.error('Invalid completion state:', state);
            if (this.uiManager) {
                this.uiManager.showError('Comic generation completed but no output was received');
            }
            return;
        }

        // Get the panels container
        const panelContainer = document.getElementById('comic-panels');
        if (!panelContainer) {
            console.error('Comic panels container not found');
            return;
        }

        // Clear any existing panels
        panelContainer.innerHTML = '';

        if (state.output.panels && Array.isArray(state.output.panels)) {
            // Create and append each panel
            state.output.panels.forEach((panel, index) => {
                if (!panel.composed_url) {
                    console.error(`Panel ${index} missing composed URL:`, panel);
                    return;
                }

                // Create panel wrapper
                const panelElement = document.createElement('div');
                panelElement.className = 'comic-panel';

                // Create image with loading state
                const img = document.createElement('img');
                img.src = panel.composed_url;
                img.alt = panel.description || `Panel ${index + 1}`;
                img.className = 'comic-image';
                img.loading = 'lazy'; // Enable lazy loading

                // Add loading indicator
                img.style.opacity = '0';
                img.onload = () => {
                    img.style.transition = 'opacity 0.3s ease-in';
                    img.style.opacity = '1';
                };

                // Add error handling
                img.onerror = () => {
                    console.error(`Failed to load panel image: ${panel.composed_url}`);
                    img.src = '/assets/images/error-placeholder.png';
                    img.alt = 'Failed to load panel';
                };

                // Add description if available
                if (panel.description) {
                    const desc = document.createElement('p');
                    desc.className = 'panel-description';
                    desc.textContent = panel.description;
                    panelElement.appendChild(desc);
                }

                // Append image
                panelElement.appendChild(img);
                panelContainer.appendChild(panelElement);
            });

            // Show completion message
            if (this.uiManager) {
                this.uiManager.showCompletion({
                    totalPanels: state.output.panels.length,
                    outputUrl: state.output_url,
                    errors: state.output.errors || []
                });
            }
        } else {
            // Fallback to single output URL if no panels array
            if (state.output_url) {
                const img = document.createElement('img');
                img.src = state.output_url;
                img.alt = 'Generated comic strip';
                img.className = 'comic-image';
                panelContainer.appendChild(img);

                if (this.uiManager) {
                    this.uiManager.showCompletion({
                        totalPanels: 1,
                        outputUrl: state.output_url,
                        errors: []
                    });
                }
            } else {
                console.error('No panels or output URL found in completion state:', state);
                if (this.uiManager) {
                    this.uiManager.showError('Comic generation completed but no images were produced');
                }
            }
        }

        // Log completion
        console.log('Comic generation completed:', {
            totalPanels: state.output.panels?.length || 0,
            outputUrl: state.output_url,
            errors: state.output.errors || []
        });
    }
};

function checkJobStatus(jobId) {
    return fetch(`/api/jobs/${jobId}/status`)
        .then(response => response.json())
        .then(data => {
            if (data.status === 'completed') {
                // Verify output exists
                if (!data.output || !data.output.panels) {
                    throw new Error('Job completed but no output panels found');
                }

                // Display each composed panel
                const panelContainer = document.getElementById('comic-panels');
                panelContainer.innerHTML = ''; // Clear previous content

                data.output.panels.forEach(panel => {
                    if (!panel.composed_url) {
                        console.error('Missing composed URL for panel:', panel.id);
                        return;
                    }

                    // Create panel element
                    const panelElement = document.createElement('div');
                    panelElement.className = 'comic-panel';

                    // Create image element
                    const img = document.createElement('img');
                    img.src = panel.composed_url;
                    img.alt = `Comic panel: ${panel.description}`;
                    img.className = 'comic-image';

                    // Create description element
                    const desc = document.createElement('p');
                    desc.className = 'panel-description';
                    desc.textContent = panel.description;

                    // Append elements
                    panelElement.appendChild(img);
                    panelElement.appendChild(desc);
                    panelContainer.appendChild(panelElement);
                });

                // Show completion message
                showCompletionMessage();
                return true;
            }
            return false;
        })
        .catch(error => {
            console.error('Error checking job status:', error);
            showError(error.message);
            throw error;
        });
}

function showCompletionMessage() {
    const statusElement = document.getElementById('status-message');
    statusElement.textContent = 'Comic generation complete!';
    statusElement.className = 'status-message success';
}

function showError(message) {
    const statusElement = document.getElementById('status-message');
    statusElement.textContent = `Error: ${message}`;
    statusElement.className = 'status-message error';
}

// Example usage with polling
function pollJobStatus(jobId) {
    const interval = setInterval(() => {
        checkJobStatus(jobId)
            .then(completed => {
                if (completed) {
                    clearInterval(interval);
                }
            })
            .catch(() => clearInterval(interval));
    }, 5000); // Poll every 5 seconds
} 