<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/models/Logger.php';
require_once __DIR__ . '/models/Config.php';
require_once __DIR__ . '/models/ImageComposer.php';
require_once __DIR__ . '/models/CharacterProcessor.php';
require_once __DIR__ . '/models/StoryParser.php';
require_once __DIR__ . '/models/StateManager.php';
require_once __DIR__ . '/models/ComicGenerator.php';
require_once __DIR__ . '/controllers/ComicController.php';

try {
    // Initialize configuration
    $config = Config::getInstance();
    $logger = new Logger();

    // Initialize dependencies for ComicController
    $imageComposer = new ImageComposer($logger, $config);
    $characterProcessor = new CharacterProcessor($logger, $config);
    $storyParser = new StoryParser($logger);
    $stateManager = new StateManager($config->getTempPath(), $logger);

    // Initialize ComicGenerator with its dependencies
    $comicGenerator = new ComicGenerator(
        $stateManager,
        $logger,
        $config,
        $imageComposer,
        $characterProcessor,
        $storyParser
    );

    // Initialize controller with dependencies
    $controller = new ComicController($logger, $config, $comicGenerator);

    // Enable CORS for development
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Content-Type: application/json');

    // Handle preflight requests
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit();
    }

    // Create required directories with proper permissions
    $outputPath = $config->getOutputPath();
    if (!file_exists($outputPath)) {
        mkdir($outputPath, 0755, true);
        if (IS_PRODUCTION && function_exists('posix_getuid') && posix_getuid() === 0) {
            chown($outputPath, 'www-data');
            chgrp($outputPath, 'www-data');
        }
    }

    // Handle the request
    $controller->handleRequest();
} catch (Throwable $e) {
    error_log(sprintf(
        "API Error: %s in %s on line %d\nStack trace:\n%s",
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString()
    ));

    http_response_code(500);
    echo json_encode([
        'error' => 'Internal Server Error',
        'message' => IS_PRODUCTION ? 'An unexpected error occurred' : $e->getMessage(),
        'debug' => IS_PRODUCTION ? null : [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]
    ]);
}
