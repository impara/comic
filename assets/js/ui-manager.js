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
        $('.progress-bar').css('width', '100%');
        $('#step3').removeClass('active');
        $('#step4').addClass('active');
        $('#generatingStatus').show();
        $('#completionStatus').hide();

        // Wait for DOM updates to complete before scrolling
        setTimeout(() => {
            const $wizardContainer = $('.wizard-container');
            const scrollTarget = $wizardContainer.length ?
                $wizardContainer.offset().top - 50 :
                0;

            $('html, body').animate({
                scrollTop: scrollTarget
            }, {
                duration: 500,
                easing: 'swing',
                complete: () => {
                    // Force a second scroll in case of any race conditions
                    window.scrollTo({
                        top: scrollTarget,
                        behavior: 'smooth'
                    });
                }
            });
        }, 100);
    },

    hideGeneratingState(callback) {
        $('#generatingStatus').fadeOut(400, () => {
            if (typeof callback === 'function') {
                callback();
            }
        });
    },

    showCompletionState() {
        try {
            // Show completion state with animation
            $('#completionStatus').fadeIn(400);
        } catch (e) {
            console.error('Error in showCompletionState:', e);
            // Fallback to direct show without animation
            $('#completionStatus').show();
        }
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