<?php

require_once __DIR__ . '/utils/EnvLoader.php';

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

try {
    // Load environment variables
    EnvLoader::load(__DIR__ . '/.env');

    // Define environment constants
    define('IS_PRODUCTION', isset($_SERVER['SERVER_NAME']) && strpos($_SERVER['SERVER_NAME'], 'comic.amertech.online') !== false);
    define('ROOT_DIR', __DIR__);
    define('LOGS_DIR', IS_PRODUCTION ? '/var/www/comic.amertech.online/logs' : __DIR__ . '/logs');

    // Ensure logs directory exists
    if (!file_exists(LOGS_DIR)) {
        mkdir(LOGS_DIR, 0777, true);
        if (IS_PRODUCTION && function_exists('posix_getuid') && posix_getuid() === 0) {
            chown(LOGS_DIR, 'www-data');
            chgrp(LOGS_DIR, 'www-data');
        }
    }

    // Set up error log
    $errorLogFile = LOGS_DIR . '/php_errors.log';
    if (!file_exists($errorLogFile)) {
        touch($errorLogFile);
        chmod($errorLogFile, 0666);
        if (IS_PRODUCTION && function_exists('posix_getuid') && posix_getuid() === 0) {
            chown($errorLogFile, 'www-data');
            chgrp($errorLogFile, 'www-data');
        }
    }
    ini_set('error_log', $errorLogFile);

    // Set up consistent error handler
    set_error_handler(function ($errno, $errstr, $errfile, $errline) {
        $errorType = match ($errno) {
            E_ERROR => 'Error',
            E_WARNING => 'Warning',
            E_PARSE => 'Parse Error',
            E_NOTICE => 'Notice',
            default => 'Unknown Error'
        };

        error_log(sprintf(
            "[%s] %s: %s in %s on line %d",
            date('Y-m-d H:i:s'),
            $errorType,
            $errstr,
            $errfile,
            $errline
        ));

        return false;
    });

    // Set up exception handler
    set_exception_handler(function ($exception) {
        error_log(sprintf(
            "[%s] Uncaught Exception: %s in %s on line %d\nStack trace:\n%s",
            date('Y-m-d H:i:s'),
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            $exception->getTraceAsString()
        ));
    });

    // Set up autoloading
    spl_autoload_register(function ($class) {
        $directories = [
            ROOT_DIR . '/models/',
            ROOT_DIR . '/controllers/',
            ROOT_DIR . '/interfaces/',
            ROOT_DIR . '/utils/'
        ];

        foreach ($directories as $directory) {
            $file = $directory . $class . '.php';
            if (file_exists($file)) {
                require_once $file;
                return true;
            }
        }
        return false;
    });
} catch (Throwable $e) {
    error_log(sprintf(
        "Bootstrap Error: %s in %s on line %d\nStack trace:\n%s",
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString()
    ));
    http_response_code(500);
    die('Application failed to initialize. Please check error logs.');
}
