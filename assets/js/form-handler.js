import { UIManager } from './ui-manager.js';
import { ComicGenerator } from './comic-generator.js';

const FormHandler = {
    // Version tracking
    version: '1.0.2',

    // Configuration
    minChars: 50,
    maxChars: 500,
    selectedStyle: null,
    selectedCharacters: [],
    selectedBackground: null,
    customCharacters: [],
    nextCustomId: 1000,
    maxCharacters: 2,
    characters: [
        { id: 1, name: 'Hero', image: 'assets/characters/hero.png' },
        { id: 2, name: 'Sidekick', image: 'assets/characters/sidekick.png' },
        { id: 3, name: 'Villain', image: 'assets/characters/villain.png' },
        { id: 4, name: 'Mentor', image: 'assets/characters/mentor.png' },
        { id: 5, name: 'Friend', image: 'assets/characters/friend.png' },
        { id: 6, name: 'Pet', image: 'assets/characters/pet.png' }
    ],

    init() {
        // Bind all methods to this instance
        Object.getOwnPropertyNames(Object.getPrototypeOf(this))
            .filter(method => typeof this[method] === 'function')
            .forEach(method => {
                this[method] = this[method].bind(this);
            });

        // Wait for DOM to be ready
        $(document).ready(() => {
            // Check if we're on the input page
            const $storyInput = $('#story-input');
            if (!$storyInput.length) return;

            this.bindEvents();
            this.initializeCharacterCount();
            this.initializeFileUpload();
            this.initializeEventHandlers();
        });
    },

    bindEvents() {
        // Story input handling
        const $storyInput = $('#story-input');
        $storyInput.on('input', () => {
            this.updateCharacterCount();
            // Store story in session storage on input
            const story = $storyInput.val().trim();
            sessionStorage.setItem('userStory', story);
        });

        // Example prompts - use arrow function to preserve this context
        $(document).on('click', '.example-prompt', (e) => this.handleExamplePrompt(e));

        // Style selection
        $(document).on('click', '.style-option', (e) => this.handleStyleSelection(e));

        // Background selection
        $(document).on('click', '.background-option', (e) => this.handleBackgroundSelection(e));

        // Character upload
        $('#uploadCharacter').on('click', (e) => this.handleCharacterUpload(e));
        $('#characterImage').on('change', (e) => this.handleCharacterImagePreview(e));
        $('#characterName').on('input', (e) => this.handleCharacterNameInput(e));

        // Navigation buttons
        $('#next-step-1').on('click', (e) => this.handleNextStep1(e));
        $('#next-step-2').on('click', (e) => this.handleNextStep2(e));
        $('#back-step-2').on('click', () => this.handleBackStep(1));
        $('#back-step-3').on('click', () => this.handleBackStep(2));

        // Add Pay Now button handler
        $('#payButton').on('click', (e) => {
            e.preventDefault();

            // Validate all required data is present
            const userStory = sessionStorage.getItem('userStory');
            const selectedStyle = sessionStorage.getItem('selectedStyle');
            const selectedBackground = sessionStorage.getItem('selectedBackground');
            const characterData = JSON.parse(sessionStorage.getItem('characterData') || '{}');

            if (!userStory || !selectedStyle || !this.selectedCharacters.length || !selectedBackground) {
                UIManager.showError('Please complete all required steps before generating the comic.');
                return;
            }

            // Move to generation step
            this.handleNextStep2(e);
            UIManager.showGeneratingState();

            // Prepare character data
            const characters = this.selectedCharacters.map(id => {
                const char = characterData[id];
                if (!char) return null;
                return {
                    id: char.id,
                    name: char.name,
                    description: char.description || char.name,
                    image: char.image,
                    isCustom: char.isCustom,
                    options: {
                        style: selectedStyle,
                        position: char.position || 'center',
                        scale: char.scale || 1.0
                    }
                };
            }).filter(Boolean);

            if (characters.length === 0) {
                UIManager.showError('No valid characters selected. Please select at least one character.');
                return;
            }

            // Initialize ComicGenerator if not already initialized
            if (!ComicGenerator.uiManager) {
                ComicGenerator.init();
            }

            // Generate the comic
            ComicGenerator.generateStrip(userStory, characters, {
                style: selectedStyle,
                background: selectedBackground,
                metadata: {
                    created_at: new Date().toISOString(),
                    version: this.version
                }
            });
        });

        // Initialize character selection handlers
        this.initializeCharacterSelectionHandlers();
    },

    initializeCharacterGrid() {
        const grid = $('#characterGrid');
        const customCharactersList = $('#customCharactersList');
        grid.empty();
        customCharactersList.empty();

        // Load any existing custom characters
        const storedCharacters = JSON.parse(sessionStorage.getItem('characterData') || '{}');
        const characters = Object.values(storedCharacters);

        if (characters.length === 0) {
            grid.append(`
                <div class="col-12 text-center text-muted py-4">
                    <i class="fas fa-user-plus fa-2x mb-2"></i>
                    <p>No characters uploaded yet. Upload your first character above.</p>
                </div>
            `);
        } else {
            characters.forEach(char => {
                if (char.isCustom) {
                    // Add to grid
                    grid.append(`
                        <div class="col-4 col-md-2">
                            <div class="character-option p-2" data-character-id="${char.id}">
                                <img src="${char.image}" class="img-fluid rounded" alt="${char.name}">
                                <div class="text-center mt-2">${char.name}</div>
                                <div class="selected-overlay">
                                    <i class="fas fa-check-circle"></i>
                                </div>
                            </div>
                        </div>
                    `);

                    // Add to tags list
                    customCharactersList.append(`
                        <div class="custom-character-tag d-inline-flex align-items-center bg-primary text-white rounded-pill px-3 py-2 me-2 mb-2" data-character-id="${char.id}">
                            <span class="me-2">${char.name}</span>
                            <span class="remove-character" data-character-id="${char.id}" style="cursor: pointer;">
                                <i class="fas fa-times"></i>
                            </span>
                        </div>
                    `);
                }
            });
        }

        // Initialize character selection handlers
        this.initializeCharacterSelectionHandlers();
    },

    initializeCharacterSelectionHandlers() {
        // Remove previous handlers to avoid duplicates
        $(document).off('click', '.character-option');
        $('#characterGrid, #customCharactersList').off('click', '.remove-character');

        // Handle character selection
        $(document).on('click', '.character-option', (e) => {
            e.preventDefault();
            e.stopPropagation();
            const $target = $(e.currentTarget);
            const characterId = $target.data('character-id');

            if (!characterId) return;

            // Toggle selection
            if ($target.hasClass('selected')) {
                // Remove from selection
                this.selectedCharacters = this.selectedCharacters.filter(id => id !== characterId);
                $(`.character-option[data-character-id="${characterId}"]`).removeClass('selected');
                $(`.custom-character-tag[data-character-id="${characterId}"]`).removeClass('selected');
            } else if (this.selectedCharacters.length < this.maxCharacters) {
                // Add to selection
                this.selectedCharacters.push(characterId);
                $(`.character-option[data-character-id="${characterId}"]`).addClass('selected');
                $(`.custom-character-tag[data-character-id="${characterId}"]`).addClass('selected');
            } else {
                this.showErrorMessage(`Maximum ${this.maxCharacters} characters allowed`);
                return;
            }

            // Store selected characters in session
            sessionStorage.setItem('selectedCharacters', JSON.stringify(this.selectedCharacters));

            // Update UI and preview
            this.updateFormState();
            this.updateLivePreview();
        });

        // Handle character removal
        $('#characterGrid, #customCharactersList').on('click', '.remove-character', (e) => {
            e.preventDefault();
            e.stopPropagation();
            const characterId = $(e.currentTarget).data('character-id');
            if (characterId) {
                this.removeCharacter(characterId);
            }
        });

        // Restore any previously selected characters
        const storedSelections = JSON.parse(sessionStorage.getItem('selectedCharacters') || '[]');
        storedSelections.forEach(characterId => {
            $(`.character-option[data-character-id="${characterId}"]`).addClass('selected');
            $(`.custom-character-tag[data-character-id="${characterId}"]`).addClass('selected');
        });
        this.selectedCharacters = storedSelections;
        this.updateLivePreview();
    },

    initializeCustomCharacters() {
        const storedCharacters = JSON.parse(sessionStorage.getItem('characterData') || '{}');
        Object.values(storedCharacters).forEach(char => {
            if (char.isCustom && !this.characters.some(c => c.id === char.id)) {
                this.characters.push(char);
                this.customCharacters.push(char);
                $('#characterGrid').append(`
                    <div class="col-4 col-md-2">
                        <div class="character-option p-2" data-character-id="${char.id}">
                            <img src="${char.image}" class="img-fluid rounded" alt="${char.name}">
                            <div class="text-center mt-2">${char.name}</div>
                            <div class="selected-overlay">
                                <i class="fas fa-check-circle"></i>
                            </div>
                        </div>
                    </div>
                `);
            }
        });
    },

    handleCharacterImagePreview(e) {
        const file = e.target.files[0];
        if (file) {
            console.log('Processing character image:', {
                name: file.name,
                type: file.type,
                size: file.size
            });

            // Validate file
            if (!['image/jpeg', 'image/png', 'image/jpg'].includes(file.type)) {
                alert('Please upload only PNG or JPEG images.');
                this.resetCharacterPreview();
                return;
            }
            if (file.size > 5 * 1024 * 1024) { // 5MB
                alert('File size should not exceed 5MB.');
                this.resetCharacterPreview();
                return;
            }

            // Preview image with consistent dimensions
            const reader = new FileReader();
            reader.onload = (e) => {
                console.log('Image loaded into FileReader');
                const img = new Image();
                img.onload = () => {
                    console.log('Image loaded for processing:', {
                        width: img.width,
                        height: img.height
                    });

                    const canvas = document.createElement('canvas');
                    const ctx = canvas.getContext('2d');
                    canvas.width = 512;  // Standard size for AI models
                    canvas.height = 512; // Standard size for AI models

                    // Calculate scaling and positioning for center crop
                    const scale = Math.max(canvas.width / img.width, canvas.height / img.height);
                    const x = (canvas.width - img.width * scale) / 2;
                    const y = (canvas.height - img.height * scale) / 2;

                    // Fill with white background
                    ctx.fillStyle = '#FFFFFF';
                    ctx.fillRect(0, 0, canvas.width, canvas.height);

                    // Draw image centered and scaled
                    ctx.drawImage(img, x, y, img.width * scale, img.height * scale);

                    // Get base64 with proper MIME type prefix
                    const resizedImage = canvas.toDataURL('image/png');

                    // Store the full base64 string for API submission
                    const customCharId = this.nextCustomId++;
                    const name = $('#characterName').val() || 'Custom Character';
                    const customChar = {
                        id: customCharId,
                        name: name,
                        image: resizedImage,
                        isCustom: true,
                        createdAt: new Date().toISOString()
                    };

                    console.log('Created custom character:', {
                        id: customCharId,
                        name: name,
                        imageLength: resizedImage.length
                    });

                    // Add to characters array and update storage
                    this.characters.push(customChar);
                    this.customCharacters.push(customChar);

                    // Update character data in session storage
                    const characterData = JSON.parse(sessionStorage.getItem('characterData') || '{}');
                    characterData[customCharId] = customChar;
                    sessionStorage.setItem('characterData', JSON.stringify(characterData));

                    // Automatically select the new character
                    if (this.selectedCharacters.length < this.maxCharacters) {
                        this.selectedCharacters.push(customCharId);
                        sessionStorage.setItem('selectedCharacters', JSON.stringify(this.selectedCharacters));
                    }

                    console.log('Updated session storage with character data:', {
                        characterData: characterData,
                        selectedCharacters: this.selectedCharacters
                    });

                    // Update UI
                    this.initializeCharacterGrid();
                    this.updateFormState();
                    this.updateLivePreview();

                    // Clear the file input
                    $('#characterImage').val('');
                    $('#characterName').val('');
                };
                img.src = e.target.result;
            };
            reader.readAsDataURL(file);
        }
    },

    resetCharacterPreview() {
        $('#characterImage').val('');
        $('#characterPreview img').attr('src', 'assets/images/placeholder-character.png');
        $('#characterPreview .preview-name').text('No image selected');
    },

    handleCharacterNameInput(e) {
        const name = $(e.target).val() || 'Custom Character';
        $('#characterPreview .preview-name').text(name);
    },

    initializeCharacterCount() {
        const $storyInput = $('#story-input');
        if (!$storyInput.length) {
            console.warn('Story input element not found');
            return;
        }
        const currentLength = $storyInput.val().length;
        $('#currentCount').text(currentLength);
        this.updateNextButtonState(currentLength);
    },

    updateCharacterCount() {
        const $storyInput = $('#story-input');
        const $currentCount = $('#currentCount');

        if (!$storyInput.length || !$currentCount.length) {
            console.warn('Required elements for character count not found');
            return;
        }

        const currentLength = $storyInput.val().length;
        $currentCount.text(currentLength);
        this.updateNextButtonState(currentLength);
    },

    updateNextButtonState(length) {
        const $nextButton = $('#next-step-1');
        const $charRequirement = $('#char-requirement');

        if (length < this.minChars) {
            $nextButton.prop('disabled', true);
            $charRequirement.removeClass('text-success').addClass('text-danger');
            $charRequirement.text(`Minimum ${this.minChars} characters required (${this.minChars - length} more needed)`);
        } else if (length > this.maxChars) {
            $nextButton.prop('disabled', true);
            $charRequirement.removeClass('text-success').addClass('text-danger');
            $charRequirement.text(`Maximum ${this.maxChars} characters allowed (${length - this.maxChars} too many)`);
        } else {
            $nextButton.prop('disabled', false);
            $charRequirement.removeClass('text-danger').addClass('text-success');
            $charRequirement.text('Story length is good!');
        }
    },

    handleExamplePrompt(e) {
        const promptText = $(e.target).text().trim();
        const $storyInput = $('#story-input');
        $storyInput.val(promptText);
        this.updateCharacterCount();

        // Smooth scroll back to textarea
        $('html, body').animate({
            scrollTop: $storyInput.offset().top - 100
        }, 500);
    },

    handleStyleSelection(e) {
        e.preventDefault();
        const $selected = $(e.currentTarget);
        $('.style-option').removeClass('selected');
        $selected.addClass('selected');

        this.selectedStyle = $selected.data('style');
        this.selectedStyleImage = $selected.find('img').attr('src');

        // Store style immediately in session storage
        sessionStorage.setItem('selectedStyle', this.selectedStyle);

        // Update preview immediately
        this.updateLivePreview();
    },

    handleBackgroundSelection(e) {
        e.preventDefault();
        const $selected = $(e.currentTarget);
        $('.background-option').removeClass('selected');
        $selected.addClass('selected');

        this.selectedBackground = $selected.data('background');
        this.selectedBackgroundImage = $selected.find('img').attr('src');

        // Store background in session storage
        sessionStorage.setItem('selectedBackground', this.selectedBackground);

        // Update preview immediately
        this.updateLivePreview();
    },

    updateCharacterPreview() {
        const characterData = JSON.parse(sessionStorage.getItem('characterData') || '{}');
        const characterPreviewGrid = $('#characterPreviewGrid').empty();

        if (Object.keys(characterData).length === 0) {
            characterPreviewGrid.append(`
                <div class="col-12 text-center text-muted">
                    <p>No characters uploaded yet</p>
                </div>
            `);
            return;
        }

        Object.values(characterData).forEach(char => {
            characterPreviewGrid.append(`
                <div class="col-md-4">
                    <div class="card h-100">
                        <img src="${char.image}" class="card-img-top character-preview-img" alt="${char.name}">
                        <div class="card-body">
                            <h5 class="card-title">${char.name}</h5>
                            <button class="btn btn-danger btn-sm remove-character" data-character-id="${char.id}">
                                Remove
                            </button>
                        </div>
                    </div>
                </div>
            `);
        });
    },

    addCharacter(characterId) {
        if (!this.selectedCharacters.includes(characterId)) {
            this.selectedCharacters.push(characterId);
            $(`.character-option[data-character-id="${characterId}"]`).each((_, el) => {
                const $el = $(el);
                $el.addClass('selected');
                $el.find('.selected-overlay').css('display', 'flex');
            });
            this.updateSelectedCharactersList();
            this.checkSelections();
        }
    },

    removeCharacter(characterId) {
        try {
            const characterData = JSON.parse(sessionStorage.getItem('characterData') || '{}');
            const character = characterData[characterId];

            if (!character) {
                console.warn('Character not found in storage:', characterId);
                this.cleanupCharacterUI(characterId);
                return;
            }

            // Show confirmation modal
            this.showRemoveConfirmation(character).then(confirmed => {
                if (!confirmed) return;

                // Remove from storage
                delete characterData[characterId];
                sessionStorage.setItem('characterData', JSON.stringify(characterData));

                // Update backup
                try {
                    localStorage.setItem('characterData_backup', JSON.stringify(characterData));
                } catch (e) {
                    console.warn('Failed to update backup:', e);
                }

                // Remove from selected characters
                this.selectedCharacters = this.selectedCharacters.filter(id => id !== characterId);
                sessionStorage.setItem('selectedCharacters', JSON.stringify(this.selectedCharacters));

                // Update UI with animation
                this.cleanupCharacterUI(characterId);

                // Show success message
                this.showSuccessMessage(`Character "${character.name}" removed successfully.`);
            });

        } catch (error) {
            console.error('Error removing character:', error);
            this.showErrorMessage('There was an error removing the character. Please try again.');
        }
    },

    showRemoveConfirmation(character) {
        return new Promise((resolve) => {
            const modalHtml = `
                <div class="modal fade" id="removeCharacterModal">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Remove Character</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <p>Are you sure you want to remove "${character.name}"?</p>
                                <div class="text-center">
                                    <img src="${character.image}" class="img-fluid rounded mb-2" style="max-height: 150px;" alt="${character.name}">
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="button" class="btn btn-danger" id="confirmRemove">Remove</button>
                            </div>
                        </div>
                    </div>
                </div>`;

            const $modal = $(modalHtml).appendTo('body');
            const modal = new bootstrap.Modal($modal[0]);

            $modal.on('hidden.bs.modal', () => {
                $modal.remove();
                resolve(false);
            });

            $('#confirmRemove').on('click', () => {
                modal.hide();
                resolve(true);
            });

            modal.show();
        });
    },

    cleanupCharacterUI(characterId) {
        // Remove from grid with animation
        const $characterOption = $(`.character-option[data-character-id="${characterId}"]`).parent();
        $characterOption.fadeOut(300, function () {
            $(this).remove();
            // Show empty state if no characters left
            const $grid = $('#characterGrid');
            if ($grid.children().length === 0) {
                $grid.html(`
                    <div class="col-12 text-center text-muted">
                        <p>No characters uploaded yet. Upload your first character above.</p>
                    </div>
                `);
            }
        });

        // Remove from tags list with animation
        const $characterTag = $(`.custom-character-tag[data-character-id="${characterId}"]`);
        $characterTag.fadeOut(300, function () {
            $(this).remove();
            // Show empty state if no tags left
            const $tagsList = $('#customCharactersList');
            if ($tagsList.children().length === 0) {
                $tagsList.html('<div class="text-muted">No characters uploaded yet</div>');
            }
        });

        // Update form state and preview
        this.updateFormState();
        this.updateLivePreview();
    },

    showErrorMessage(message) {
        const alertHtml = `
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        `;
        $('#characterGrid').before(alertHtml);

        // Auto-dismiss after 5 seconds
        setTimeout(() => {
            $('.alert').alert('close');
        }, 5000);
    },

    showSuccessMessage(message) {
        const alertHtml = `
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        `;
        $('#characterGrid').before(alertHtml);

        // Auto-dismiss after 3 seconds
        setTimeout(() => {
            $('.alert').alert('close');
        }, 3000);
    },

    resetUploadForm() {
        $('#characterName').val('');
        $('#characterUpload').val('');
        this.currentUploadedImage = null;
        this.resetCharacterPreview();
    },

    handleNextStep1(e) {
        e.preventDefault();
        if (this.validateStep1()) {
            document.dispatchEvent(new CustomEvent('changeStep', { detail: { step: 2 } }));
        }
    },

    handleNextStep2(e) {
        e.preventDefault();
        if (this.validateStep2()) {
            document.dispatchEvent(new CustomEvent('changeStep', { detail: { step: 3 } }));
        }
    },

    handleBackStep(step) {
        document.dispatchEvent(new CustomEvent('changeStep', { detail: { step: step } }));
    },

    updateReviewSection() {
        // Update story preview
        $('#storyPreview').text(sessionStorage.getItem('userStory'));

        // Update style preview
        const stylePreview = $('#stylePreview');
        stylePreview.empty().append(`
            <div class="row align-items-center">
                <div class="col-auto">
                    <img src="${this.selectedStyleImage}" alt="Selected Style" class="img-fluid rounded" style="max-width: 150px;">
                </div>
                <div class="col">
                    <h6 class="mb-0">${this.capitalizeFirstLetter(this.selectedStyle)}</h6>
                </div>
            </div>
        `);

        // Update character preview
        const characterPreviewGrid = $('#characterPreviewGrid').empty();
        this.selectedCharacters.forEach(charId => {
            const char = this.findCharacter(charId);
            if (char) {
                characterPreviewGrid.append(`
                    <div class="col-4">
                        <div class="character-preview-item">
                            <img src="${char.image}" 
                                 class="img-fluid rounded mb-2" 
                                 alt="${char.name}"
                                 style="max-height: 150px; width: 100%; object-fit: cover;">
                            <div class="text-center small">${char.name}</div>
                        </div>
                    </div>
                `);
            }
        });

        // Update background preview
        const backgroundPreview = $('#backgroundPreview');
        backgroundPreview.empty().append(`
            <div class="row align-items-center">
                <div class="col-auto">
                    <img src="${this.selectedBackgroundImage}" alt="Selected Background" class="img-fluid rounded" style="max-width: 150px;">
                </div>
                <div class="col">
                    <h6 class="mb-0">${this.capitalizeFirstLetter(this.selectedBackground)}</h6>
                </div>
            </div>
        `);
    },

    checkSelections() {
        const styleSelected = this.selectedStyle !== null;
        const charactersSelected = this.selectedCharacters.length > 0;
        const backgroundSelected = this.selectedBackground !== null;

        this.updateStatusIndicators(styleSelected, charactersSelected, backgroundSelected);
        this.updateNextButton(styleSelected && charactersSelected && backgroundSelected);
        UIManager.updatePreview(this);
    },

    updateStatusIndicators(styleSelected, charactersSelected, backgroundSelected) {
        this.updateStatusIndicator('#styleStatus', styleSelected);
        this.updateStatusIndicator('#charactersStatus', charactersSelected);
        this.updateStatusIndicator('#backgroundStatus', backgroundSelected);
    },

    updateStatusIndicator(selector, isSelected) {
        $(selector)
            .toggleClass('text-danger', !isSelected)
            .toggleClass('text-success', isSelected)
            .html(isSelected ? '<i class="fas fa-check-circle"></i>' : '<i class="fas fa-times-circle"></i>');
    },

    updateNextButton(allSelected) {
        $('#next-step-2').prop('disabled', !allSelected);
    },

    restoreState() {
        const storedSelections = JSON.parse(sessionStorage.getItem('selectedCharacters') || '[]');
        this.selectedCharacters = storedSelections.slice();
        this.selectedCharacters.forEach(charId => {
            $(`.character-option[data-character-id="${charId}"]`).addClass('selected');
        });
        this.updateSelectedCharactersList();
        this.checkSelections();
    },

    capitalizeFirstLetter(string) {
        return string ? string.charAt(0).toUpperCase() + string.slice(1) : '';
    },

    updateFormState() {
        const formState = {
            story: $('#story-input').val(),
            style: sessionStorage.getItem('selectedStyle'),
            characters: JSON.parse(sessionStorage.getItem('characterData') || '{}'),
            background: sessionStorage.getItem('selectedBackground')
        };

        // Dispatch form state change event
        const event = new CustomEvent('formStateChanged', {
            detail: formState
        });
        document.dispatchEvent(event);
    },

    // Add event handler for character removal
    initializeEventHandlers() {
        // Handle character removal
        $(document).on('click', '.remove-character', (e) => {
            e.preventDefault();
            const characterId = $(e.currentTarget).data('character-id');
            if (confirm('Are you sure you want to remove this character?')) {
                this.removeCharacter(characterId);
            }
        });
    },

    updateLivePreview() {
        const $previewPane = $('#comicPreview');

        // Clear current preview
        $previewPane.empty();

        // Show placeholder if no selections made
        if (!this.selectedStyle && this.selectedCharacters.length === 0 && !this.selectedBackground) {
            $previewPane.html(`
                <div class="preview-placeholder">
                    <i class="fas fa-image fa-3x mb-3 text-muted"></i>
                    <p class="text-muted">Select style, characters, and background to see preview</p>
                </div>
            `);
            return;
        }

        // Create preview container
        const $previewContainer = $('<div class="preview-container"></div>');

        // Add style indicator if selected
        if (this.selectedStyle) {
            $previewContainer.append(`
                <div class="preview-style mb-3">
                    <small class="text-muted d-block mb-2">Selected Style: ${this.capitalizeFirstLetter(this.selectedStyle)}</small>
                </div>
            `);
        }

        // Add background if selected
        if (this.selectedBackground) {
            $previewContainer.append(`
                <div class="preview-background mb-3">
                    <small class="text-muted d-block mb-2">Selected Background: ${this.capitalizeFirstLetter(this.selectedBackground)}</small>
                </div>
            `);
        }

        // Add characters if selected
        if (this.selectedCharacters.length > 0) {
            const $charactersContainer = $('<div class="preview-characters"></div>');
            $charactersContainer.append('<small class="text-muted d-block mb-2">Selected Characters:</small>');

            const $characterGrid = $('<div class="row g-2"></div>');
            this.selectedCharacters.forEach(charId => {
                const char = this.findCharacter(charId);
                if (char) {
                    $characterGrid.append(`
                        <div class="col-6">
                            <div class="preview-character p-2 border rounded">
                                <img src="${char.image}" 
                                     class="img-fluid rounded mb-2" 
                                     alt="${char.name}"
                                     style="max-height: 150px; width: 100%; object-fit: cover;">
                                <div class="text-center small">${char.name}</div>
                            </div>
                        </div>
                    `);
                }
            });
            $charactersContainer.append($characterGrid);
            $previewContainer.append($charactersContainer);
        }

        // Add the preview container to the preview pane
        $previewPane.append($previewContainer);

        // Update selection status indicators
        this.updateSelectionStatus();
    },

    updateSelectionStatus() {
        // Update style status
        const hasStyle = !!this.selectedStyle;
        $('#styleStatus')
            .toggleClass('text-danger', !hasStyle)
            .toggleClass('text-success', hasStyle)
            .html(hasStyle ? '<i class="fas fa-check-circle"></i>' : '<i class="fas fa-times-circle"></i>');

        // Update characters status
        const hasCharacters = this.selectedCharacters.length > 0;
        $('#charactersStatus')
            .toggleClass('text-danger', !hasCharacters)
            .toggleClass('text-success', hasCharacters)
            .html(hasCharacters ? '<i class="fas fa-check-circle"></i>' : '<i class="fas fa-times-circle"></i>');

        // Update background status
        const hasBackground = !!this.selectedBackground;
        $('#backgroundStatus')
            .toggleClass('text-danger', !hasBackground)
            .toggleClass('text-success', hasBackground)
            .html(hasBackground ? '<i class="fas fa-check-circle"></i>' : '<i class="fas fa-times-circle"></i>');

        // Enable/disable next button based on all selections
        const allSelected = hasStyle && hasCharacters && hasBackground;
        $('#next-step-2').prop('disabled', !allSelected);
    },

    handleCharacterUpload() {
        const name = $('#characterName').val().trim();
        const image = this.currentUploadedImage;

        // Validate both name and image
        const validationErrors = this.validateCharacterInput(name, image);
        if (validationErrors.length > 0) {
            this.showErrorMessage(validationErrors.join('<br>'));
            return;
        }

        // Create custom character with consistent ID format
        const customCharId = `custom_${Date.now()}`;
        const customChar = {
            id: customCharId,
            name: name,
            image: image,
            isCustom: true,
            createdAt: new Date().toISOString()
        };

        try {
            // Store character data
            this.storeCharacterData(customChar);

            // Update UI
            this.addCharacterToGrid(customChar);
            this.addCharacterToTagsList(customChar);
            this.resetUploadForm();

            // Show success message
            this.showSuccessMessage(`Character "${name}" has been added successfully.`);
        } catch (error) {
            console.error('Error uploading character:', error);
            this.showErrorMessage('Failed to upload character. Please try again.');
        }
    },

    validateCharacterInput(name, image) {
        const errors = [];

        if (!name) {
            errors.push('Please provide a character name.');
        } else if (name.length > 50) {
            errors.push('Character name must be less than 50 characters.');
        }

        if (!image) {
            errors.push('Please provide a character image.');
        }

        return errors;
    },

    storeCharacterData(character) {
        try {
            const characterData = JSON.parse(sessionStorage.getItem('characterData') || '{}');
            characterData[character.id] = character;
            sessionStorage.setItem('characterData', JSON.stringify(characterData));

            // Backup to localStorage
            try {
                localStorage.setItem('characterData_backup', JSON.stringify(characterData));
            } catch (e) {
                console.warn('Failed to backup character data:', e);
            }
        } catch (error) {
            console.error('Failed to store character data:', error);
            throw new Error('Failed to save character data');
        }
    },

    addCharacterToGrid(character) {
        const $characterGrid = $('#characterGrid');
        if ($characterGrid.find('.text-muted').length) {
            $characterGrid.empty(); // Remove empty state message
        }

        $characterGrid.append(`
            <div class="col-4 col-md-2">
                <div class="character-option p-2" data-character-id="${character.id}">
                    <img src="${character.image}" class="img-fluid rounded" alt="${character.name}">
                    <div class="text-center mt-2">${character.name}</div>
                    <div class="selected-overlay">
                        <i class="fas fa-check-circle"></i>
                    </div>
                </div>
            </div>
        `);
    },

    addCharacterToTagsList(character) {
        const $customCharactersList = $('#customCharactersList');
        if ($customCharactersList.find('.text-muted').length) {
            $customCharactersList.empty(); // Remove empty state message
        }

        $customCharactersList.append(`
            <div class="custom-character-tag d-inline-flex align-items-center bg-primary text-white rounded-pill px-3 py-2 me-2 mb-2" data-character-id="${character.id}">
                <span class="me-2">${character.name}</span>
                <span class="remove-character" data-character-id="${character.id}" style="cursor: pointer;">
                    <i class="fas fa-times"></i>
                </span>
            </div>
        `);
    },

    resetUploadForm() {
        $('#characterName').val('');
        $('#characterUpload').val('');
        this.currentUploadedImage = null;
        this.resetCharacterPreview();
    },

    initializeFileUpload() {
        // Handle file selection
        $('#characterUpload').on('change', (e) => {
            const file = e.target.files[0];
            if (file) {
                // Validate file
                if (!['image/jpeg', 'image/png', 'image/jpg'].includes(file.type)) {
                    this.showErrorMessage('Please upload only PNG or JPEG images.');
                    e.target.value = '';
                    return;
                }
                if (file.size > 5 * 1024 * 1024) { // 5MB
                    this.showErrorMessage('File size should not exceed 5MB.');
                    e.target.value = '';
                    return;
                }

                const reader = new FileReader();
                reader.onload = (e) => {
                    const img = new Image();
                    img.onload = () => {
                        const canvas = document.createElement('canvas');
                        const ctx = canvas.getContext('2d');
                        canvas.width = 512;  // Standard size for AI models
                        canvas.height = 512; // Standard size for AI models

                        // Calculate scaling and positioning for center crop
                        const scale = Math.max(canvas.width / img.width, canvas.height / img.height);
                        const x = (canvas.width - img.width * scale) / 2;
                        const y = (canvas.height - img.height * scale) / 2;

                        // Fill with white background
                        ctx.fillStyle = '#FFFFFF';
                        ctx.fillRect(0, 0, canvas.width, canvas.height);

                        // Draw image centered and scaled
                        ctx.drawImage(img, x, y, img.width * scale, img.height * scale);

                        // Get base64 with proper MIME type prefix
                        const resizedImage = canvas.toDataURL('image/png');

                        // Update preview
                        $('#characterPreview').attr('src', resizedImage);
                        $('.preview-name').text(file.name);
                        this.currentUploadedImage = resizedImage;
                    };
                    img.src = e.target.result;
                };
                reader.readAsDataURL(file);
            } else {
                this.resetCharacterPreview();
            }
        });
    },

    findCharacter(charId) {
        // First check in session storage
        const characterData = JSON.parse(sessionStorage.getItem('characterData') || '{}');
        if (characterData[charId]) {
            return characterData[charId];
        }

        // Then check in default characters
        return this.characters.find(c => c.id === charId);
    },

    checkGenerationResult(predictionId) {
        console.log('Checking result for prediction:', predictionId);

        // First check after 30 seconds
        setTimeout(() => {
            this.doResultCheck(predictionId);
        }, 30000);
    },

    doResultCheck(predictionId) {
        $.ajax({
            url: `public/temp/${predictionId}.json`,
            type: 'GET',
            success: (result) => {
                console.log('Result check response:', result);
                if (result.status === 'succeeded' && result.output) {
                    this.displayGeneratedComic(result);
                } else if (result.status === 'failed') {
                    UIManager.showError(result.error || 'Generation failed');
                } else {
                    // If still processing, try one more time after 15 seconds
                    setTimeout(() => {
                        this.doResultCheck(predictionId);
                    }, 15000);
                }
            },
            error: (xhr) => {
                console.error('Error checking result:', xhr);
                UIManager.showError('Error checking generation status');
            }
        });
    },

    displayGeneratedComic(result) {
        UIManager.hideGeneratingState();

        if (result && result.output) {
            // Validate and sanitize URL
            const comicUrl = result.output;
            const isAbsoluteUrl = comicUrl.startsWith('http://') || comicUrl.startsWith('https://');
            const sanitizedUrl = isAbsoluteUrl ? comicUrl : window.location.origin + comicUrl;

            // Display the comic
            $('.comic-preview').html(
                `<img src="${sanitizedUrl}" class="img-fluid mb-4" alt="Generated Comic">`
            );

            // Enable action buttons
            $('.action-buttons button').prop('disabled', false);

            // Show completion status
            UIManager.showCompletionState();
        } else {
            UIManager.showError('Comic generation completed but no output URL found');
        }
    },

    validateStep1() {
        const story = $('#story-input').val().trim();
        if (story.length >= this.minChars && story.length <= this.maxChars) {
            // Store story in session storage
            sessionStorage.setItem('userStory', story);
            return true;
        }
        return false;
    },

    validateStep2() {
        const hasStyle = this.selectedStyle !== null;
        const hasCharacters = this.selectedCharacters.length > 0;
        const hasBackground = this.selectedBackground !== null;

        if (hasStyle && hasCharacters && hasBackground) {
            // Store selections
            sessionStorage.setItem('selectedStyle', this.selectedStyle);
            sessionStorage.setItem('selectedCharacters', JSON.stringify(this.selectedCharacters));
            sessionStorage.setItem('selectedBackground', this.selectedBackground);
            this.updateReviewSection();
            return true;
        }
        return false;
    },

    validateStep3() {
        // Add any step 3 validation logic here
        return true;
    },

    async generateComic() {
        try {
            const response = await fetch('/api.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    story: this.story,
                    characters: this.selectedCharacters,
                    style: this.selectedStyle,
                    background: this.selectedBackground
                })
            });

            // Check for Cloudflare error page
            const contentType = response.headers.get('content-type');
            if (contentType && contentType.includes('text/html')) {
                throw new Error('Server is temporarily unavailable. Please try again in a few moments.');
            }

            const result = await response.json();
            if (result.success && result.data.id) {
                // Start polling for updates
                this.comicGenerator.startPolling(result.data.id);
            } else {
                throw new Error(result.error || 'Failed to start comic generation');
            }
        } catch (error) {
            console.error('Comic generation error:', error);
            this.handleError(error.message);
        }
    }
};

// Export the FormHandler object
export { FormHandler }; 