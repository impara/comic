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
    $stateManager = new StateManager($config->getTempPath(), $logger);

    // Enable CORS for development
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Content-Type: application/json');

    // Handle preflight requests
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit();
    }

    // Handle status requests
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'status') {
        if (!isset($_GET['id'])) {
            throw new Exception('Strip ID is required');
        }

        $stripId = $_GET['id'];
        $state = $stateManager->getStripState($stripId);
        if (empty($state)) {
            throw new Exception('Strip not found');
        }

        echo json_encode($state);
        exit();
    }

    // Handle comic generation requests
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

        // Return result
        echo json_encode($result);
        exit();
    }

    throw new Exception('Invalid request method or action');
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
