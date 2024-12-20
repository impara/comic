import { FormHandler } from './form-handler.js?v=1.0.2';

export const SharingManager = {
    init() {
        this.bindEvents();
    },

    bindEvents() {
        // Download button
        $('.action-buttons .btn-primary').on('click', () => this.handleDownload());

        // Share button
        $('.action-buttons .btn-success').on('click', () => this.handleShare());

        // Create another comic button
        $('.action-buttons .btn-outline-primary').on('click', () => this.handleCreateAnother());
    },

    handleDownload() {
        const comicUrl = $('.comic-preview img').attr('src');
        if (comicUrl) {
            const link = document.createElement('a');
            link.href = comicUrl;
            link.download = 'my-comic.png';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
    },

    handleShare() {
        const comicUrl = $('.comic-preview img').attr('src');
        if (comicUrl) {
            const shareDialog = this.createShareDialog();
            this.setupShareHandlers(shareDialog, comicUrl);
            this.showShareDialog(shareDialog);
        }
    },

    createShareDialog() {
        return $(`
            <div class="share-dialog modal fade" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Share Your Comic</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="d-flex justify-content-center gap-3">
                                <button class="btn btn-primary share-facebook">
                                    <i class="fab fa-facebook-f"></i> Facebook
                                </button>
                                <button class="btn btn-info share-twitter">
                                    <i class="fab fa-twitter"></i> Twitter
                                </button>
                                <button class="btn btn-danger share-pinterest">
                                    <i class="fab fa-pinterest"></i> Pinterest
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `);
    },

    setupShareHandlers(shareDialog, comicUrl) {
        shareDialog.find('.share-facebook').click(() => {
            this.openShareWindow('facebook', comicUrl);
        });

        shareDialog.find('.share-twitter').click(() => {
            this.openShareWindow('twitter', comicUrl);
        });

        shareDialog.find('.share-pinterest').click(() => {
            this.openShareWindow('pinterest', comicUrl);
        });
    },

    showShareDialog(shareDialog) {
        shareDialog.appendTo('body').modal('show');

        // Clean up on hide
        shareDialog.on('hidden.bs.modal', () => {
            shareDialog.remove();
        });
    },

    openShareWindow(platform, comicUrl) {
        const shareUrls = {
            facebook: `https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(comicUrl)}`,
            twitter: `https://twitter.com/intent/tweet?url=${encodeURIComponent(comicUrl)}&text=${encodeURIComponent('Check out my AI-generated comic!')}`,
            pinterest: `https://pinterest.com/pin/create/button/?url=${encodeURIComponent(window.location.href)}&media=${encodeURIComponent(comicUrl)}&description=${encodeURIComponent('My AI-generated comic')}`
        };

        window.open(shareUrls[platform], '_blank');
    },

    handleCreateAnother() {
        // Clear stored data
        this.clearStoredData();

        // Reset form and selections
        this.resetForm();

        // Return to step 1
        this.resetToStep1();
    },

    clearStoredData() {
        sessionStorage.removeItem('userStory');
        sessionStorage.removeItem('selectedStyle');
        sessionStorage.removeItem('selectedCharacters');
        sessionStorage.removeItem('selectedBackground');
    },

    resetForm() {
        $('#comicForm')[0].reset();
        $('.style-option, .character-option, .background-option').removeClass('selected');
        FormHandler.selectedStyle = null;
        FormHandler.selectedCharacters = [];
        FormHandler.selectedBackground = null;
        FormHandler.updateSelectedCharactersList();
        FormHandler.checkSelections();
    },

    resetToStep1() {
        $('.step').removeClass('active');
        $('.step[data-step="1"]').addClass('active');
        $('.step-content').removeClass('active');
        $('#step1').addClass('active');
        $('.progress-bar').css('width', '25%');

        // Smooth scroll to top
        $('html, body').animate({
            scrollTop: $('.wizard-container').offset().top - 50
        }, 500);
    }
}; 