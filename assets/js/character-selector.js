class CharacterSelector {
    handleCharacterSelection(e) {
        const characterOption = $(e.currentTarget);
        const characterId = characterOption.data('character-id');
        const isSelected = characterOption.hasClass('selected');

        // Toggle selection
        if (isSelected) {
            characterOption.removeClass('selected');
            this.selectedCharacters = this.selectedCharacters.filter(id => id !== characterId);
        } else {
            // Check if we've reached the maximum
            if (this.selectedCharacters.length >= this.maxCharacters) {
                alert(`You can only select up to ${this.maxCharacters} characters.`);
                return;
            }
            characterOption.addClass('selected');
            this.selectedCharacters.push(characterId);
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
    }
}