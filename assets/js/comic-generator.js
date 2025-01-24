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
        console.log('Updating progress:', {
            status: state.status,
            progress: progress,
            output_url: state.output_url
        });

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

        // If status is completed and we have an output URL, handle completion
        if (state.status === 'completed' && state.output_url) {
            this.handleCompletion(state);
        }
    },

    handleCompletion(state) {
        this.stopPolling();

        if (!state || !state.output_url) {
            console.error('Invalid completion state:', state);
            if (this.uiManager) {
                this.uiManager.showError('Comic generation completed but no output was received');
            }
            return;
        }

        console.log('Handling completion with state:', state);

        // Get the panels container
        const panelContainer = document.getElementById('comic-panels');
        if (!panelContainer) {
            console.error('Comic panels container not found');
            return;
        }

        // Clear any existing panels and states
        panelContainer.innerHTML = '';
        if (this.uiManager) {
            this.uiManager.updateProgress(100);
        }

        // Create single panel element
        const panelElement = document.createElement('div');
        panelElement.className = 'comic-panel card p-3 mb-4';

        // Create loading placeholder
        const loadingPlaceholder = document.createElement('div');
        loadingPlaceholder.className = 'text-center p-4';
        loadingPlaceholder.innerHTML = `
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <div class="mt-2">Loading your comic...</div>
        `;
        panelElement.appendChild(loadingPlaceholder);

        // Add panel to container immediately to show loading state
        panelContainer.appendChild(panelElement);

        // Configure image
        const img = document.createElement('img');
        const BASE_URL = window.location.origin;
        const cleanPath = state.output_url.replace(/\/+/g, '/');
        const imagePath = cleanPath.startsWith('/') ? cleanPath : '/' + cleanPath;

        console.log('Loading image from:', {
            base: BASE_URL,
            path: imagePath,
            full: BASE_URL + imagePath
        });

        img.src = BASE_URL + imagePath;
        img.alt = 'Generated comic panel';
        img.className = 'comic-image img-fluid rounded';
        img.style.opacity = '0';
        img.style.transition = 'opacity 0.3s ease-in';

        // Handle image load
        img.onload = () => {
            console.log('Image loaded successfully:', img.src);

            // Remove loading placeholder
            loadingPlaceholder.remove();

            // Add image to panel
            panelElement.appendChild(img);

            // Fade in the image
            requestAnimationFrame(() => {
                img.style.opacity = '1';

                // Update UI to show completion after image is loaded
                if (this.uiManager) {
                    // Small delay to ensure smooth transition
                    setTimeout(() => {
                        this.uiManager.showCompletion({
                            totalPanels: 1,
                            outputUrl: imagePath,
                            errors: []
                        });
                    }, 300);
                }
            });
        };

        // Handle image load error
        img.onerror = (error) => {
            console.error('Failed to load comic image:', {
                src: img.src,
                error: error,
                state: state
            });

            // Remove loading placeholder
            loadingPlaceholder.remove();

            // Show error in panel
            panelElement.innerHTML = `
                <div class="alert alert-danger m-0">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    Failed to load the comic image. Please try refreshing the page.
                </div>
            `;

            if (this.uiManager) {
                this.uiManager.showError('Failed to load the generated comic image. Please try refreshing the page.');
            }
        };
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
                    const BASE_URL = window.location.origin; // Could be loaded from config.js
                    img.src = BASE_URL + panel.composed_url;
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

                    // Add temporary debug logging to verify URLs
                    console.log('Received composed URL:', panel.composed_url);
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