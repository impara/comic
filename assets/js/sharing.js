import { CONFIG } from './config.js';
import { FormHandler } from './form-handler.js';

export class SharingManager {
    static async init() {
        try {
            await this.bindEvents();
            console.log(`SharingManager initialized (v${CONFIG.VERSION})`);
        } catch (error) {
            console.error('Failed to initialize SharingManager:', error);
            this.handleError(error);
        }
    }

    static async bindEvents() {
        try {
            const actionButtons = document.querySelector('.action-buttons');
            if (!actionButtons) {
                throw new Error('Action buttons container not found');
            }

            const buttons = {
                download: actionButtons.querySelector('.btn-primary'),
                share: actionButtons.querySelector('.btn-success'),
                createAnother: actionButtons.querySelector('.btn-outline-primary')
            };

            // Check if all buttons exist
            Object.entries(buttons).forEach(([name, button]) => {
                if (!button) {
                    throw new Error(`${name} button not found`);
                }
            });

            // Bind events with error handling
            buttons.download?.addEventListener('click', async (e) => {
                try {
                    await this.handleDownload();
                } catch (error) {
                    this.handleError(error, 'Failed to download comic');
                }
            });

            buttons.share?.addEventListener('click', async (e) => {
                try {
                    await this.handleShare();
                } catch (error) {
                    this.handleError(error, 'Failed to share comic');
                }
            });

            buttons.createAnother?.addEventListener('click', async (e) => {
                try {
                    await this.handleCreateAnother();
                } catch (error) {
                    this.handleError(error, 'Failed to create new comic');
                }
            });
        } catch (error) {
            throw new Error(`Failed to bind events: ${error.message}`);
        }
    }

    static handleError(error, userMessage = 'An error occurred') {
        console.error('SharingManager error:', {
            message: error.message,
            stack: error.stack,
            timestamp: new Date().toISOString()
        });

        // Show user-friendly error message
        const errorContainer = document.createElement('div');
        errorContainer.className = 'alert alert-danger alert-dismissible fade show';
        errorContainer.innerHTML = `
            ${userMessage}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;

        // Insert error message before the action buttons
        const actionButtons = document.querySelector('.action-buttons');
        if (actionButtons?.parentNode) {
            actionButtons.parentNode.insertBefore(errorContainer, actionButtons);
        }

        // Auto-dismiss after 5 seconds
        setTimeout(() => {
            const alert = bootstrap.Alert.getOrCreateInstance(errorContainer);
            alert?.close();
        }, 5000);
    }

    static handleDownload() {
        const comicImg = document.querySelector('.comic-preview img');
        const comicUrl = comicImg?.src;
        if (comicUrl) {
            const link = document.createElement('a');
            link.href = comicUrl;
            link.download = 'my-comic.png';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
    }

    static handleShare() {
        const comicImg = document.querySelector('.comic-preview img');
        const comicUrl = comicImg?.src;
        if (comicUrl) {
            const shareDialog = this.createShareDialog();
            this.setupShareHandlers(shareDialog, comicUrl);
            this.showShareDialog(shareDialog);
        }
    }

    static createShareDialog() {
        const dialog = document.createElement('div');
        dialog.className = 'share-dialog modal fade';
        dialog.setAttribute('tabindex', '-1');
        dialog.innerHTML = `
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
        `;
        return dialog;
    }

    static setupShareHandlers(shareDialog, comicUrl) {
        shareDialog.querySelector('.share-facebook')?.addEventListener('click', () => this.openShareWindow('facebook', comicUrl));
        shareDialog.querySelector('.share-twitter')?.addEventListener('click', () => this.openShareWindow('twitter', comicUrl));
        shareDialog.querySelector('.share-pinterest')?.addEventListener('click', () => this.openShareWindow('pinterest', comicUrl));
    }

    static showShareDialog(shareDialog) {
        document.body.appendChild(shareDialog);
        const modal = new bootstrap.Modal(shareDialog);
        modal.show();

        shareDialog.addEventListener('hidden.bs.modal', () => {
            shareDialog.remove();
        });
    }

    static openShareWindow(platform, comicUrl) {
        const shareUrls = {
            facebook: `https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(comicUrl)}`,
            twitter: `https://twitter.com/intent/tweet?url=${encodeURIComponent(comicUrl)}&text=${encodeURIComponent('Check out my AI-generated comic!')}`,
            pinterest: `https://pinterest.com/pin/create/button/?url=${encodeURIComponent(window.location.href)}&media=${encodeURIComponent(comicUrl)}&description=${encodeURIComponent('My AI-generated comic')}`
        };

        window.open(shareUrls[platform], '_blank');
    }

    static handleCreateAnother() {
        this.clearStoredData();
        this.resetForm();
        this.resetToStep1();
    }

    static clearStoredData() {
        sessionStorage.removeItem('userStory');
        sessionStorage.removeItem('selectedStyle');
        sessionStorage.removeItem('selectedCharacters');
        sessionStorage.removeItem('selectedBackground');
    }

    static resetForm() {
        const form = document.getElementById('comicForm');
        if (form) {
            form.reset();
            document.querySelectorAll('.style-option, .character-option, .background-option').forEach(el => el.classList.remove('selected'));
            FormHandler.selectedStyle = null;
            FormHandler.selectedCharacters = [];
            FormHandler.selectedBackground = null;
            FormHandler.updateSelectedCharactersList();
            FormHandler.checkSelections();
        }
    }

    static resetToStep1() {
        document.querySelectorAll('.step').forEach(el => el.classList.remove('active'));
        document.querySelector('.step[data-step="1"]')?.classList.add('active');
        document.querySelectorAll('.step-content').forEach(el => el.classList.remove('active'));
        document.getElementById('step1')?.classList.add('active');
        document.querySelector('.progress-bar')?.style.setProperty('width', '25%');

        const wizardContainer = document.querySelector('.wizard-container');
        if (wizardContainer) {
            wizardContainer.scrollIntoView({ behavior: 'smooth', block: 'start', inline: 'nearest' });
        }
    }
} 