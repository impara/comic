export const UIManager = {
    init() {
        this.initializeProgressBar();
        // Initialize preview update listener
        document.addEventListener('formStateChanged', (event) => {
            this.updatePreview(event.detail);
        });
    },

    initializeProgressBar() {
        $('.progress-bar').css('width', '25%');
    },

    initializeSteps() {
        // Set initial step
        this.goToStep(1);

        // Initialize step navigation
        $('.step').on('click', (e) => {
            const stepNumber = $(e.currentTarget).data('step');
            if (stepNumber < this.getCurrentStep()) {
                this.goToStep(stepNumber);
            }
        });

        // Initialize step content visibility
        $('.step-content').not('#step1').removeClass('active');
        $('#step1').addClass('active');
    },

    getCurrentStep() {
        return $('.step.active').data('step');
    },

    goToStep(stepNumber) {
        // Update progress bar
        $('.progress-bar').css('width', `${stepNumber * 25}%`);

        // Update step indicators
        $('.step').removeClass('active');
        $(`.step[data-step="${stepNumber}"]`).addClass('active');

        // Hide current step and show target step
        $('.step-content').removeClass('active');
        $(`#step${stepNumber}`).addClass('active');

        // Smooth scroll to top of wizard
        $('html, body').animate({
            scrollTop: $('.wizard-container').offset().top - 50
        }, 500);
    },

    returnToStep(stepNumber) {
        $(`#step${stepNumber + 1}`).removeClass('active');
        $(`#step${stepNumber}`).addClass('active');
    },

    showGeneratingState() {
        console.log('Showing generating state');
        // Hide step 3 and show step 4
        $('#step3').removeClass('active');
        $('#step4').addClass('active');

        // Show generating status with spinner
        $('#generatingStatus').show().html(`
            <div class="alert alert-info generating-message">
                <i class="fas fa-spinner fa-spin me-2"></i>
                Your comic is being generated. Please wait...
            </div>
        `);

        // Hide completion status and comic panels
        $('#completionStatus').hide();
        $('#comic-panels').hide().empty();
        $('.progress-bar').css('width', '0%');
    },

    showError(message) {
        if (message.includes('NSFW') || message.includes('blocked')) {
            message += '<div class="mt-3 alert alert-warning">Tip: Avoid violent, dangerous, or adult themes. Focus on heroic actions and positive outcomes.</div>';
        }
        $('#debugInfo').html(`<p class="text-danger">Error: ${message}</p>`);
        $('#generatingStatus').hide();
        $('#generateButton').prop('disabled', false).show();
        $('.progress-bar').css('width', '0%');
    },

    updateProgress(progress) {
        console.log('Updating progress:', progress);
        $('.progress-bar').css('width', `${progress}%`);

        // Update the generating message if needed
        if (progress < 100) {
            $('#generatingStatus').show().find('.generating-message').html(`
                <i class="fas fa-spinner fa-spin me-2"></i>
                Your comic is being generated (${progress}% complete)...
            `);
        }
    },

    updateDebugInfo(info) {
        const debugHtml = `
            <div class="debug-info p-3 border rounded mb-3">
                <h5>Comic Generation Status</h5>
                <div class="debug-section">
                    <p><strong>Strip ID:</strong> ${info.stripId}</p>
                    <p><strong>Status:</strong> ${info.status}</p>
                    <p><strong>Last Update:</strong> ${info.last_update}</p>
                    <p><strong>Current Operation:</strong> ${info.current_operation}</p>
                </div>
                
                <div class="debug-section mt-3">
                    <h6>Progress</h6>
                    <p><strong>Characters:</strong> ${info.characters}</p>
                    <p><strong>Panels:</strong> ${info.panels}</p>
                </div>

                ${info.state_history.length > 0 ? `
                    <div class="debug-section mt-3">
                        <h6>State History</h6>
                        <div class="small" style="max-height: 100px; overflow-y: auto;">
                            ${info.state_history.map(state => `
                                <div class="debug-history-item">
                                    <small>${state.timestamp}: ${state.state} - ${state.message || 'No message'}</small>
                                </div>
                            `).join('')}
                        </div>
                    </div>
                ` : ''}
            </div>
        `;

        $('#debugInfo').html(debugHtml);
    },

    showCompletionState() {
        console.log('Showing completion state');
        // Hide generating message
        $('#generatingStatus').hide().empty();

        // Show completion message
        $('#completionStatus').show().html(`
            <div class="alert alert-success">
                <i class="fas fa-check-circle me-2"></i>
                Your comic has been generated successfully!
            </div>
        `);

        // Update progress and show panels
        $('.progress-bar').css('width', '100%');
        $('#comic-panels').show();

        // Scroll to the comic panels
        const panelsElement = document.getElementById('comic-panels');
        if (panelsElement) {
            panelsElement.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    },

    showCompletion(result) {
        console.log('Showing completion result:', result);

        // Clear any existing messages first
        $('#generatingStatus').hide().empty();

        // Show completion state
        this.showCompletionState();

        if (result.errors && result.errors.length > 0) {
            const errorHtml = result.errors.map(error =>
                `<div class="alert alert-danger">${error}</div>`
            ).join('');
            $('#debugInfo').html(errorHtml);
        } else {
            $('#debugInfo').html('');
        }

        // Enable sharing buttons if present
        $('.share-button').prop('disabled', false);

        // Update step indicators
        $('.step').removeClass('active completed');
        $('.step[data-step="4"]').addClass('active completed');
    },

    displayResult(imagePath) {
        $('.comic-preview').html(`
            <img src="${imagePath}" class="img-fluid mb-4" alt="Generated Comic">
        `);
    },

    reset() {
        $('#debugInfo').empty();
        $('#generatingStatus').hide();
        $('#completionStatus').hide();
        $('.progress-bar').css('width', '0%');
        $('.comic-preview').empty();
        $('#generateButton').prop('disabled', false).show();
    },

    showGenerateButton() {
        $('#generatingStatus').hide();
        $('#generateButton').prop('disabled', false).show();
    },

    updatePreview(formState) {
        const preview = $('#comicPreview');
        const characterData = JSON.parse(sessionStorage.getItem('characterData') || '{}');
        const hasCharacters = Object.keys(characterData).length > 0;
        const selectedStyle = sessionStorage.getItem('selectedStyle');

        if (!selectedStyle || !hasCharacters) {
            preview.html(`
                <div class="preview-placeholder">
                    <i class="fas fa-image fa-3x mb-3 text-muted"></i>
                    <p class="text-muted">Your comic preview will appear here as you make selections</p>
                </div>
            `);
            return;
        }

        // Get character names
        const characterNames = Object.values(characterData)
            .map(char => char.name)
            .join(', ');

        preview.html(`
            <div class="preview-content">
                <p class="mb-2">Selected Style: ${this.capitalizeFirstLetter(selectedStyle)}</p>
                <p class="mb-2">Characters: ${characterNames}</p>
            </div>
        `);
    },

    capitalizeFirstLetter(string) {
        return string ? string.charAt(0).toUpperCase() + string.slice(1) : '';
    }
}; 