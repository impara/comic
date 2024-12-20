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

            // Store the original panel ID first - updated to use new field name
            const originalPanelId = response.result.original_panel_id || response.result.id;
            this.originalPanelId = originalPanelId;  // Store globally

            console.log('Setting up cartoonification tracking with original panel ID:', originalPanelId);

            // Initialize cartoonification state with original panel ID
            if (response.result.pending_predictions) {
                response.result.pending_predictions.forEach(predId => {
                    this.cartoonificationState.set(predId, {
                        status: 'pending',
                        started_at: new Date().getTime(),
                        original_panel_id: originalPanelId // Updated field name
                    });
                });
            }

            // Update UI to show progress
            $('#debugInfo').html(`
                <p>Generation started successfully</p>
                <p>Panel ID: ${originalPanelId}</p>
                <p>Status: ${response.result.status}</p>
                ${response.result.pending_predictions ?
                    `<p>Waiting for cartoonification: ${response.result.pending_predictions.join(', ')}</p>` : ''}
            `);

            // Update progress bar
            $('.progress-bar').css('width', '25%');

            // Start checking for results using state file
            this.checkResult(originalPanelId);
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

    handleComicGeneration(e) {
        e.preventDefault();
        console.log('Comic generation started');

        // Get story and style from session storage
        const userStory = sessionStorage.getItem('userStory');
        const selectedStyle = sessionStorage.getItem('selectedStyle');
        const selectedCharacterIds = JSON.parse(sessionStorage.getItem('selectedCharacters') || '[]');
        const selectedBackground = sessionStorage.getItem('selectedBackground');

        // Debug: Log retrieved data
        console.log('Retrieved data:', {
            story: userStory,
            style: selectedStyle,
            characterIds: selectedCharacterIds,
            background: selectedBackground
        });

        if (!userStory || !selectedStyle || !selectedCharacterIds.length || !selectedBackground) {
            console.error('Missing required data:', {
                hasStory: !!userStory,
                hasStyle: !!selectedStyle,
                characterCount: selectedCharacterIds.length,
                hasBackground: !!selectedBackground
            });
            this.handleGenerationError('Please complete all required steps before generating the comic.');
            return;
        }

        // Get custom character data
        const characterData = JSON.parse(sessionStorage.getItem('characterData') || '{}');

        // Process characters
        const characters = selectedCharacterIds.map(id => {
            const char = characterData[id];
            if (!char) {
                console.error('Character not found:', id);
                return null;
            }
            return {
                id: char.id,
                name: char.name,
                description: char.description || char.name,
                image: char.image,
                isCustom: char.isCustom,
                options: {
                    style: selectedStyle
                }
            };
        }).filter(Boolean);

        // Prepare form data
        const formData = {
            characters: characters,
            story: userStory,
            art_style: selectedStyle,
            background: selectedBackground
        };

        // Generate the comic
        this.generateComic(formData);
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

    generateComic(formData) {
        const apiUrl = this.getApiUrl('api.php');
        console.log('Sending POST request to:', apiUrl);

        // Add loading indicator to UI
        $('#debugInfo').html('<p>Sending request to server...</p>');

        // Ensure all parameters are included
        const requestPayload = {
            characters: formData.characters,
            story: formData.story,
            art_style: formData.art_style,
            background: formData.background  // Add background to payload
        };

        // Log the exact payload that will be sent
        console.log('Request payload:', requestPayload);

        const stringifiedPayload = JSON.stringify(requestPayload);

        $.ajax({
            url: apiUrl,
            type: 'POST',
            data: stringifiedPayload,
            contentType: 'application/json',
            dataType: 'json',
            success: (response) => {
                console.log('Comic generation response:', response);
                $('#debugInfo').html('<pre>Response: ' + JSON.stringify(response, null, 2) + '</pre>');
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

    checkResult(panelId) {
        if (!panelId) {
            console.error('Invalid panel ID provided');
            this.handleGenerationError('Invalid panel ID');
            return;
        }

        // First check the state file
        const stateUrl = this.getApiUrl(`public/temp/state_${panelId}.json`);
        console.log('Checking state file:', stateUrl);

        // Get the final panel URL
        const panelUrl = this.getApiUrl(`public/temp/${panelId}.json`);
        console.log('Final panel URL:', panelUrl);

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
                    console.log('State update received:', state);
                    retryCount = 0;  // Reset retry count on success

                    // Validate state structure
                    if (!state || typeof state !== 'object') {
                        console.error('Invalid state format received:', state);
                        this.handleGenerationError('Invalid state format received from server');
                        return;
                    }

                    // Log state transitions
                    if (lastStatus !== state.status) {
                        console.log(`State transition: ${lastStatus || 'initial'} -> ${state.status}`);
                        lastStatus = state.status;
                    }

                    // Update progress based on state
                    if (state.status === 'cartoonification_complete') {
                        $('.progress-bar').css('width', '75%');
                        $('#debugInfo').html(`
                            <p>Cartoonification complete, generating final panel...</p>
                            <p>Panel ID: ${panelId}</p>
                            ${state.sdxl_status ? `<p>SDXL Status: ${state.sdxl_status}</p>` : ''}
                        `);
                    } else if (state.status === 'succeeded') {
                        // Final success state
                        clearInterval(this.pollingInterval);
                        this.pollingInterval = null;

                        $('.progress-bar').css('width', '100%');
                        $('#debugInfo').html(`
                            <p>Comic panel generated successfully!</p>
                            <p>Panel ID: ${panelId}</p>
                        `);

                        // Get the final result
                        $.ajax({
                            url: panelUrl,
                            type: 'GET',
                            dataType: 'json',
                            success: (result) => {
                                console.log('Final result received:', result);
                                this.handleFinalResult(result);
                            },
                            error: (xhr, status, error) => {
                                console.error('Error getting final result:', error);
                                this.handleGenerationError('Failed to get final result');
                            }
                        });
                    } else if (state.status === 'failed' || state.sdxl_status === 'failed') {
                        // Handle failure state
                        clearInterval(this.pollingInterval);
                        this.pollingInterval = null;

                        // Get the most specific error message available
                        const errorMessage = state.sdxl_error ||
                            state.error ||
                            state.cartoonification_requests?.find(r => r.error)?.error ||
                            'Generation failed';

                        this.handleGenerationError(errorMessage);
                    } else {
                        // Update progress for cartoonification
                        const completedRequests = state.cartoonification_requests?.filter(r => r.status === 'succeeded')?.length || 0;
                        const totalRequests = state.cartoonification_requests?.length || 0;

                        // Check for any failed requests
                        const failedRequests = state.cartoonification_requests?.filter(r => r.status === 'failed') || [];
                        if (failedRequests.length > 0) {
                            clearInterval(this.pollingInterval);
                            this.pollingInterval = null;
                            this.handleGenerationError(failedRequests[0].error || 'Character processing failed');
                            return;
                        }

                        if (totalRequests > 0) {
                            const progress = (completedRequests / totalRequests) * 50 + 25; // 25-75% range for cartoonification
                            $('.progress-bar').css('width', `${progress}%`);
                        }

                        $('#debugInfo').html(`
                            <p>Processing characters: ${completedRequests}/${totalRequests}</p>
                            <p>Panel ID: ${panelId}</p>
                            <p>Status: ${state.status}</p>
                            ${state.cartoonification_requests?.length ?
                                `<p>Cartoonification progress: ${completedRequests}/${totalRequests}</p>` : ''}
                            ${state.sdxl_status ?
                                `<p>SDXL status: ${state.sdxl_status}</p>` : ''}
                        `);
                    }
                },
                error: (xhr, status, error) => {
                    console.error('Error checking state:', {
                        status: status,
                        error: error,
                        response: xhr.responseText,
                        retry_count: retryCount,
                        xhr_status: xhr.status
                    });

                    retryCount++;

                    // Handle specific error cases
                    if (status === 'timeout') {
                        if (retryCount >= maxRetries) {
                            clearInterval(this.pollingInterval);
                            this.pollingInterval = null;
                            this.handleGenerationError('Server is not responding, please try again');
                            return;
                        }
                    } else if (xhr.status === 404) {
                        // State file not found yet, this is normal at the start
                        $('#debugInfo').html(`
                            <p>Initializing generation...</p>
                            <p>Panel ID: ${panelId}</p>
                        `);
                        return;
                    } else if (retryCount >= maxRetries) {
                        clearInterval(this.pollingInterval);
                        this.pollingInterval = null;
                        this.handleGenerationError('Failed to check generation status after multiple retries');
                        return;
                    }

                    // Update UI with retry status
                    $('#debugInfo').html(`
                        <p>Waiting for processing to start...</p>
                        <p>Panel ID: ${panelId}</p>
                        <p class="text-warning">Retry ${retryCount}/${maxRetries}</p>
                        ${status === 'timeout' ? '<p class="text-warning">Server is taking longer than expected to respond</p>' : ''}
                    `);
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