<?php

require_once __DIR__ . '/utils/EnvLoader.php';

// Set error reporting and basic security headers
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Set security headers
if (PHP_SAPI !== 'cli') {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

try {
    // Load environment variables
    EnvLoader::load(__DIR__ . '/.env');

    // Define environment constants
    define('IS_PRODUCTION', isset($_SERVER['SERVER_NAME']) && strpos($_SERVER['SERVER_NAME'], 'comic.amertech.online') !== false);
    define('ROOT_DIR', __DIR__);
    define('LOGS_DIR', IS_PRODUCTION ? '/var/www/comic.amertech.online/logs' : __DIR__ . '/logs');
    define('CACHE_DIR', IS_PRODUCTION ? '/var/www/comic.amertech.online/cache' : __DIR__ . '/cache');
    define('OUTPUT_DIR', IS_PRODUCTION ? '/var/www/comic.amertech.online/output' : __DIR__ . '/output');

    // Ensure required directories exist with proper permissions
    $requiredDirs = [
        LOGS_DIR => 0775,
        CACHE_DIR => 0775,
        OUTPUT_DIR => 0775
    ];

    foreach ($requiredDirs as $dir => $perms) {
        if (!file_exists($dir)) {
            if (!mkdir($dir, $perms, true)) {
                throw new RuntimeException("Failed to create directory: $dir");
            }
            if (IS_PRODUCTION && function_exists('posix_getuid') && posix_getuid() === 0) {
                chown($dir, 'www-data');
                chgrp($dir, 'www-data');
            }
        }
    }

    // Set up error log with rotation
    $errorLogFile = LOGS_DIR . '/php_errors.log';
    if (!file_exists($errorLogFile)) {
        if (!touch($errorLogFile)) {
            throw new RuntimeException("Failed to create error log file: $errorLogFile");
        }
        chmod($errorLogFile, 0664);
        if (IS_PRODUCTION && function_exists('posix_getuid') && posix_getuid() === 0) {
            chown($errorLogFile, 'www-data');
            chgrp($errorLogFile, 'www-data');
        }
    }
    ini_set('error_log', $errorLogFile);

    // Rotate error log if it's too large
    if (file_exists($errorLogFile) && filesize($errorLogFile) > 5 * 1024 * 1024) { // 5MB
        $backupFile = $errorLogFile . '.' . date('Y-m-d-H-i-s');
        rename($errorLogFile, $backupFile);
        touch($errorLogFile);
        chmod($errorLogFile, 0664);
    }

    // Set up consistent error handler
    set_error_handler(function ($errno, $errstr, $errfile, $errline) {
        $errorType = match ($errno) {
            E_ERROR => 'Error',
            E_WARNING => 'Warning',
            E_PARSE => 'Parse Error',
            E_NOTICE => 'Notice',
            E_STRICT => 'Strict Standards',
            E_DEPRECATED => 'Deprecated',
            default => 'Unknown Error'
        };

        $message = sprintf(
            "[%s] [%s] %s: %s in %s on line %d",
            date('Y-m-d H:i:s'),
            php_sapi_name(),
            $errorType,
            $errstr,
            $errfile,
            $errline
        );

        error_log($message);

        // Don't execute PHP internal error handler
        return true;
    });

    // Set up exception handler
    set_exception_handler(function (Throwable $exception) {
        $message = sprintf(
            "[%s] [%s] Uncaught %s: %s in %s on line %d\nStack trace:\n%s",
            date('Y-m-d H:i:s'),
            php_sapi_name(),
            get_class($exception),
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            $exception->getTraceAsString()
        );

        error_log($message);

        if (PHP_SAPI !== 'cli') {
            http_response_code(500);
            if (!IS_PRODUCTION) {
                echo "<h1>Application Error</h1>";
                echo "<pre>" . htmlspecialchars($message) . "</pre>";
            } else {
                echo "An unexpected error occurred. Please try again later.";
            }
        }
        exit(1);
    });

    // Set up autoloading with namespace support
    spl_autoload_register(function ($class) {
        $directories = [
            ROOT_DIR . '/models/',
            ROOT_DIR . '/controllers/',
            ROOT_DIR . '/interfaces/',
            ROOT_DIR . '/utils/',
            ROOT_DIR . '/services/'
        ];

        // Convert namespace separators to directory separators
        $file = str_replace('\\', DIRECTORY_SEPARATOR, $class) . '.php';

        foreach ($directories as $directory) {
            $path = $directory . $file;
            if (file_exists($path)) {
                require_once $path;
                return true;
            }
        }

        return false;
    });

    // Initialize session with secure settings if not CLI
    if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
        ini_set('session.cookie_httponly', 1);
        ini_set('session.use_strict_mode', 1);
        ini_set('session.cookie_samesite', 'Lax');
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            ini_set('session.cookie_secure', 1);
        }
        session_start();
    }

    // Set default timezone
    date_default_timezone_set('UTC');
} catch (Throwable $e) {
    error_log(sprintf(
        "[%s] Bootstrap Error: %s in %s on line %d\nStack trace:\n%s",
        date('Y-m-d H:i:s'),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString()
    ));

    if (PHP_SAPI !== 'cli') {
        http_response_code(500);
        if (!IS_PRODUCTION) {
            echo "<h1>Bootstrap Error</h1>";
            echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
        } else {
            echo "Application failed to initialize. Please try again later.";
        }
    }
    exit(1);
}
