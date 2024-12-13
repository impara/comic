<?php

require_once __DIR__ . '/utils/EnvLoader.php';

// Load environment variables
EnvLoader::load(__DIR__ . '/.env');

// Enable error logging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/php_errors.log');

// Add detailed error handler
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    error_log(sprintf(
        "[%s] Error %d: %s in %s:%d",
        date('Y-m-d H:i:s'),
        $errno,
        $errstr,
        $errfile,
        $errline
    ));
    return false;
});

// Include required files
require_once __DIR__ . '/models/Config.php';
require_once __DIR__ . '/controllers/ComicController.php';

// Initialize configuration
$config = Config::getInstance();

// Log configuration state
error_log(sprintf(
    "Configuration loaded - Debug: %s",
    $config->isDebugMode() ? 'true' : 'false'
));

// Define debug mode based on configuration if not already defined
if (!defined('DEBUG')) {
    define('DEBUG', $config->isDebugMode());
}

// Enable CORS for development
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

// Add error handler
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    error_log(sprintf(
        "Error [%d]: %s in %s on line %d",
        $errno,
        $errstr,
        $errfile,
        $errline
    ));
    return false;
});

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Create required directories
$outputPath = $config->getOutputPath();
if (!file_exists($outputPath)) {
    mkdir($outputPath, 0755, true);
}

$logsDir = $config->getLogsPath();
if (!file_exists($logsDir)) {
    mkdir($logsDir, 0755, true);
}

// Wrap the main execution in try-catch
try {
    // Initialize and handle the request
    $controller = new ComicController();
    $controller->handleRequest();
} catch (Throwable $e) {
    error_log(sprintf(
        "Fatal Error: %s in %s on line %d\nStack trace:\n%s",
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString()
    ));

    http_response_code(500);
    echo json_encode([
        'error' => 'Internal Server Error',
        'message' => DEBUG ? $e->getMessage() : 'An unexpected error occurred',
        'debug' => DEBUG ? [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ] : null
    ]);
}
