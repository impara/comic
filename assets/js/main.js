import storyExamples from './story-examples.js';
import { UIManager } from './ui-manager.js';
import { FormHandler } from './form-handler.js?v=1.0.2';
import { ComicGenerator } from './comic-generator.js';

$(document).ready(function () {
    // Initialize UI manager first
    UIManager.init();

    // Initialize form handler
    FormHandler.init();
    FormHandler.initializeCharacterGrid();
    FormHandler.initializeEventHandlers();
    FormHandler.updateFormState();

    // Initialize comic generator
    ComicGenerator.init();

    // Initialize example prompts
    initializeExamplePrompts();

    // Set up step change listener
    document.addEventListener('changeStep', (event) => {
        if (event.detail && typeof event.detail.step === 'number') {
            UIManager.goToStep(event.detail.step);
        }
    });
});

function initializeExamplePrompts() {
    const examplePromptsList = document.getElementById('examplePromptsList');
    if (!examplePromptsList) return;

    storyExamples.forEach(example => {
        const li = document.createElement('li');
        li.className = 'mb-3';

        li.innerHTML = `
            <div class="example-prompt p-3 rounded bg-white shadow-sm cursor-pointer">
                <h6 class="mb-2">${example.title}</h6>
                <p class="mb-0">${example.text}</p>
            </div>
        `;

        // Add click handler to populate the story input
        li.addEventListener('click', () => {
            const $storyInput = $('#story-input');
            $storyInput.val(example.text);
            // Trigger input event to update character count
            $storyInput[0].dispatchEvent(new Event('input'));
            // Collapse the examples panel
            const examplesCollapse = bootstrap.Collapse.getInstance(document.getElementById('examplePrompts'));
            if (examplesCollapse) {
                examplesCollapse.hide();
            }
            // Scroll to textarea
            $('html, body').animate({
                scrollTop: $storyInput.offset().top - 100
            }, 500);
        });

        examplePromptsList.appendChild(li);
    });
}