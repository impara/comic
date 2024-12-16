import { UIManager } from './ui-manager.js';

export const ComicGenerator = {
    init() {
        // Get base path from current page location
        this.basePath = new URL('.', window.location.href).pathname;
        this.pollingInterval = null;
        console.log('ComicGenerator initialized with base path:', this.basePath);
    },

    handleGenerationSuccess(response) {
        if (response.success) {
            console.log('Comic generation initiated:', response.result);

            // Update UI to show progress
            $('#debugInfo').html(`
                <p>Generation started successfully</p>
                <p>Prediction ID: ${response.result.id}</p>
                <p>Status: ${response.result.status}</p>
                ${response.result.pending_predictions ?
                    `<p>Waiting for cartoonification: ${response.result.pending_predictions.join(', ')}</p>` : ''}
            `);

            // Update progress bar
            $('.progress-bar').css('width', '25%');

            // Start checking for results
            this.checkResult(response.result.id);
        } else {
            console.error('Comic generation returned error:', response.message);
            this.handleGenerationError(response.message);
        }
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
        console.log('Generated API URL:', apiUrl);
        console.log('Polling URL:', apiUrl);

        $.ajax({
            url: apiUrl,
            type: 'GET',
            dataType: 'json',
            success: (result) => {
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
                        // Check if we have panel output
                        if (result.output) {
                            console.log('Panel generation completed with output:', result.output);
                            this.displayGeneratedComic(result);
                            return;
                        }

                        // Check if we have pending cartoonifications
                        if (result.pending_predictions && result.pending_predictions.length > 0) {
                            console.log('Panel has pending cartoonifications:', result.pending_predictions);

                            // Update progress bar based on completed cartoonifications
                            const totalCartoonifications = result.pending_predictions.length;
                            const completedCartoonifications = Array.from(this.cartoonificationState.values())
                                .filter(state => state.status === 'completed').length;
                            const progress = 25 + ((completedCartoonifications / totalCartoonifications) * 25);
                            $('.progress-bar').css('width', `${progress}%`);

                            // Update UI to show cartoonification progress
                            $('#debugInfo').html(`
                                <p>Cartoonifying characters...</p>
                                <p>Progress: ${completedCartoonifications} of ${totalCartoonifications} completed</p>
                                <p>Pending IDs: ${result.pending_predictions.join(', ')}</p>
                            `);

                            // Start tracking each pending cartoonification
                            result.pending_predictions.forEach(predId => {
                                if (!this.cartoonificationState.has(predId)) {
                                    this.cartoonificationState.set(predId, {
                                        status: 'pending',
                                        started_at: new Date().getTime()
                                    });
                                    setTimeout(() => this.checkResult(predId), 5000);
                                }
                            });

                            // Continue polling this panel
                            setTimeout(() => this.checkResult(predictionId), 5000);
                            return;
                        }

                        // If we get here, panel is complete but may be missing output
                        console.error('Panel completed but missing output:', result);
                        this.handleGenerationError('Panel generation completed but output is missing');
                    } else if (result.type === 'cartoonification') {
                        console.log('Cartoonification completed:', result);

                        // Update UI to show progress
                        const completedCount = Array.from(this.cartoonificationState.values())
                            .filter(state => state.status === 'completed').length + 1;

                        // Update progress bar for cartoonification phase (25-50%)
                        const totalCartoonifications = this.cartoonificationState.size;
                        const progress = 25 + ((completedCount / totalCartoonifications) * 25);
                        $('.progress-bar').css('width', `${progress}%`);

                        $('#debugInfo').html(`
                            <p>Character cartoonification completed: ${completedCount} of ${totalCartoonifications}</p>
                            <p>Processing final panel...</p>
                        `);

                        // Store the cartoonified image URL
                        this.cartoonificationState.set(predictionId, {
                            status: 'completed',
                            output: result.output,
                            completed_at: new Date().getTime()
                        });

                        // Continue polling the original prediction
                        if (result.original_prediction_id) {
                            setTimeout(() => this.checkResult(result.original_prediction_id), 5000);
                        }
                    }
                } else if (result.status === 'failed') {
                    console.error('Generation failed:', result);
                    this.handleGenerationError(result.error || 'Generation failed');
                } else {
                    // Continue polling for incomplete status
                    setTimeout(() => this.checkResult(predictionId), 5000);
                }
            },
            error: (xhr, status, error) => {
                console.error('Error checking result:', {
                    status: status,
                    error: error,
                    response: xhr.responseText
                });

                // If file not found, wait and retry
                if (xhr.status === 404) {
                    setTimeout(() => this.checkResult(predictionId), 5000);
                } else {
                    this.handleGenerationError('Failed to check generation status');
                }
            }
        });
    },

    displayGeneratedComic(result) {
        console.log('Displaying generated comic:', result);

        UIManager.hideGeneratingState(() => {
            if (result && (result.output || result.panels)) {
                // Handle both single panel and multi-panel results
                const panels = result.panels || [result.output];
                console.log('Comic panels:', panels);

                // Create a container for the panels
                let panelHtml = '<div class="comic-strip-container">';

                // Add each panel with loading state
                panels.forEach((panelUrl, index) => {
                    if (panelUrl) {
                        panelHtml += `
                            <div class="comic-panel">
                                <div class="panel-loading">Loading panel ${index + 1}...</div>
                                <img src="${panelUrl}" class="img-fluid mb-4" 
                                     alt="Comic Panel ${index + 1}" 
                                     onload="this.parentElement.querySelector('.panel-loading').style.display='none';"
                                     onerror="this.onerror=null; this.src='assets/images/error.png'; this.parentElement.querySelector('.panel-loading').innerHTML='Failed to load panel';">
                            </div>
                        `;
                    }
                });

                panelHtml += '</div>';

                // Display the comic strip
                $('.comic-preview').html(panelHtml);

                // Add CSS for panel layout
                const style = `
                    <style>
                        .comic-strip-container {
                            display: grid;
                            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
                            gap: 20px;
                            padding: 20px;
                            background: #fff;
                            border-radius: 10px;
                            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
                        }
                        .comic-panel {
                            position: relative;
                            background: #fff;
                            padding: 10px;
                            border: 2px solid #000;
                            border-radius: 5px;
                            min-height: 200px;
                        }
                        .comic-panel img {
                            width: 100%;
                            height: auto;
                            object-fit: cover;
                            border-radius: 3px;
                        }
                        .panel-loading {
                            position: absolute;
                            top: 50%;
                            left: 50%;
                            transform: translate(-50%, -50%);
                            text-align: center;
                            color: #666;
                            font-style: italic;
                        }
                    </style>
                `;

                // Add the style to the head if it doesn't exist
                if (!$('head style:contains(".comic-strip-container")').length) {
                    $('head').append(style);
                }

                // Enable action buttons
                $('.action-buttons button').prop('disabled', false);

                // Show completion status
                UIManager.showCompletionState();

                // Update debug info with cartoonification status if available
                const debugInfo = result.debug_info ? `
                    <div class="mt-3">
                        <h6>Generation Details:</h6>
                        <ul class="list-unstyled">
                            <li>Total Images: ${result.debug_info.cartoonification_status?.total_images || 'N/A'}</li>
                            <li>Valid Images: ${result.debug_info.cartoonification_status?.valid_images || 'N/A'}</li>
                            <li>Completed: ${result.debug_info.cartoonification_status?.timestamp || 'N/A'}</li>
                        </ul>
                    </div>
                ` : '';

                $('#debugInfo').html(`
                    <p>Comic generation completed successfully!</p>
                    <p>Number of panels: ${panels.length}</p>
                    ${panels.map((url, i) => `<p>Panel ${i + 1} URL: ${url}</p>`).join('')}
                    ${debugInfo}
                `);
            } else {
                console.error('No output URL in result:', result);
                this.handleGenerationError('Comic generation completed but no output URL found');
            }
        });
    },

    handleGenerationError(message = 'An error occurred while generating the comic. Please try again.') {
        console.error('Handling generation error:', message);
        // Clear any existing polling
        if (this.pollingInterval) {
            clearInterval(this.pollingInterval);
        }
        // Add debug info display when in debug mode
        if (window.APP_DEBUG) {
            $('#debugInfo').html(`<pre>${JSON.stringify(message, null, 2)}</pre>`);
        }
        alert(message);
        UIManager.returnToStep(3);
    }
}; 