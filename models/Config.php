<?php

require_once __DIR__ . '/../utils/EnvLoader.php';

class Config
{
    private static ?Config $instance = null;
    private array $config;
    private array $env;

    public function __construct()
    {
        // Load environment variables if not already loaded
        if (empty($_ENV)) {
            EnvLoader::load(__DIR__ . '/../.env');
        }

        // Store environment variables
        $this->env = $_ENV;

        // Load configuration file
        $this->config = require __DIR__ . '/../config/config.php';
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
            self::$instance = new Config();
        }
        return self::$instance;
    }

    public function get(string $key, $default = null)
    {
        $parts = explode('.', $key);
        $value = $this->config;

        foreach ($parts as $part) {
            if (!isset($value[$part])) {
                return $default;
            }
            $value = $value[$part];
        }

        return $value;
    }

    public function getApiToken(): string
    {
        $token = $this->get('replicate.api_token');
        if (!$token) {
            throw new RuntimeException('Replicate API token not configured');
        }
        return $token;
    }

    public function getOutputPath(): string
    {
        return $this->get('paths.output');
    }

    public function getLogsPath(): string
    {
        $path = $this->get('paths.logs');
        if (!$path) {
            throw new RuntimeException('Logs directory not configured');
        }
        return rtrim($path, '/') . '/';
    }

    public function getBaseUrl(): string
    {
        // Use APP_BASE_URL from the environment if set
        if (!empty($this->env['APP_BASE_URL'])) {
            return $this->env['APP_BASE_URL'];
        }

        // Derive protocol and host from the request
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';

        // Check for Cloudflare headers to determine the protocol
        if (!empty($_SERVER['HTTP_CF_VISITOR'])) {
            $cfVisitor = json_decode($_SERVER['HTTP_CF_VISITOR'], true);
            if (!empty($cfVisitor['scheme'])) {
                $protocol = $cfVisitor['scheme'];
            }
        }

        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        return $protocol . '://' . $host;
    }

    public function isDebugMode(): bool
    {
        return (bool)$this->get('app.debug', false);
    }

    public function getModelConfig(string $model): array
    {
        $config = $this->get("replicate.models.$model");
        if (!$config) {
            throw new RuntimeException("Model configuration not found for: $model");
        }
        return $config;
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

    public function getLogLevel()
    {
        // Default to INFO if not set
        return $this->get('log_level', 'INFO');
    }
}
