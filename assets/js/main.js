// Module error handling
window.addEventListener('error', (event) => {
    if (event.error instanceof TypeError && event.error.message.includes('does not provide an export')) {
        console.error('Module loading error:', {
            file: event.filename,
            line: event.lineno,
            message: event.error.message,
            stack: event.error.stack
        });

        // Show user-friendly error
        const errorContainer = document.getElementById('error-container');
        if (errorContainer) {
            errorContainer.innerHTML = `
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <strong>Loading Error:</strong> Failed to load required modules. Please try clearing your browser cache and refreshing the page.
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            `;
        }
    }
});

import { CONFIG } from './config.js';
import { storyExamples } from './story-examples.js';
import { UIManager } from './ui-manager.js';
import { FormHandler } from './form-handler.js';
import { ComicGenerator } from './comic-generator.js';
import { SharingManager } from './sharing.js';

// Add version info to loaded modules
const loadedModules = {
    storyExamples,
    UIManager,
    FormHandler,
    ComicGenerator,
    SharingManager
};

// Attach version to each module
Object.entries(loadedModules).forEach(([name, module]) => {
    if (module) {
        module.version = CONFIG.VERSION;
    }
});

class App {
    static async init() {
        try {
            // Wait for DOM to be ready
            await this.domReady();

            // Log loaded modules versions
            console.log('Loaded modules:', Object.entries(loadedModules)
                .map(([name, module]) => `${name}@${module?.version || 'unknown'}`));

            // Initialize in specific order
            await UIManager.init();
            await FormHandler.init();
            await FormHandler.initializeCharacterGrid();
            await FormHandler.initializeEventHandlers();
            await FormHandler.updateFormState();
            await ComicGenerator.init();
            await SharingManager.init();

            // Initialize examples after core functionality
            this.initializeExamplePrompts();

            // Set up global event listeners
            this.setupEventListeners();

            console.log(`App initialized successfully (v${CONFIG.VERSION})`);
        } catch (error) {
            console.error('Error initializing app:', error);
            // Add error reporting
            this.reportError(error);
        }
    }

    static reportError(error) {
        // Log to console with stack trace
        console.error('Detailed error:', {
            message: error.message,
            stack: error.stack,
            timestamp: new Date().toISOString()
        });

        // Show user-friendly error message
        const errorContainer = document.getElementById('error-container');
        if (errorContainer) {
            errorContainer.innerHTML = `
                <div class="alert alert-danger">
                    An error occurred while loading the application. 
                    Please refresh the page or contact support if the problem persists.
                </div>
            `;
        }
    }

    static domReady() {
        return new Promise(resolve => {
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', resolve);
            } else {
                resolve();
            }
        });
    }

    static setupEventListeners() {
        document.addEventListener('changeStep', (event) => {
            if (event.detail && typeof event.detail.step === 'number') {
                UIManager.goToStep(event.detail.step);
            }
        });
    }

    static initializeExamplePrompts() {
        const examplePromptsList = document.getElementById('examplePromptsList');
        if (!examplePromptsList) return;

        // Check if storyExamples is an array
        if (!Array.isArray(storyExamples)) {
            console.error('storyExamples is not an array:', storyExamples);
            return;
        }

        storyExamples.forEach(example => {
            const li = document.createElement('li');
            li.className = 'mb-3';

            li.innerHTML = `
                <div class="example-prompt p-3 rounded bg-white shadow-sm cursor-pointer">
                    <h6 class="mb-2">${example.title}</h6>
                    <p class="mb-0">${example.text}</p>
                </div>
            `;

            li.addEventListener('click', () => {
                const storyInput = document.getElementById('story-input');
                if (storyInput) {
                    storyInput.value = example.text;
                    storyInput.dispatchEvent(new Event('input'));

                    const examplesCollapse = bootstrap.Collapse.getInstance(document.getElementById('examplePrompts'));
                    if (examplesCollapse) {
                        examplesCollapse.hide();
                    }

                    storyInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            });

            examplePromptsList.appendChild(li);
        });
    }
}

// Initialize the app
App.init();