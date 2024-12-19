// Debug Information Management
document.addEventListener('DOMContentLoaded', function () {
    // Only initialize debug functionality if debug elements exist
    const debugSection = document.getElementById('debugInfo');
    if (!debugSection) return;

    // Set page load time
    const pageLoadTimeElement = document.getElementById('pageLoadTime');
    if (pageLoadTimeElement) {
        pageLoadTimeElement.textContent = new Date().toLocaleTimeString();
    }

    // Update session storage content
    function updateDebugInfo() {
        try {
            // Session Storage
            const sessionStorageElement = document.getElementById('sessionStorageContent');
            if (sessionStorageElement) {
                const sessionData = {};
                for (let i = 0; i < sessionStorage.length; i++) {
                    const key = sessionStorage.key(i);
                    sessionData[key] = sessionStorage.getItem(key);
                }
                sessionStorageElement.textContent = JSON.stringify(sessionData, null, 2);
            }

            // Form State
            const formStateElement = document.getElementById('formStateContent');
            if (formStateElement) {
                const formState = {
                    story: document.getElementById('story-input')?.value,
                    currentStep: document.querySelector('.step.active')?.dataset.step
                };
                formStateElement.textContent = JSON.stringify(formState, null, 2);
            }
        } catch (error) {
            console.error('Error updating debug info:', error);
        }
    }

    // Update debug info every 2 seconds
    const debugInterval = setInterval(updateDebugInfo, 2000);

    // Log when pay button is clicked
    const payButton = document.getElementById('payButton');
    const lastActionElement = document.getElementById('lastAction');

    if (payButton && lastActionElement) {
        payButton.addEventListener('click', function () {
            lastActionElement.textContent = 'Pay button clicked at ' + new Date().toLocaleTimeString();
            console.log('Pay button clicked (from debug script)');
        });
    }

    // Clean up interval if debug section is removed
    const observer = new MutationObserver(function (mutations) {
        if (!document.getElementById('debugInfo')) {
            clearInterval(debugInterval);
            observer.disconnect();
        }
    });

    observer.observe(document.body, {
        childList: true,
        subtree: true
    });
}); 