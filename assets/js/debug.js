// Debug Information Management
document.addEventListener('DOMContentLoaded', function () {
    // Set page load time
    document.getElementById('pageLoadTime').textContent = new Date().toLocaleTimeString();

    // Update session storage content
    function updateDebugInfo() {
        try {
            // Session Storage
            const sessionData = {};
            for (let i = 0; i < sessionStorage.length; i++) {
                const key = sessionStorage.key(i);
                sessionData[key] = sessionStorage.getItem(key);
            }
            const sessionStorageElement = document.getElementById('sessionStorageContent');
            if (sessionStorageElement) {
                sessionStorageElement.textContent = JSON.stringify(sessionData, null, 2);
            }

            // Form State
            const formState = {
                story: document.getElementById('story-input')?.value,
                currentStep: document.querySelector('.step.active')?.dataset.step
            };
            const formStateElement = document.getElementById('formStateContent');
            if (formStateElement) {
                formStateElement.textContent = JSON.stringify(formState, null, 2);
            }
        } catch (error) {
            console.error('Error updating debug info:', error);
        }
    }

    // Update debug info every 2 seconds
    setInterval(updateDebugInfo, 2000);

    // Log when pay button is clicked
    document.getElementById('payButton')?.addEventListener('click', function () {
        document.getElementById('lastAction').textContent =
            'Pay button clicked at ' + new Date().toLocaleTimeString();
        console.log('Pay button clicked (from debug script)');
    });
}); 