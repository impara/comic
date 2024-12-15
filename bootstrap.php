<?php

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Load environment variables
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }

        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);

        if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
            putenv(sprintf('%s=%s', $name, $value));
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

// Set up error log path
$isProduction = isset($_SERVER['SERVER_NAME']) && strpos($_SERVER['SERVER_NAME'], 'comic.amertech.online') !== false;
if ($isProduction) {
    $logsDir = '/var/www/comic.amertech.online/logs';
} else {
    $logsDir = __DIR__ . '/logs';
}

// Create logs directory if it doesn't exist
if (!file_exists($logsDir)) {
    mkdir($logsDir, 0777, true);
}

$errorLogFile = $logsDir . '/php_errors.log';
ini_set('error_log', $errorLogFile);

// Set up autoloading if needed
spl_autoload_register(function ($class) {
    $file = __DIR__ . '/models/' . $class . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});
