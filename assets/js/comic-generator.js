import { UIManager } from './ui-manager.js';

export const ComicGenerator = {
    init() {
        // Get base path from current page location
        this.basePath = new URL('.', window.location.href).pathname;
        this.pollingInterval = null;
        this.cartoonificationState = new Map();
        this.originalPanelId = null;  // Add this for better tracking
        console.log('ComicGenerator initialized with base path:', this.basePath);
    },

    handleGenerationSuccess(response) {
        if (response.success) {
            console.log('Comic generation initiated:', response.result);

            // Store the strip ID
            this.stripId = response.result.id;

            // Initialize tracking for each panel's cartoonifications
            if (response.result.pending_panels) {
                response.result.pending_panels.forEach(panelId => {
                    console.log('Setting up tracking for panel:', panelId);
                    // Create state tracking for this panel
                    this.cartoonificationState.set(panelId, {
                        status: 'pending',
                        started_at: new Date().getTime(),
                        strip_id: this.stripId,
                        panel_id: panelId,  // Add panel_id for tracking
                        cartoonification_requests: new Map()
                    });

                    // Log the tracking setup
                    console.log('Panel tracking initialized:', {
                        panel_id: panelId,
                        strip_id: this.stripId,
                        state: this.cartoonificationState.get(panelId)
                    });
                });
            }

            // Update UI to show progress
            $('#debugInfo').html(`
                <p>Generation started successfully</p>
                <p>Strip ID: ${this.stripId}</p>
                <p>Total Panels: ${response.result.total_panels}</p>
                <p>Status: ${response.result.status}</p>
                ${response.result.pending_panels ?
                    `<p>Pending Panels: ${response.result.pending_panels.join(', ')}</p>` : ''}
            `);

            // Update progress bar
            $('.progress-bar').css('width', '25%');

            // Start checking for results using state file
            this.checkResult(this.stripId);
        } else {
            console.error('Comic generation returned error:', response.message);
            this.handleGenerationError(response.message);
        }
    },

    getApiUrl(endpoint) {
        if (!endpoint) {
            console.error('Invalid endpoint provided');
            return null;
        }
        // Ensure endpoint has no leading slash and combine with base path
        endpoint = endpoint.replace(/^\/+/, '');
        // Use current window location as base URL
        const baseUrl = window.location.origin;
        try {
            const url = new URL(endpoint, baseUrl).href;
            console.log('Generated API URL:', url);
            return url;
        } catch (error) {
            console.error('Error constructing URL:', error);
            return null;
        }
    },

    generateComic(formData) {
        const apiUrl = this.getApiUrl('api.php');
        console.log('Sending POST request to:', apiUrl);

        // Validate required data
        if (!formData.story || !formData.art_style || !formData.characters.length || !formData.background) {
            console.error('Missing required data:', {
                hasStory: !!formData.story,
                hasStyle: !!formData.art_style,
                characterCount: formData.characters.length,
                hasBackground: !!formData.background
            });
            this.handleGenerationError('Please complete all required steps before generating the comic.');
            return;
        }

        // Validate character data
        const invalidCharacters = formData.characters.filter(char =>
            !char.id || !char.name || !char.image || typeof char.isCustom !== 'boolean'
        );
        if (invalidCharacters.length > 0) {
            console.error('Invalid character data:', invalidCharacters);
            this.handleGenerationError('Some characters have invalid data. Please try again.');
            return;
        }

        // Add loading indicator to UI
        $('#debugInfo').html('<p>Sending request to server...</p>');

        // Log the exact payload that will be sent
        console.log('Request payload:', formData);

        const stringifiedPayload = JSON.stringify(formData);

        $.ajax({
            url: apiUrl,
            type: 'POST',
            data: stringifiedPayload,
            contentType: 'application/json',
            dataType: 'json',
            success: (response) => {
                console.log('Comic generation response:', response);
                $('#debugInfo').html('<pre>Response: ' + JSON.stringify(response, null, 2) + '</pre>');

                // Validate response structure
                if (!response || typeof response !== 'object') {
                    this.handleGenerationError('Invalid response from server');
                    return;
                }

                if (!response.success) {
                    this.handleGenerationError(response.message || 'Comic generation failed');
                    return;
                }

                // Ensure we have the necessary data for webhook tracking
                if (!response.result || !response.result.id) {
                    this.handleGenerationError('Missing panel ID in response');
                    return;
                }

                this.handleGenerationSuccess(response);
            },
            error: (xhr, status, error) => {
                // Enhanced error logging
                const errorDetails = {
                    status: status,
                    error: error,
                    response: xhr.responseText,
                    headers: xhr.getAllResponseHeaders(),
                    state: xhr.readyState,
                    statusCode: xhr.status,
                    statusText: xhr.statusText
                };
                console.error('Comic generation failed:', errorDetails);

                // Show error details in UI
                $('#debugInfo').html('<pre>Error: ' + JSON.stringify(errorDetails, null, 2) + '</pre>');

                // Try to parse response text if it's JSON
                let errorMessage = error;
                try {
                    const errorResponse = JSON.parse(xhr.responseText);
                    errorMessage = errorResponse.message || errorResponse.error || error;
                } catch (e) {
                    errorMessage = xhr.responseText || error;
                }

                this.handleGenerationError(errorMessage);
            },
            // Add timeout
            timeout: 30000, // 30 seconds
            // Add request state logging
            beforeSend: (xhr) => {
                console.log('Sending request with headers:', xhr.getAllResponseHeaders());
            }
        });
    },

    findCharacterById(id) {
        // First check predefined characters
        const predefined = this.characters.find(c => c.id === id);
        if (predefined) return predefined;

        // Then check custom characters
        const customChar = this.customCharacters.find(c => c.id === id);
        if (customChar) return customChar;

        return null;
    },

    checkResult(stripId) {
        if (!stripId) {
            console.error('Invalid strip ID provided');
            this.handleGenerationError('Invalid strip ID');
            return;
        }

        // First check the state file
        const stateUrl = this.getApiUrl(`public/temp/strip_state_${stripId}.json`);
        console.log('Checking strip state file:', stateUrl);

        // Clear any existing polling interval
        if (this.pollingInterval) {
            clearInterval(this.pollingInterval);
            this.pollingInterval = null;
        }

        let retryCount = 0;
        const maxRetries = 3;  // Maximum number of consecutive errors before showing error
        let lastStatus = null;  // Track last known status for transition logging

        // Start polling for state updates
        this.pollingInterval = setInterval(() => {
            $.ajax({
                url: stateUrl,
                type: 'GET',
                dataType: 'json',
                success: (state) => {
                    console.log('Strip state update received:', state);
                    retryCount = 0;  // Reset retry count on success

                    // Validate state structure
                    if (!state || typeof state !== 'object') {
                        console.error('Invalid state format received:', state);
                        this.handleGenerationError('Invalid state format received from server');
                        return;
                    }

                    // Log state transitions
                    if (lastStatus !== state.status) {
                        console.log(`Strip state transition: ${lastStatus || 'initial'} -> ${state.status}`);
                        lastStatus = state.status;
                    }

                    // Update progress based on state
                    if (state.panels) {
                        const totalPanels = state.panels.length;
                        const completedPanels = state.panels.filter(p => p.status === 'succeeded').length;
                        const failedPanels = state.panels.filter(p => p.status === 'failed').length;
                        const progress = (completedPanels / totalPanels) * 100;

                        // Update cartoonification state for each panel
                        state.panels.forEach((panel, index) => {
                            const panelId = panel.id;
                            if (this.cartoonificationState.has(panelId)) {
                                const currentState = this.cartoonificationState.get(panelId);

                                // Log panel state update
                                console.log(`Panel ${panelId} state update:`, {
                                    previous: currentState.status,
                                    new: panel.status,
                                    panel_data: panel
                                });

                                // Update panel state
                                currentState.status = panel.status;
                                currentState.updated_at = new Date().getTime();
                                if (panel.cartoonification_requests) {
                                    panel.cartoonification_requests.forEach(request => {
                                        currentState.cartoonification_requests.set(request.prediction_id, request);
                                    });
                                }
                                this.cartoonificationState.set(panelId, currentState);
                            }
                        });

                        $('.progress-bar').css('width', `${progress}%`);

                        $('#debugInfo').html(`
                            <p>Generating comic strip...</p>
                            <p>Strip ID: ${stripId}</p>
                            <p>Progress: ${completedPanels}/${totalPanels} panels completed</p>
                            ${failedPanels > 0 ? `<p class="text-danger">${failedPanels} panels failed</p>` : ''}
                            <p>Status: ${state.status}</p>
                        `);

                        // Check for completion or failure
                        if (failedPanels > 0) {
                            clearInterval(this.pollingInterval);
                            this.pollingInterval = null;
                            this.handleGenerationError('One or more panels failed to generate');
                            return;
                        }

                        if (completedPanels === totalPanels && state.status === 'completed') {
                            clearInterval(this.pollingInterval);
                            this.pollingInterval = null;
                            this.handleFinalResult(state);
                        }
                    }
                },
                error: (xhr, status, error) => {
                    console.error('Error checking strip state:', {
                        status: status,
                        error: error,
                        response: xhr.responseText,
                        retry_count: retryCount
                    });

                    retryCount++;
                    if (retryCount >= maxRetries) {
                        clearInterval(this.pollingInterval);
                        this.pollingInterval = null;
                        this.handleGenerationError('Failed to check generation status after multiple retries');
                    }
                },
                timeout: 10000  // 10 second timeout
            });
        }, 2000); // Poll every 2 seconds

        // Set a timeout to stop polling after 5 minutes
        setTimeout(() => {
            if (this.pollingInterval) {
                clearInterval(this.pollingInterval);
                this.pollingInterval = null;
                this.handleGenerationError('Generation timed out after 5 minutes');
            }
        }, 5 * 60 * 1000);
    },

    handleFinalResult(result) {
        try {
            if (result && result.output) {
                // First hide generating state with callback
                UIManager.hideGeneratingState(() => {
                    // First show completion state to make the container visible
                    try {
                        UIManager.showCompletionState();
                    } catch (e) {
                        console.error('Error showing completion state:', e);
                        // Fallback to direct DOM manipulation if UI Manager fails
                        $('#completionStatus').show();
                    }

                    // Short delay to ensure container is visible
                    setTimeout(() => {
                        // Then set the image
                        $('.comic-preview').html(`<img src="${result.output}" class="img-fluid mb-4" alt="Generated Comic">`);
                    }, 100);
                });
            } else {
                this.handleGenerationError('Missing final image in result');
            }
        } catch (e) {
            console.error('Error in handleFinalResult:', e);
            this.handleGenerationError('Error displaying final result');
        }
    },

    handleGenerationError(error) {
        if (this.pollingInterval) {
            clearInterval(this.pollingInterval);
            this.pollingInterval = null;
        }

        console.error('Generation error:', error);
        $('#debugInfo').html(`<p class="text-danger">Error: ${error}</p>`);

        // Hide generating state and show error
        $('#generatingStatus').hide();
        UIManager.showGenerateButton();
        $('.progress-bar').css('width', '0%');
    }
}; 