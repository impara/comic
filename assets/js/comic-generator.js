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

            // Store the original panel ID first
            const originalPanelId = response.result.original_prediction_id || response.result.id;
            this.originalPanelId = originalPanelId;  // Store globally

            console.log('Setting up cartoonification tracking with original panel ID:', originalPanelId);

            // Initialize cartoonification state with original panel ID
            if (response.result.pending_predictions) {
                response.result.pending_predictions.forEach(predId => {
                    this.cartoonificationState.set(predId, {
                        status: 'pending',
                        started_at: new Date().getTime(),
                        original_prediction_id: originalPanelId
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

            // Start checking for results
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
        if (!predictionId) {
            console.error('Invalid prediction ID provided');
            this.handleGenerationError('Invalid prediction ID');
            return;
        }

        // Check for panel result first
        const panelApiUrl = this.getApiUrl(`public/temp/panel_${predictionId}.json`);

        $.ajax({
            url: panelApiUrl,
            type: 'GET',
            dataType: 'json',
            success: (result) => {
                console.log('Result check response:', result);

                if (result.status === 'succeeded' && result.output) {
                    this.displayGeneratedComic(result);
                } else {
                    // If panel not ready, check original prediction
                    const originalApiUrl = this.getApiUrl(`public/temp/${predictionId}.json`);
                    $.ajax({
                        url: originalApiUrl,
                        type: 'GET',
                        dataType: 'json',
                        success: (originalResult) => {
                            if (originalResult.panel_id) {
                                setTimeout(() => this.checkResult(originalResult.panel_id), 5000);
                            } else {
                                setTimeout(() => this.checkResult(predictionId), 5000);
                            }
                        },
                        error: () => {
                            setTimeout(() => this.checkResult(predictionId), 5000);
                        }
                    });
                }
            },
            error: (xhr) => {
                if (xhr.status === 404) {
                    // If panel not found, check original prediction
                    const originalApiUrl = this.getApiUrl(`public/temp/${predictionId}.json`);
                    $.ajax({
                        url: originalApiUrl,
                        type: 'GET',
                        dataType: 'json',
                        success: (originalResult) => {
                            if (originalResult.panel_id) {
                                setTimeout(() => this.checkResult(originalResult.panel_id), 5000);
                            } else {
                                setTimeout(() => this.checkResult(predictionId), 5000);
                            }
                        },
                        error: () => {
                            setTimeout(() => this.checkResult(predictionId), 5000);
                        }
                    });
                } else {
                    this.handleGenerationError('Failed to check generation status');
                }
            }
        });
    },

    displayGeneratedComic(result) {
        // Clear any polling interval
        if (this.pollingInterval) {
            clearInterval(this.pollingInterval);
            this.pollingInterval = null;
        }

        // Display the final image
        const imageUrl = result.output;
        $('.comic-preview').html(`<img src="${imageUrl}" alt="Generated panel" class="img-fluid">`);
        UIManager.showGenerateButton();
    },

    handleGenerationError(error) {
        if (this.pollingInterval) {
            clearInterval(this.pollingInterval);
            this.pollingInterval = null;
        }

        console.error('Generation error:', error);
        $('#debugInfo').html(`<p class="text-danger">Error: ${error}</p>`);
        UIManager.showGenerateButton();
    }
}; 