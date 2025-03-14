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

        // Clear previous states
        this.reset();

        // Hide step 3 and show step 4
        $('#step3').removeClass('active');
        $('#step4').addClass('active');

        // Show generating status with initial message
        $('#generatingStatus').show().html(`
            <div class="alert alert-info generating-message">
                <i class="fas fa-spinner fa-spin me-2"></i>
                Initializing comic generation...
            </div>
            <div class="progress mb-3">
                <div class="progress-bar progress-bar-striped progress-bar-animated" 
                     role="progressbar" 
                     style="width: 0%" 
                     aria-valuenow="0" 
                     aria-valuemin="0" 
                     aria-valuemax="100">
                </div>
            </div>
            <div class="text-muted small text-center operation-status"></div>
        `);

        // Hide completion status and comic panels
        $('#completionStatus').hide();
        $('#comic-panels').hide().empty();
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

    updateProgress(progress, operation) {
        console.log('Updating progress:', progress, operation);

        // Update progress bar
        const progressBar = $('.progress-bar');
        progressBar.css('width', `${progress}%`);
        progressBar.attr('aria-valuenow', progress);

        // Update the generating message if needed
        if (progress < 100) {
            const operationText = operation || 'Generating your comic';
            $('.operation-status').text(operationText);

            // Update the main status message
            $('#generatingStatus').show().find('.generating-message').html(`
                <i class="fas fa-spinner fa-spin me-2"></i>
                ${operationText} (${progress}% complete)
            `);
        } else {
            // At 100%, show a completion message but wait for final confirmation
            $('.operation-status').text('Finalizing your comic...');
        }

        // Force progress bar animation
        progressBar.addClass('progress-bar-striped progress-bar-animated');
        setTimeout(() => progressBar[0]?.offsetHeight, 0);
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

        // Clear generating states
        $('#generatingStatus').hide().empty();
        $('.generating-message').remove();

        // Show completion message
        $('#completionStatus').show().html(`
            <div class="alert alert-success">
                <i class="fas fa-check-circle me-2"></i>
                Your comic has been generated successfully!
            </div>
        `);

        // Update progress to 100%
        $('.progress-bar')
            .css('width', '100%')
            .removeClass('progress-bar-striped progress-bar-animated');

        // Show the comic panels container
        $('#comic-panels').show();

        // Scroll to the comic panels with a slight delay
        setTimeout(() => {
            const panelsElement = document.getElementById('comic-panels');
            if (panelsElement) {
                panelsElement.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }, 100);
    },

    showCompletion(result) {
        console.log('Showing completion result:', result);

        // Clear any existing messages and states first
        $('#generatingStatus').hide().empty();
        $('.generating-message').remove();

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

        // Force a reflow to ensure UI updates
        $('#comic-panels').css('display', 'none').height();
        $('#comic-panels').css('display', 'block');
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