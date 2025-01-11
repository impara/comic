// Debug Information Management
document.addEventListener('DOMContentLoaded', function () {
    let isDebugActive = false;
    let debugInterval = null;

    // Initialize debug functionality
    function initializeDebug() {
        const debugSection = document.getElementById('debugInfo');
        if (!debugSection) {
            cleanupDebug();
            return false;
        }

        if (!isDebugActive) {
            // Set page load time only once when initializing
            const pageLoadTimeElement = document.getElementById('pageLoadTime');
            if (pageLoadTimeElement) {
                pageLoadTimeElement.textContent = new Date().toLocaleTimeString();
            }

            // Set up pay button listener
            const payButton = document.getElementById('payButton');
            const lastActionElement = document.getElementById('lastAction');
            if (payButton && lastActionElement) {
                payButton.addEventListener('click', function () {
                    lastActionElement.textContent = 'Pay button clicked at ' + new Date().toLocaleTimeString();
                    console.log('Pay button clicked (from debug script)');
                });
            }

            isDebugActive = true;
        }

        return true;
    }

    // Clean up debug resources
    function cleanupDebug() {
        if (debugInterval) {
            clearInterval(debugInterval);
            debugInterval = null;
        }
        isDebugActive = false;
    }

    // Update debug information
    function updateDebugInfo() {
        // Check if debug section still exists
        if (!initializeDebug()) {
            return;
        }

        try {
            // Session Storage
            const sessionStorageElement = document.getElementById('sessionStorageContent');
            if (sessionStorageElement) {
                const sessionData = {};
                try {
                    for (let i = 0; i < sessionStorage.length; i++) {
                        const key = sessionStorage.key(i);
                        if (key) {
                            sessionData[key] = sessionStorage.getItem(key);
                        }
                    }
                    sessionStorageElement.textContent = JSON.stringify(sessionData, null, 2);
                } catch (storageError) {
                    console.warn('Error accessing session storage:', storageError);
                    sessionStorageElement.textContent = '{}';
                }
            }

            // Form State
            const formStateElement = document.getElementById('formStateContent');
            if (formStateElement) {
                const formState = {
                    story: document.getElementById('story-input')?.value || '',
                    currentStep: document.querySelector('.step.active')?.dataset.step || '1'
                };
                formStateElement.textContent = JSON.stringify(formState, null, 2);
            }
        } catch (error) {
            console.error('Error updating debug info:', error);
            cleanupDebug();
        }
    }

    // Start debug monitoring if debug section exists
    if (initializeDebug()) {
        debugInterval = setInterval(updateDebugInfo, 2000);

        // Set up observer to handle debug section removal
        const observer = new MutationObserver(function (mutations) {
            if (!document.getElementById('debugInfo')) {
                cleanupDebug();
                observer.disconnect();
            }
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
    }
}); 