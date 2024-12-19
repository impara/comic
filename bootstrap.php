<?php

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

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

// Load environment variables
if (file_exists(__DIR__ . '/.env')) {
    $envCache = [];
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }

        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);

        $envCache[$name] = $value;
        if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
            putenv(sprintf('%s=%s', $name, $value));
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
    // Store cached env values for reuse
    if (!defined('ENV_CACHE')) {
        define('ENV_CACHE', $envCache);
    }
}

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
        ROOT_DIR . '/interfaces/'
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
