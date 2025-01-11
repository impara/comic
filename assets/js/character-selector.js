class CharacterSelector {
    constructor(maxCharacters = 2) {
        this.maxCharacters = maxCharacters;
        this.selectedCharacters = JSON.parse(sessionStorage.getItem('selectedCharacters') || '[]');
        this.initializeEventListeners();
        this.updateCharacterCount();
    }

    initializeEventListeners() {
        $('.character-option').on('click', (e) => this.handleCharacterSelection(e));
    }

    handleCharacterSelection(e) {
        const characterOption = $(e.currentTarget);
        const characterId = characterOption.data('character-id');
        const isSelected = characterOption.hasClass('selected');

        console.log('Character selection attempted:', {
            characterId,
            isSelected,
            currentSelected: this.selectedCharacters
        });

        try {
            if (isSelected) {
                characterOption.removeClass('selected');
                this.selectedCharacters = this.selectedCharacters.filter(id => id !== characterId);
                console.log('Character deselected:', {
                    characterId,
                    remainingSelected: this.selectedCharacters
                });
            } else {
                // Check if we've reached the maximum
                if (this.selectedCharacters.length >= this.maxCharacters) {
                    alert(`You can only select up to ${this.maxCharacters} characters.`);
                    return;
                }

                characterOption.addClass('selected');
                this.selectedCharacters.push(characterId);
                console.log('Character selected:', {
                    characterId,
                    allSelected: this.selectedCharacters
                });
            }

            // Clean up character data in session storage
            const characterData = JSON.parse(sessionStorage.getItem('characterData') || '{}');
            const cleanedData = {};

            // Only keep selected characters
            this.selectedCharacters.forEach(id => {
                if (characterData[id]) {
                    cleanedData[id] = characterData[id];
                }
            });

            // Update session storage
            sessionStorage.setItem('selectedCharacters', JSON.stringify(this.selectedCharacters));
            sessionStorage.setItem('characterData', JSON.stringify(cleanedData));

            // Update UI
            this.updateCharacterCount();
            this.updateCharacterUI(characterId, !isSelected);

            // Dispatch event for form handler
            document.dispatchEvent(new CustomEvent('characterSelectionChanged', {
                detail: {
                    selectedCharacters: this.selectedCharacters,
                    characterData: cleanedData
                }
            }));

        } catch (error) {
            console.error('Error handling character selection:', error);
            alert('An error occurred while selecting the character. Please try again.');
        }
    }

    updateCharacterUI(characterId, isSelected) {
        const $characterOption = $(`.character-option[data-character-id="${characterId}"]`);

        if (isSelected) {
            $characterOption.addClass('selected');
        } else {
            $characterOption.removeClass('selected');
        }

        // Update custom character tag if it exists
        const $customCharacterTag = $(`.custom-character-tag[data-character-id="${characterId}"]`);
        if ($customCharacterTag.length) {
            $customCharacterTag.toggleClass('selected', isSelected);
        }
    }

    updateCharacterCount() {
        const count = this.selectedCharacters.length;
        $('#selected-character-count').text(count);
        $('#max-character-count').text(this.maxCharacters);

        // Update next button state
        const nextButton = $('#next-step-1');
        if (count > 0 && count <= this.maxCharacters) {
            nextButton.prop('disabled', false);
        } else {
            nextButton.prop('disabled', true);
        }
    }

    getSelectedCharacters() {
        return this.selectedCharacters;
    }
}

// Export the class
export { CharacterSelector };