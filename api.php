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
    // Initialize dependencies
    $logger = new Logger();
    $config = Config::getInstance();

    // Initialize StateManager with correct parameters
    $tempPath = $config->getTempPath();
    $stateManager = new StateManager($tempPath, $logger);

    $imageComposer = new ImageComposer($logger);
    $characterProcessor = new CharacterProcessor($logger);
    $storyParser = new StoryParser($logger);
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

    // Validate request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Only POST method is allowed');
    }

    // Get and validate input
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        throw new Exception('Invalid JSON input');
    }

    // Create required directories with proper permissions
    $outputPath = $config->getOutputPath();
    if (!file_exists($outputPath)) {
        if (!mkdir($outputPath, 0755, true)) {
            throw new Exception('Failed to create output directory');
        }
    }

    // Handle the request
    $result = $controller->handleRequest();

    // Ensure proper JSON response
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }
    echo json_encode($result);
} catch (Throwable $e) {
    $logger->error('API Error', [
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
    }

    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
