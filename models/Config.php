<?php

class Config
{
    private static ?Config $instance = null;
    private array $config;
    private array $env;
    private static bool $initialized = false;
    private ?string $cachedBaseUrl = null;
    private ?string $environment = null;

    private function __construct()
    {
        if (!self::$initialized) {
            // Force environment reload
            $this->loadEnv();

            // Store environment variables
            $this->env = $_ENV;

            // Load configuration file
            $this->config = require __DIR__ . '/../config/config.php';

            // Override config with environment variables
            $this->loadConfig();

            // Debug environment loading - only log once during initialization
            error_log("DEBUG_VERIFY - Config initialized: " . json_encode([
                'env_log_level' => $this->getEnv('LOG_LEVEL'),
                'env_app_log_level' => $this->getEnv('APP_LOG_LEVEL'),
                'env_app_debug' => $this->getEnv('APP_DEBUG'),
                'config_debug' => $this->isDebugMode(),
                'config_log_level' => $this->getLogLevel()
            ]));

            self::$initialized = true;
        }
    }

    // Prevent cloning of the instance
    private function __clone() {}

    // Prevent unserializing of the instance
    public function __wakeup()
    {
        throw new Exception('Cannot unserialize singleton');
    }

    private function loadEnv(): void
    {
        $this->env = [];
        $envFile = __DIR__ . '/../.env';

        // First load environment variables from system
        $systemEnvVars = [
            'APP_ENV',
            'APP_DEBUG',
            'APP_LOG_LEVEL',
            'APP_BASE_URL',
            'REPLICATE_API_TOKEN',
            'REPLICATE_WEBHOOK_SECRET',
            'MAX_CONCURRENT_JOBS',
            'MAX_RETRIES',
            'RETRY_DELAY',
            'JOB_TIMEOUT',
            'HEARTBEAT_INTERVAL',
            'SDXL_MODEL_VERSION',
            'CARTOONIFY_MODEL',
            'NLP_MODEL'
        ];

        foreach ($systemEnvVars as $var) {
            $value = getenv($var);
            if ($value !== false) {
                $this->env[$var] = $value;
            }
        }

        // Then load from .env file, allowing it to override system vars
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos(trim($line), '#') === 0) {
                    continue;
                }

                if (strpos($line, '=') !== false) {
                    list($name, $value) = explode('=', $line, 2);
                    $name = trim($name);
                    $value = trim($value);

                    // Remove quotes if present
                    if (preg_match('/^([\'"])(.*)\1$/', $value, $matches)) {
                        $value = $matches[2];
                    }

                    $this->env[$name] = $value;
                    putenv(sprintf('%s=%s', $name, $value));
                    $_ENV[$name] = $value;
                    $_SERVER[$name] = $value;
                }
            }
        }

        // Validate required environment variables
        $requiredVars = ['APP_ENV', 'REPLICATE_API_TOKEN'];
        foreach ($requiredVars as $var) {
            if (empty($this->env[$var])) {
                throw new RuntimeException("Required environment variable {$var} is not set");
            }
        }
    }

    private function loadConfig(): void
    {
        $configFile = __DIR__ . '/../config/config.php';
        if (!file_exists($configFile)) {
            throw new RuntimeException('Configuration file not found');
        }

        // Load base configuration
        $this->config = require $configFile;

        // Override with environment variables using a more structured approach
        $this->overrideConfigWithEnv();

        // Validate critical configuration sections
        $this->validateConfig();
    }

    private function overrideConfigWithEnv(): void
    {
        // App configuration overrides
        $this->config['app']['debug'] = filter_var($this->env['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->config['app']['log_level'] = strtolower($this->env['APP_LOG_LEVEL'] ?? $this->config['app']['log_level']);
        $this->config['app']['base_url'] = $this->env['APP_BASE_URL'] ?? $this->config['app']['base_url'];

        // Replicate configuration overrides
        $this->config['replicate']['api_token'] = $this->env['REPLICATE_API_TOKEN'] ?? null;
        $this->config['replicate']['webhook_secret'] = $this->env['REPLICATE_WEBHOOK_SECRET'] ?? null;

        // Model version overrides
        if (isset($this->env['SDXL_MODEL_VERSION'])) {
            $this->config['replicate']['models']['sdxl']['version'] = $this->env['SDXL_MODEL_VERSION'];
        }
        
        if (isset($this->env['CARTOONIFY_MODEL'])) {
            $this->config['replicate']['models']['cartoonify']['version'] = $this->env['CARTOONIFY_MODEL'];
        }
        
        if (isset($this->env['NLP_MODEL'])) {
            $this->config['replicate']['models']['nlp']['version'] = $this->env['NLP_MODEL'];
        }

        // Performance configuration overrides
        $this->config['app']['max_concurrent_jobs'] = (int)($this->env['MAX_CONCURRENT_JOBS'] ?? $this->config['app']['max_concurrent_jobs']);
        $this->config['app']['max_retries'] = (int)($this->env['MAX_RETRIES'] ?? $this->config['app']['max_retries']);
        $this->config['app']['retry_delay'] = (int)($this->env['RETRY_DELAY'] ?? $this->config['app']['retry_delay']);
        $this->config['app']['job_timeout'] = (int)($this->env['JOB_TIMEOUT'] ?? $this->config['app']['job_timeout']);
    }

    private function validateConfig(): void
    {
        // Validate critical configuration sections
        if (!isset($this->config['replicate']['models']['sdxl'])) {
            throw new RuntimeException('SDXL model configuration is missing');
        }

        if (!isset($this->config['paths'])) {
            throw new RuntimeException('Paths configuration is missing');
        }

        // Validate base URL format
        $baseUrl = $this->config['app']['base_url'] ?? null;
        if ($baseUrl) {
            // Remove trailing slashes for consistency
            $baseUrl = rtrim($baseUrl, '/');
            
            // Validate URL format
            if (!filter_var($baseUrl, FILTER_VALIDATE_URL)) {
                throw new RuntimeException('Invalid base URL format: ' . $baseUrl);
            }
            
            // Store validated URL
            $this->config['app']['base_url'] = $baseUrl;
        }

        // Validate paths exist or are creatable
        foreach ($this->config['paths'] as $key => $path) {
            if (!file_exists($path) && !@mkdir($path, 0775, true)) {
                error_log("Warning: Unable to create path: {$path}");
            }
        }
    }

    public static function getInstance(): Config
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function get(string $key, $default = null)
    {
        $keys = explode('.', $key);
        $value = $this->config;

        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }

    /**
     * Get Replicate API key with validation
     * @throws Exception if API token is not configured
     */
    public function getReplicateApiKey(): string
    {
        $apiKey = $this->get('replicate.api_token');

        if (!$apiKey) {
            throw new RuntimeException('REPLICATE_API_TOKEN not configured. Please set it in .env or environment variables.');
        }

        return $apiKey;
    }

    /**
     * Get and validate a path from configuration
     * @param string $type Path type (output, logs, temp, etc.)
     * @param array $options Additional options (create_if_missing, validate_writable, etc.)
     * @return string The validated and formatted path
     * @throws RuntimeException if path is not configured or validation fails
     */
    public function getPath(string $type, array $options = []): string
    {
        $options = array_merge([
            'create_if_missing' => true,
            'validate_writable' => true,
            'trailing_slash' => true,
            'permissions' => 0775
        ], $options);

        $path = $this->get("paths.$type");
        if (!$path) {
            throw new RuntimeException("Path not configured for: $type");
        }

        $fullPath = $options['trailing_slash'] ? rtrim($path, '/') . '/' : rtrim($path, '/');

        // Create directory if it doesn't exist and creation is requested
        if ($options['create_if_missing'] && !file_exists($fullPath)) {
            if (!mkdir($fullPath, $options['permissions'], true)) {
                error_log("Failed to create directory: $fullPath");
                throw new RuntimeException("Failed to create directory: $fullPath");
            }

            // Set proper permissions
            if (function_exists('posix_getpwuid')) {
                chown($fullPath, 'www-data');
                chgrp($fullPath, 'www-data');
            }

            error_log("Created directory: $fullPath");
        }

        // Validate writability if requested
        if ($options['validate_writable'] && !is_writable($fullPath)) {
            error_log("Directory is not writable: $fullPath");
            error_log("Permissions: " . substr(sprintf('%o', fileperms($fullPath)), -4));
            error_log("Owner: " . (function_exists('posix_getpwuid') ? posix_getpwuid(fileowner($fullPath))['name'] : 'unknown'));
            error_log("Group: " . (function_exists('posix_getgrgid') ? posix_getgrgid(filegroup($fullPath))['name'] : 'unknown'));
            throw new RuntimeException("Directory is not writable: $fullPath");
        }

        return $fullPath;
    }

    /**
     * Get the current environment (development, production, testing)
     */
    public function getEnvironment(): string
    {
        if ($this->environment === null) {
            $env = strtolower($this->getEnv('APP_ENV', 'production'));
            $this->environment = trim($env);
        }
        return $this->environment;
    }

    /**
     * Check if debug mode is enabled
     */
    public function isDebugMode(): bool
    {
        return (bool) $this->get('app.debug', false);
    }

    /**
     * Get the configured log level
     */
    public function getLogLevel(): string
    {
        return strtolower($this->get('app.log_level', 'info'));
    }

    /**
     * Get model version from configuration
     * @param string $model Model identifier (e.g., 'cartoonify', 'sdxl')
     * @return string Model version
     * @throws RuntimeException if model version not found
     */
    public function getModelVersion(string $model): string
    {
        // For background generation, use the SDXL model
        if ($model === 'background') {
            $model = 'sdxl';
        }
        
        $version = $this->get("replicate.models.$model.version");
        if (!$version) {
            throw new RuntimeException("Model version not found for: $model");
        }
        return $version;
    }

    /**
     * Get model parameters from configuration
     * @param string $model Model identifier (e.g., 'cartoonify', 'sdxl')
     * @return array Model parameters
     * @throws RuntimeException if model parameters not found
     */
    public function getModelParams(string $model): array
    {
        // For background generation, use the SDXL model params
        if ($model === 'background') {
            $model = 'sdxl';
        }
        
        $params = $this->get("replicate.models.$model.params");
        if (!$params) {
            throw new RuntimeException("Model parameters not found for: $model");
        }
        return $params;
    }

    /**
     * @deprecated Use getModelVersion('cartoonify') instead
     */
    public function getCartoonifyModel(): string
    {
        return $this->getModelVersion('cartoonify');
    }

    /**
     * @deprecated Use getModelVersion() and getModelParams() instead
     */
    public function getModelConfig(string $model): array
    {
        return [
            'version' => $this->getModelVersion($model),
            'params' => $this->getModelParams($model)
        ];
    }

    public function getNegativePrompts(): array
    {
        return $this->get('negative_prompts', []);
    }

    public function getEnv(string $key, $default = null)
    {
        return $this->env[$key] ?? $default;
    }

    public function getImageConfig(): array
    {
        $config = $this->get('image');
        if (!$config) {
            throw new RuntimeException('Image configuration not found');
        }
        return $config;
    }

    private function getDefaultConfig(): array
    {
        return [
            'app' => [
                'debug' => false,
                'log_level' => 'info',
                'base_url' => 'http://localhost',
            ],
            'paths' => [
                'output' => __DIR__ . '/../public/generated/',
                'temp' => __DIR__ . '/../temp/',
            ],
            'replicate' => [
                'models' => [
                    'cartoonify' => [
                        'version' => 'f109015d60170dfb20460f17da8cb863155823c85ece1115e1e9e4ec7ef51d3b',
                        'params' => [
                            'num_inference_steps' => 50,
                            'guidance_scale' => 7.5
                        ]
                    ],
                    'nlp' => [
                        'version' => 'f4e2de70d66816a838a89eeeb621910adffb0dd0baba3976c96980970978018d',
                        'params' => [
                            'temperature' => 0.75,
                            'top_p' => 0.9,
                            'max_length' => 2048
                        ]
                    ]
                ]
            ]
        ];
    }

    /**
     * Get the background generation model configuration
     * @return array Model configuration with version and parameters
     */
    public function getBackgroundModel(): array
    {
        // Always use SDXL configuration for backgrounds
        $config = $this->get('replicate.models.sdxl');
        
        if (!$config) {
            throw new RuntimeException('SDXL model configuration not found');
        }
        
        return $config;
    }

    /**
     * Get the base URL with environment-aware handling
     * @return string The base URL for the application
     */
    public function getBaseUrl(): string
    {
        // Return cached URL if available
        if ($this->cachedBaseUrl !== null) {
            return $this->cachedBaseUrl;
        }

        // Priority 1: Environment variable override
        if (!empty($this->env['APP_BASE_URL'])) {
            $this->cachedBaseUrl = rtrim($this->env['APP_BASE_URL'], '/');
            return $this->cachedBaseUrl;
        }

        // Priority 2: Config file value
        $configUrl = $this->get('app.base_url');
        if ($configUrl) {
            $this->cachedBaseUrl = rtrim($configUrl, '/');
            return $this->cachedBaseUrl;
        }

        // Priority 3: Auto-detect (development only)
        if ($this->getEnvironment() === 'development') {
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $port = $_SERVER['SERVER_PORT'] ?? '80';
            
            // Add port if non-standard
            if (!in_array($port, ['80', '443'])) {
                $host .= ":$port";
            }
            
            $this->cachedBaseUrl = "$protocol://$host";
            return $this->cachedBaseUrl;
        }

        // Fallback for production (should be configured properly)
        throw new RuntimeException('Base URL not configured. Please set APP_BASE_URL in environment or app.base_url in config.');
    }
}
