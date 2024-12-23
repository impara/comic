import { UIManager } from './ui-manager.js';

export const ComicGenerator = {
    init(uiManager) {
        this.uiManager = uiManager;
        this.stripId = null;
        this.pollInterval = null;
    },

    async generateStrip(story, characters, options = {}) {
        try {
            console.log('Sending request with:', { story, characters, options });

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

            const responseText = await response.text();
            console.log('Raw response:', responseText);

            let result;
            try {
                result = JSON.parse(responseText);
            } catch (parseError) {
                console.error('Failed to parse response:', responseText);
                throw new Error(`Server returned invalid JSON: ${responseText.substring(0, 100)}...`);
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

            if (result.data && result.data.id) {
                this.stripId = result.data.id;
                if (this.uiManager) {
                    this.uiManager.showGeneratingState();
                }
                await new Promise(resolve => setTimeout(resolve, 1000));
                this.startPolling();
                return;
            }

            throw new Error('Invalid server response: missing strip ID');
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
        const warningTime = 2 * 60 * 1000;    // 2 minutes
        const startTime = Date.now();
        let consecutiveErrors = 0;
        let warningShown = false;
        let initialDelay = true;

        this.pollInterval = setInterval(async () => {
            try {
                if (!this.stripId) {
                    console.error('No strip ID available for polling');
                    this.stopPolling();
                    return;
                }

                const response = await fetch(`/api.php?action=status&id=${this.stripId}`);
                if (!response.ok) {
                    throw new Error(`Server error: ${response.status} - ${response.statusText}`);
                }

                const result = await response.json();
                if (!result || !result.success) {
                    throw new Error(result?.error || 'Invalid response from server');
                }

                const state = result.data;
                if (!state) {
                    throw new Error('No state data in response');
                }

                console.log('Comic generation status:', state);

                // Reset consecutive errors on successful response
                consecutiveErrors = 0;

                // Check for completion or failure
                if (state.status === 'completed') {
                    this.handleCompletion(state);
                    return;
                } else if (state.status === 'failed') {
                    throw new Error(state.error || 'Comic generation failed');
                }

                // Update progress
                this.updateProgress(state);

                // Check for timeout
                const elapsedTime = Date.now() - startTime;
                if (elapsedTime > maxPollingTime) {
                    throw new Error('Comic generation timed out after 5 minutes');
                }

                // Show warning if taking longer than expected
                if (!warningShown && elapsedTime > warningTime) {
                    console.warn('Comic generation is taking longer than expected');
                    warningShown = true;
                }

            } catch (error) {
                console.error('Error checking status:', error);
                consecutiveErrors++;

                // Show error in UI after 3 consecutive failures
                if (consecutiveErrors >= 3) {
                    this.stopPolling();
                    if (this.uiManager) {
                        this.uiManager.showError(`Failed to check comic generation status: ${error.message}`);
                    }
                }
            }
        }, initialDelay ? 2000 : 5000);

        // Clear initial delay flag after first interval
        setTimeout(() => {
            initialDelay = false;
        }, 2000);
    },

    stopPolling() {
        if (this.pollInterval) {
            clearInterval(this.pollInterval);
            this.pollInterval = null;
        }
    },

    updateProgress(data) {
        console.log('Comic generation progress:', data);

        const { stripId, status, characters, panels } = data;

        // Update progress bar and status message
        const progressBar = document.getElementById('progress-bar');
        const statusMessage = document.getElementById('status-message');

        // Calculate overall progress based on state
        let progress = 0;
        let message = '';

        switch (status) {
            case 'init':
                progress = 0;
                message = 'Initializing...';
                break;

            case 'characters_pending':
                progress = 5;
                message = 'Preparing to process characters...';
                break;

            case 'characters_processing':
                progress = 5 + (characters.count / characters.total) * 25;
                message = `Processing characters: ${characters.message}`;
                break;

            case 'characters_complete':
                progress = 30;
                message = 'Characters completed, preparing story...';
                break;

            case 'story_segmenting':
                progress = 35;
                message = 'Segmenting story into panels...';
                break;

            case 'panels_generating':
                // Calculate panel progress
                const panelProgress = panels.total > 0
                    ? (panels.count / panels.total) * 65
                    : 0;
                progress = 35 + panelProgress;
                message = `Generating panels: ${panels.message}`;

                // Add detailed panel status if available
                if (data.panels && data.panels.details) {
                    const pendingPanels = Object.values(data.panels.details)
                        .filter(p => p.status === 'background_pending').length;
                    const composingPanels = Object.values(data.panels.details)
                        .filter(p => p.status === 'composing').length;

                    if (pendingPanels > 0) {
                        message += ` (${pendingPanels} backgrounds pending)`;
                    }
                    if (composingPanels > 0) {
                        message += ` (${composingPanels} composing)`;
                    }
                }
                break;

            case 'complete':
                progress = 100;
                message = 'Comic generation complete!';
                this.stopPolling();
                this.showResult(data.output_url);
                break;

            case 'failed':
                message = `Comic generation failed: ${data.error}`;
                this.stopPolling();
                this.showError(data.error);
                break;

            default:
                message = 'Processing...';
        }

        // Update UI
        if (progressBar) {
            progressBar.style.width = `${progress}%`;
            progressBar.setAttribute('aria-valuenow', progress);
        }

        if (statusMessage) {
            statusMessage.textContent = message;
        }

        // Log state for debugging
        console.log('Comic generation status:', {
            stripId,
            status,
            progress,
            message,
            characters,
            panels
        });

        // Continue polling if not complete or failed
        if (status !== 'complete' && status !== 'failed') {
            setTimeout(() => {
                this.checkProgress(stripId);
            }, 2000);
        }
    },

    handleCompletion(state) {
        this.stopPolling();
        if (this.uiManager) {
            this.uiManager.showCompletionState();
        }

        if (state.panels && state.panels.length > 0) {
            const sortedPanels = [...state.panels].sort((a, b) => a.id.localeCompare(b.id));
            const panelGrid = document.createElement('div');
            panelGrid.className = 'comic-panel-grid';
            panelGrid.style.display = 'grid';
            panelGrid.style.gridTemplateColumns = 'repeat(2, 1fr)';
            panelGrid.style.gap = '10px';
            panelGrid.style.padding = '10px';

            sortedPanels.forEach(panel => {
                if (panel.output_path) {
                    const panelDiv = document.createElement('div');
                    panelDiv.className = 'comic-panel';
                    panelDiv.style.border = '1px solid #ddd';
                    panelDiv.style.borderRadius = '5px';
                    panelDiv.style.overflow = 'hidden';

                    const img = document.createElement('img');
                    img.src = panel.output_path;
                    img.alt = `Comic Panel ${panel.id}`;
                    img.style.width = '100%';
                    img.style.height = 'auto';
                    img.style.display = 'block';

                    panelDiv.appendChild(img);
                    panelGrid.appendChild(panelDiv);
                }
            });

            if (this.uiManager) {
                this.uiManager.displayResult(panelGrid);
            }
        } else {
            const outputUrl = state.output_path || state.output_url;
            if (outputUrl) {
                if (this.uiManager) {
                    this.uiManager.displayResult(outputUrl);
                }
            } else {
                console.warn('No output URL in completion state:', state);
                if (this.uiManager) {
                    this.uiManager.showError('Comic generation completed but no output URL found');
                }
            }
        }
    },

    handleError(error) {
        console.error('Comic generation error:', error);
        if (this.uiManager) {
            this.uiManager.showError(error);
        }
    }
};

async function checkProgress(stripId) {
    try {
        const response = await fetch(`/api/status.php?strip_id=${stripId}`);
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        const data = await response.json();

        if (data.success) {
            const state = data.data;
            console.log('Raw state:', state);

            // Format the progress data
            const progressData = {
                stripId,
                status: state.status,
                characters: {
                    count: Object.values(state.characters || {})
                        .filter(c => c.status === 'completed').length,
                    total: Object.keys(state.characters || {}).length,
                    message: formatCharacterProgress(state.characters)
                },
                panels: {
                    count: Object.values(state.panels || {})
                        .filter(p => p.status === 'completed').length,
                    total: state.total_panels || 0,
                    message: formatPanelProgress(state.panels)
                },
                error: state.error
            };

            updateProgress(progressData);
        } else {
            console.error('Failed to check progress:', data.error);
            showError(data.error);
        }
    } catch (error) {
        console.error('Error checking progress:', error);
        showError(error.message);
    }
}

function formatCharacterProgress(characters) {
    if (!characters) return '0/0 characters completed';

    const total = Object.keys(characters).length;
    const completed = Object.values(characters)
        .filter(c => c.status === 'completed').length;
    const processing = Object.values(characters)
        .filter(c => c.status === 'processing').length;
    const failed = Object.values(characters)
        .filter(c => c.status === 'failed').length;

    let message = `${completed}/${total} characters completed`;
    if (processing > 0) message += `, ${processing} processing`;
    if (failed > 0) message += `, ${failed} failed`;

    return message;
}

function formatPanelProgress(panels) {
    if (!panels) return '0/0 panels completed';

    const total = Object.keys(panels).length;
    const completed = Object.values(panels)
        .filter(p => p.status === 'complete').length;
    const generating = Object.values(panels)
        .filter(p => ['background_pending', 'background_ready', 'composing'].includes(p.status)).length;
    const failed = Object.values(panels)
        .filter(p => p.status === 'failed').length;

    let message = `${completed}/${total} panels completed`;
    if (generating > 0) message += `, ${generating} generating`;
    if (failed > 0) message += `, ${failed} failed`;

    return message;
} 