// Environment detection
const ENV = {
    isDev: window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1',
    isProd: window.location.hostname === 'comic.amertech.online',
    isHttps: window.location.protocol === 'https:',
    browserSupportsModules: 'noModule' in HTMLScriptElement.prototype
};

// Configuration object
export const CONFIG = {
    VERSION: '1.0.2',
    ENV,
    PATHS: {
        FORM_HANDLER: './form-handler.js',
        UI_MANAGER: './ui-manager.js',
        COMIC_GENERATOR: './comic-generator.js',
        STORY_EXAMPLES: './story-examples.js',
        SHARING: './sharing.js'
    },
    TIMEOUTS: {
        ALERT_DISMISS: 5000,
        API_CALL: 30000
    },
    LIMITS: {
        MAX_RETRIES: 3,
        MAX_FILE_SIZE: 5 * 1024 * 1024, // 5MB
        MIN_CHARS: 50,
        MAX_CHARS: 500
    },
    getVersionedPath(path) {
        if (!path) {
            throw new Error('Path is required for versioning');
        }

        try {
            // Development: use timestamp for cache busting
            if (this.ENV.isDev) {
                return `${path}?v=${Date.now()}`;
            }

            // Production: use version number
            return `${path}?v=${this.VERSION}`;
        } catch (error) {
            console.error('Error in getVersionedPath:', error);
            // Fallback to unversioned path
            return path;
        }
    },
    validateEnvironment() {
        const warnings = [];

        // Check HTTPS
        if (!this.ENV.isDev && !this.ENV.isHttps) {
            warnings.push('Application is running without HTTPS');
        }

        // Check module support
        if (!this.ENV.browserSupportsModules) {
            warnings.push('Browser does not support ES modules');
        }

        // Log warnings if any
        if (warnings.length > 0) {
            console.warn('Environment warnings:', warnings);
        }

        return warnings.length === 0;
    }
};

// Freeze the config to prevent modifications
Object.freeze(CONFIG);
Object.freeze(CONFIG.PATHS);
Object.freeze(CONFIG.TIMEOUTS);
Object.freeze(CONFIG.LIMITS);
Object.freeze(CONFIG.ENV);

// Initial environment validation
CONFIG.validateEnvironment();