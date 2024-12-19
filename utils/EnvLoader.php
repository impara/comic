<?php

class EnvLoader
{
    private static array $cache = [];

    public static function load(string $path): void
    {
        if (!file_exists($path)) {
            throw new RuntimeException("Environment file not found: $path");
        }

        // Use file() for consistent line ending handling
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new RuntimeException("Failed to read environment file: $path");
        }

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip empty lines and comments
            if (empty($line) || strpos($line, '#') === 0) {
                continue;
            }

            // Parse the line
            if (strpos($line, '=') === false) {
                error_log("Invalid environment variable format: $line");
                continue;
            }

            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // Remove quotes if present
            if (preg_match('/^([\'"])(.*)\1$/', $value, $matches)) {
                $value = $matches[2];
            }

            // Store in cache
            self::$cache[$key] = $value;

            // Only set if not already set
            if (!array_key_exists($key, $_ENV) && !array_key_exists($key, $_SERVER)) {
                putenv("$key=$value");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
    }

    public static function get(string $key, $default = null)
    {
        return self::$cache[$key] ?? $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?: $default;
    }

    public static function has(string $key): bool
    {
        return isset(self::$cache[$key]) ||
            isset($_ENV[$key]) ||
            isset($_SERVER[$key]) ||
            getenv($key) !== false;
    }

    public static function getAll(): array
    {
        return self::$cache;
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }
}
