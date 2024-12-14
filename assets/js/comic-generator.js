import { UIManager } from './ui-manager.js';

export const ComicGenerator = {
    init() {
        this.bindEvents();
        // Get base path from current page location
        this.basePath = new URL('.', window.location.href).pathname;
        this.pollingInterval = null;
        console.log('ComicGenerator initialized with base path:', this.basePath);
    },

    bindEvents() {
        $('#payButton').on('click', (e) => this.handleComicGeneration(e));
        console.log('ComicGenerator events bound');
    },

    getApiUrl(endpoint) {
        // Ensure endpoint has no leading slash and combine with base path
        endpoint = endpoint.replace(/^\/+/, '');
        const url = new URL(endpoint, window.location.href).href;
        console.log('Generated API URL:', url);
        return url;
    },

    handleComicGeneration(e) {
        e.preventDefault();
        console.log('Comic generation started');

        // Get custom character data
        const characterData = JSON.parse(sessionStorage.getItem('characterData') || '{}');
        console.log('Retrieved character data from session:', characterData);

        // Create array of character details from custom uploads
        const characters = Object.values(characterData).map(char => {
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
                    style: sessionStorage.getItem('selectedStyle')
                }
            };
        });

        console.log('Processed characters:', characters);

        if (characters.length === 0) {
            console.error('No characters found in session storage');
            this.handleGenerationError('Please upload at least one custom character before generating the comic.');
            return;
        }

        // Get story and style from session storage
        const userStory = sessionStorage.getItem('userStory');
        const selectedStyle = sessionStorage.getItem('selectedStyle');

        console.log('Retrieved from session storage:', {
            userStory: userStory,
            selectedStyle: selectedStyle
        });

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
        console.log('Initiating comic generation with data:', {
            story_length: formData.scene_description?.length,
            art_style: formData.art_style,
            character_count: formData.characters?.length,
            characters: formData.characters
        });

        const apiUrl = this.getApiUrl('api.php');
        console.log('Sending POST request to:', apiUrl);

        $.ajax({
            url: apiUrl,
            type: 'POST',
            data: JSON.stringify(formData),
            contentType: 'application/json',
            dataType: 'json',
            success: (response) => {
                console.log('Comic generation response:', response);
                this.handleGenerationSuccess(response);
            },
            error: (xhr, status, error) => {
                console.error('Comic generation failed:', {
                    status: status,
                    error: error,
                    response: xhr.responseText,
                    headers: xhr.getAllResponseHeaders()
                });
                this.handleGenerationError(xhr.responseText || error);
            }
        });
    },

    handleGenerationSuccess(response) {
        if (response.success) {
            console.log('Comic generation initiated:', response.result);
            // Start polling for the result
            this.pollForResult(response.result.id);
        } else {
            console.error('Comic generation returned error:', response.message);
            this.handleGenerationError(response.message);
        }
    },

    pollForResult(predictionId) {
        const pollInterval = 2000; // Poll every 2 seconds
        let attempts = 0;
        const maxAttempts = 60; // Maximum 2 minutes of polling

        this.pollingInterval = setInterval(() => {
            attempts++;

            // Check if the result file exists
            $.ajax({
                url: this.getApiUrl(`public/temp/${predictionId}.json`),
                type: 'GET',
                success: (result) => {
                    if (result.status === 'succeeded' && result.output) {
                        clearInterval(this.pollingInterval);
                        this.displayGeneratedComic(result);
                    } else if (result.status === 'failed') {
                        clearInterval(this.pollingInterval);
                        this.handleGenerationError(result.error || 'Generation failed');
                    }
                },
                error: (xhr) => {
                    if (attempts >= maxAttempts) {
                        clearInterval(this.pollingInterval);
                        this.handleGenerationError('Timeout waiting for comic generation');
                    }
                    // On error, continue polling
                    console.log('Polling attempt ' + attempts + ' failed, continuing...');
                }
            });
        }, pollInterval);
    },

    displayGeneratedComic(result) {
        UIManager.hideGeneratingState(() => {
            if (result && result.output) {
                // Validate and sanitize URL
                const comicUrl = result.output;
                const isAbsoluteUrl = comicUrl.startsWith('http://') || comicUrl.startsWith('https://');
                const sanitizedUrl = isAbsoluteUrl
                    ? comicUrl
                    : window.location.origin + comicUrl;

                // Display the comic
                $('.comic-preview').html(
                    `<img src="${sanitizedUrl}" class="img-fluid mb-4" alt="Generated Comic">`
                );

                // Enable action buttons
                $('.action-buttons button').prop('disabled', false);

                // Show completion status
                UIManager.showCompletionState();
            } else {
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