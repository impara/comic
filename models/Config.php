<?php

require_once __DIR__ . '/../utils/EnvLoader.php';

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
    }

    private function loadConfig(): void
    {
        $configFile = __DIR__ . '/../config/config.php';
        if (!file_exists($configFile)) {
            throw new RuntimeException('Configuration file not found');
        }
        $this->config = require $configFile;

        // Override config values with environment variables
        if (isset($this->env['APP_DEBUG'])) {
            $this->config['app']['debug'] = $this->env['APP_DEBUG'] === 'true';
        }
        if (isset($this->env['APP_LOG_LEVEL'])) {
            $this->config['app']['log_level'] = strtolower($this->env['APP_LOG_LEVEL']);
        }
        if (isset($this->env['APP_BASE_URL'])) {
            $this->config['app']['base_url'] = $this->env['APP_BASE_URL'];
        }
        if (isset($this->env['REPLICATE_API_TOKEN'])) {
            $this->config['replicate']['api_token'] = $this->env['REPLICATE_API_TOKEN'];
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
        // Try config first (which may be set from env in loadConfig)
        $apiKey = $this->get('replicate.api_token');

        // Fallback to direct env check if not in config
        if (!$apiKey) {
            $apiKey = getenv('REPLICATE_API_TOKEN');
        }

        // Final validation
        if (!$apiKey) {
            throw new Exception('REPLICATE_API_TOKEN not configured. Please set it in .env or environment variables.');
        }

        return $apiKey;
    }

    /**
     * @deprecated Use getReplicateApiKey() instead
     */
    public function getApiToken(): string
    {
        try {
            return $this->getReplicateApiKey();
        } catch (Exception $e) {
            return '';
        }
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
            'create_if_missing' => false,
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
     * @deprecated Use getPath('output') instead
     */
    public function getOutputPath(): string
    {
        return $this->getPath('output', ['create_if_missing' => false, 'validate_writable' => false]);
    }

    /**
     * @deprecated Use getPath('logs') instead
     */
    public function getLogsPath(): string
    {
        return $this->getPath('logs', ['create_if_missing' => true]);
    }

    /**
     * @deprecated Use getPath('temp') instead
     */
    public function getTempPath(): string
    {
        return $this->getPath('temp', ['create_if_missing' => true]);
    }

    /**
     * Get the current environment (development, production, testing)
     */
    public function getEnvironment(): string
    {
        if ($this->environment === null) {
            $this->environment = strtolower($this->getEnv('APP_ENV', 'production'));
        }
        return $this->environment;
    }

    /**
     * Get the base URL with environment-aware handling
     */
    public function getBaseUrl(): string
    {
        // Return cached URL if available
        if ($this->cachedBaseUrl !== null) {
            return $this->cachedBaseUrl;
        }

        // Priority 1: Environment variable override
        if (!empty($this->env['APP_BASE_URL'])) {
            $this->cachedBaseUrl = $this->env['APP_BASE_URL'];
            return $this->cachedBaseUrl;
        }

        // Priority 2: Config file value for production
        if ($this->getEnvironment() === 'production') {
            $configUrl = $this->get('app.base_url');
            if ($configUrl) {
                $this->cachedBaseUrl = $configUrl;
                return $this->cachedBaseUrl;
            }
        }

        // Priority 3: Development URL construction
        if ($this->getEnvironment() === 'development') {
            $port = $_SERVER['SERVER_PORT'] ?? '80';
            $defaultHost = 'localhost' . ($port !== '80' && $port !== '443' ? ":$port" : '');
        } else {
            $defaultHost = 'localhost';
        }

        // Determine protocol
        $protocol = 'http';

        // Check HTTPS
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            $protocol = 'https';
        }

        // Check for reverse proxy headers
        $headers = [
            'HTTP_X_FORWARDED_PROTO' => 'protocol',
            'HTTP_X_FORWARDED_SSL' => 'ssl',
            'HTTP_CF_VISITOR' => 'cloudflare'
        ];

        foreach ($headers as $header => $type) {
            if (!empty($_SERVER[$header])) {
                switch ($type) {
                    case 'protocol':
                        $protocol = $_SERVER[$header];
                        break;
                    case 'ssl':
                        if ($_SERVER[$header] === 'on') {
                            $protocol = 'https';
                        }
                        break;
                    case 'cloudflare':
                        $cfVisitor = json_decode($_SERVER[$header], true);
                        if (!empty($cfVisitor['scheme'])) {
                            $protocol = $cfVisitor['scheme'];
                        }
                        break;
                }
            }
        }

        // Get host
        $host = $_SERVER['HTTP_HOST'] ?? $defaultHost;

        // Cache and return the constructed URL
        $this->cachedBaseUrl = $protocol . '://' . $host;
        return $this->cachedBaseUrl;
    }

    /**
     * Get the application log level
     * @return string The log level (DEBUG, INFO, WARNING, ERROR)
     */
    public function getLogLevel(): string
    {
        return strtoupper($this->get('app.log_level', 'INFO'));
    }

    /**
     * Check if debug mode is enabled
     * This is used for non-logging debug features
     * @return bool
     */
    public function isDebugMode(): bool
    {
        return (bool)$this->get('app.debug', false);
    }

    /**
     * Get model version from configuration
     * @param string $model Model identifier (e.g., 'cartoonify', 'sdxl')
     * @return string Model version
     * @throws RuntimeException if model version not found
     */
    public function getModelVersion(string $model): string
    {
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
                'base_url' => 'https://comic.amertech.online',
            ],
            'paths' => [
                'output' => __DIR__ . '/../public/generated/',
                'temp' => __DIR__ . '/../temp/',
            ],
            'debug' => true,
            'log_level' => 'debug',
            'base_url' => 'https://comic.amertech.online',
            'output_path' => __DIR__ . '/../public/generated/',
            'temp_path' => __DIR__ . '/../temp/',
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
     * Get the background generation model ID
     */
    public function getBackgroundModel(): string
    {
        return $_ENV['BACKGROUND_MODEL'] ?? 'stability-ai/sdxl:39ed52f2a78e934b3ba6e2a89f5b1c712de7dfea535525255b1aa35c5565e08b';
    }
}
