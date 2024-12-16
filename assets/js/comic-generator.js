import { UIManager } from './ui-manager.js';

export const ComicGenerator = {
    init() {
        // Get base path from current page location
        this.basePath = new URL('.', window.location.href).pathname;
        this.pollingInterval = null;
        this.cartoonificationState = new Map();
        this.processStages = new Map(); // Track stages for each prediction
        this.maxRetries = 3; // Maximum number of retries for failed requests
        this.timeoutDuration = 600000; // 10 minutes timeout
        this.retryDelay = 2000; // 2 seconds between retries
        console.log('ComicGenerator initialized with base path:', this.basePath);
    },

    handleGenerationSuccess(response) {
        if (response.success) {
            console.log('Comic generation initiated:', response.result);

            // Initialize cartoonification state with original panel ID
            if (response.result.pending_predictions) {
                const originalPanelId = response.result.original_prediction_id || response.result.id;
                console.log('Setting up cartoonification tracking with original panel ID:', originalPanelId);

                response.result.pending_predictions.forEach(predId => {
                    this.cartoonificationState.set(predId, {
                        status: 'pending',
                        started_at: new Date().getTime(),
                        original_prediction_id: originalPanelId,
                        retries: 0
                    });

                    // Initialize process stage tracking
                    this.processStages.set(predId, {
                        current: 'cartoonification',
                        cartoonification: 'pending',
                        sdxl: 'waiting',
                        progress: 0,
                        started_at: new Date().getTime(),
                        last_update: new Date().getTime()
                    });
                });

                // Also store the original panel ID separately
                this.originalPanelId = originalPanelId;
            }

            // Update UI to show progress
            this.updateProgressUI(response.result);

            // Start checking for results - use original panel ID if available
            this.checkResult(response.result.original_prediction_id || response.result.id);
        } else {
            console.error('Comic generation returned error:', response.message);
            this.handleGenerationError(response.message);
        }
    },

    updateProgressUI(result) {
        const stages = this.processStages.get(result.id) || {};
        let progressText = '';
        let progressPercent = 25; // Default starting progress

        if (stages.current === 'cartoonification') {
            progressText = 'Cartoonifying character...';
            progressPercent = 25;
        } else if (stages.current === 'sdxl') {
            progressText = 'Generating final image with SDXL...';
            progressPercent = 75;
        }

        $('#debugInfo').html(`
            <p>Generation in progress</p>
            <p>Panel ID: ${result.original_prediction_id || result.id}</p>
            <p>Status: ${result.status}</p>
            <p>Current Stage: ${progressText}</p>
            ${result.pending_predictions ?
                `<p>Pending processes: ${result.pending_predictions.join(', ')}</p>` : ''}
        `);

        $('.progress-bar').css('width', `${progressPercent}%`);
    },

    getApiUrl(endpoint) {
        // Ensure endpoint has no leading slash and combine with base path
        endpoint = endpoint.replace(/^\/+/, '');
        // Use current window location as base URL
        const baseUrl = window.location.origin;
        const url = new URL(endpoint, baseUrl).href;
        console.log('Generated API URL:', url);
        return url;
    },

    handleComicGeneration(e) {
        e.preventDefault();
        console.log('Comic generation started');

        // Debug: Log all session storage data
        console.log('All session storage data:', {
            userStory: sessionStorage.getItem('userStory'),
            selectedStyle: sessionStorage.getItem('selectedStyle'),
            selectedCharacters: sessionStorage.getItem('selectedCharacters'),
            characterData: sessionStorage.getItem('characterData')
        });

        // Get story and style from session storage
        const userStory = sessionStorage.getItem('userStory');
        const selectedStyle = sessionStorage.getItem('selectedStyle');
        const selectedCharacterIds = JSON.parse(sessionStorage.getItem('selectedCharacters') || '[]');

        console.log('Retrieved from session storage:', {
            userStory: userStory,
            selectedStyle: selectedStyle,
            selectedCharacterIds: selectedCharacterIds
        });

        if (!userStory || !selectedStyle || !selectedCharacterIds.length) {
            console.error('Missing required data:', {
                hasStory: !!userStory,
                hasStyle: !!selectedStyle,
                characterCount: selectedCharacterIds.length
            });
            this.handleGenerationError('Please complete all required steps before generating the comic.');
            return;
        }

        // Get custom character data
        const characterData = JSON.parse(sessionStorage.getItem('characterData') || '{}');
        console.log('Retrieved character data from session:', characterData);

        // Create array of character details from selected characters
        const characters = selectedCharacterIds.map(id => {
            const char = characterData[id];
            if (!char) {
                console.error('Character not found:', id);
                return null;
            }

            // Ensure image URL is complete
            let imageUrl = char.image;
            if (imageUrl && !imageUrl.startsWith('data:') && !imageUrl.startsWith('http')) {
                // Convert relative URLs to absolute
                imageUrl = new URL(imageUrl, window.location.origin).href;
            }

            return {
                id: char.id,
                name: char.name,
                description: char.name,
                image: imageUrl,
                isCustom: true,
                options: {
                    style: selectedStyle
                }
            };
        }).filter(char => char !== null);

        console.log('Processed characters:', characters);

        if (characters.length === 0) {
            console.error('No valid characters found');
            this.handleGenerationError('Please upload at least one custom character before generating the comic.');
            return;
        }

        // Collect form data
        const formData = {
            characters: characters,
            scene_description: userStory,
            art_style: selectedStyle
        };

        console.log('Sending comic generation data:', formData);

        // Update UI for generation process
        UIManager.showGeneratingState();

        // Send request to generate comic
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
        // Detailed request logging
        console.log('Initiating comic generation with data:', {
            story_length: formData.scene_description?.length,
            art_style: formData.art_style,
            character_count: formData.characters?.length,
            characters: formData.characters,
            raw_data: JSON.stringify(formData, null, 2)
        });

        const apiUrl = this.getApiUrl('api.php');
        console.log('Sending POST request to:', apiUrl);

        // Add loading indicator to UI
        $('#debugInfo').html('<p>Sending request to server...</p>');

        // Log the exact request payload
        const requestPayload = JSON.stringify(formData);
        console.log('Request payload:', requestPayload);

        $.ajax({
            url: apiUrl,
            type: 'POST',
            data: requestPayload,
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

    checkResult(predictionId) {
        const apiUrl = this.getApiUrl(`public/temp/${predictionId}.json`);
        console.log('Polling URL:', apiUrl);

        // Check for timeout
        const stages = this.processStages.get(predictionId);
        if (stages && Date.now() - stages.started_at > this.timeoutDuration) {
            console.error('Generation timed out after', this.timeoutDuration / 1000, 'seconds');
            this.handleGenerationError('Generation timed out. Please try again.');
            return;
        }

        // Check for stalled process (no updates for 2 minutes)
        if (stages && Date.now() - stages.last_update > 120000) {
            console.warn('Process appears stalled, no updates for 2 minutes');
            // Increment retry count
            const state = this.cartoonificationState.get(predictionId);
            if (state && state.retries < this.maxRetries) {
                state.retries++;
                console.log(`Retrying request (${state.retries}/${this.maxRetries})`);
                // Update last_update time to prevent multiple retries
                stages.last_update = Date.now();
                // Wait before retry
                setTimeout(() => this.checkResult(predictionId), this.retryDelay);
                return;
            } else if (state && state.retries >= this.maxRetries) {
                console.error('Max retries reached');
                this.handleGenerationError('Generation process stalled. Please try again.');
                return;
            }
        }

        $.ajax({
            url: apiUrl,
            type: 'GET',
            dataType: 'json',
            success: (result) => {
                // Update last_update time on successful response
                if (stages) {
                    stages.last_update = Date.now();
                }

                console.log('Result check response:', {
                    status: result.status,
                    type: result.type,
                    has_output: !!result.output,
                    has_debug_info: !!result.debug_info,
                    cartoonified_images: result.debug_info?.used_cartoonified_images,
                    raw: result
                });

                if (result.status === 'succeeded') {
                    if (result.type === 'panel') {
                        // Final panel is complete
                        this.handleFinalResult(result);
                    } else if (result.cartoonified_url) {
                        // Cartoonification complete, now in SDXL stage
                        const stages = this.processStages.get(result.id) || {};
                        stages.current = 'sdxl';
                        stages.cartoonification = 'completed';
                        stages.sdxl = 'pending';
                        stages.progress = 75;
                        stages.last_update = Date.now(); // Update timestamp
                        this.processStages.set(result.id, stages);

                        this.updateProgressUI(result);

                        // Reset retry count for SDXL stage
                        const state = this.cartoonificationState.get(result.id);
                        if (state) {
                            state.retries = 0;
                        }

                        // Continue polling for SDXL result
                        setTimeout(() => this.checkResult(predictionId), 2000);
                    }
                } else if (result.status === 'failed') {
                    const errorMessage = result.error || 'Generation failed';
                    console.error('Generation failed:', errorMessage);

                    // Check if we should retry
                    const state = this.cartoonificationState.get(predictionId);
                    if (state && state.retries < this.maxRetries) {
                        state.retries++;
                        console.log(`Retrying after failure (${state.retries}/${this.maxRetries})`);
                        setTimeout(() => this.checkResult(predictionId), this.retryDelay);
                    } else {
                        this.handleGenerationError(errorMessage);
                    }
                } else {
                    // Still processing, continue polling
                    setTimeout(() => this.checkResult(predictionId), 2000);
                }
            },
            error: (xhr, status, error) => {
                // Update retry count on error
                const state = this.cartoonificationState.get(predictionId);
                if (state) {
                    if (xhr.status === 404) {
                        // Result not ready yet, don't count as retry
                        setTimeout(() => this.checkResult(predictionId), 2000);
                    } else if (state.retries < this.maxRetries) {
                        state.retries++;
                        console.log(`Retrying after error (${state.retries}/${this.maxRetries}):`, error);
                        setTimeout(() => this.checkResult(predictionId), this.retryDelay);
                    } else {
                        console.error('Max retries reached after error:', error);
                        this.handleGenerationError('Failed to check generation status after multiple attempts');
                    }
                } else {
                    console.error('Error checking result:', error);
                    this.handleGenerationError('Failed to check generation status');
                }
            }
        });
    },

    handleFinalResult(result) {
        // Clear polling interval
        if (this.pollingInterval) {
            clearInterval(this.pollingInterval);
            this.pollingInterval = null;
        }

        // Clean up state tracking
        if (result.id) {
            this.cartoonificationState.delete(result.id);
            this.processStages.delete(result.id);
        }

        // Update progress to 100%
        $('.progress-bar').css('width', '100%');

        // Display the final image
        const imageUrl = result.output;
        const cartoonifiedUrl = result.cartoonified_url;

        $('#debugInfo').html(`
            <p>Generation completed successfully!</p>
            ${cartoonifiedUrl ? `
                <div class="mb-3">
                    <h5>Cartoonified Character:</h5>
                    <img src="${cartoonifiedUrl}" alt="Cartoonified character" class="img-fluid mb-2" style="max-width: 300px">
                </div>
            ` : ''}
            <div>
                <h5>Final Panel:</h5>
                <img src="${imageUrl}" alt="Generated panel" class="img-fluid" style="max-width: 500px">
            </div>
        `);

        // Enable the generate button again
        UIManager.showGenerateButton();
    },

    handleGenerationError(error) {
        // Clear polling interval
        if (this.pollingInterval) {
            clearInterval(this.pollingInterval);
            this.pollingInterval = null;
        }

        // Clean up all state tracking
        this.cartoonificationState.clear();
        this.processStages.clear();

        console.error('Generation error:', error);
        $('#debugInfo').html(`
            <p class="text-danger">Error: ${error}</p>
            <p class="text-muted">Please try again. If the problem persists, try refreshing the page.</p>
        `);
        UIManager.showGenerateButton();
    }
}; 