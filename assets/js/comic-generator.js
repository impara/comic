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
        const startTime = Date.now();
        let consecutiveErrors = 0;
        let lastState = null;

        const checkStatus = async () => {
            try {
                if (!this.stripId) {
                    console.error('No strip ID available for polling');
                    this.stopPolling();
                    return;
                }

                const response = await fetch(`/api.php?action=status&id=${this.stripId}&t=${Date.now()}`);
                if (!response.ok) {
                    throw new Error(`Server error: ${response.status}`);
                }

                const result = await response.json();
                if (!result.success) {
                    throw new Error(result?.error || 'Invalid response from server');
                }

                const state = result.data;
                if (!state) {
                    throw new Error('No state data in response');
                }

                // Only update UI if state has changed
                if (!lastState ||
                    lastState.status !== state.status ||
                    lastState.progress !== state.progress ||
                    JSON.stringify(lastState.characters) !== JSON.stringify(state.characters) ||
                    JSON.stringify(lastState.panels) !== JSON.stringify(state.panels)) {

                    console.log('Comic generation status update:', state);
                    this.updateProgress(state);
                    lastState = state;
                }

                // Reset consecutive errors on successful response
                consecutiveErrors = 0;

                // Check for completion or failure
                if (state.status === 'completed') {
                    this.handleCompletion(state);
                    return;
                } else if (state.status === 'failed') {
                    throw new Error(state.error || 'Comic generation failed');
                }

                // Check for timeout
                if (Date.now() - startTime > maxPollingTime) {
                    throw new Error('Comic generation timed out after 5 minutes');
                }

                // Adjust polling interval based on state
                const nextInterval = state.status === 'story_segmenting' ? 2000 : 5000;
                setTimeout(checkStatus, nextInterval);

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

        // Format character status
        const formatCharacterStatus = (characters) => {
            if (!characters) return '';
            const totalCharacters = Object.keys(characters).length;
            const completedCharacters = Object.values(characters)
                .filter(c => c.status === 'completed').length;
            const processingCharacters = Object.values(characters)
                .filter(c => c.status === 'processing').length;
            const failedCharacters = Object.values(characters)
                .filter(c => c.status === 'failed').length;

            let message = `${completedCharacters}/${totalCharacters} characters completed`;
            if (processingCharacters > 0) message += `, ${processingCharacters} processing`;
            if (failedCharacters > 0) message += `, ${failedCharacters} failed`;
            return message;
        };

        // Format panel status
        const formatPanelStatus = (panels) => {
            if (!panels) return '';
            const totalPanels = panels.length;
            const completedPanels = panels.filter(p => p.status === 'completed').length;
            const processingPanels = panels.filter(p => p.status === 'processing').length;
            const failedPanels = panels.filter(p => p.status === 'failed').length;

            let message = `${completedPanels}/${totalPanels} panels completed`;
            if (processingPanels > 0) message += `, ${processingPanels} processing`;
            if (failedPanels > 0) message += `, ${failedPanels} failed`;
            return message;
        };

        // Generate formatted progress info
        const progressInfo = {
            stripId: this.stripId,
            status: state.status,
            characters: {
                message: formatCharacterStatus(state.characters),
                count: Object.keys(state.characters || {}).length
            },
            panels: {
                message: formatPanelStatus(state.panels),
                count: (state.panels || []).length
            }
        };

        // Log formatted progress info
        console.log('Comic generation progress:', progressInfo);

        // Only log raw state in debug mode
        if (window.DEBUG_MODE) {
            console.debug('Raw comic generation state:', state);
        }

        // Update UI with detailed information
        if (this.uiManager) {
            this.uiManager.updateDebugInfo({
                stripId: this.stripId,
                status: state.status,
                characters: progressInfo.characters.message,
                panels: progressInfo.panels.message,
                state_history: state.state_history || [],
                current_operation: state.current_operation || 'unknown',
                last_update: new Date().toISOString()
            });
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