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

// Initialize logger first
$logger = null;
try {
    $logger = new Logger();
} catch (Throwable $e) {
    error_log('Failed to initialize logger: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error: Logger initialization failed'
    ]);
    exit;
}

try {
    // Initialize other dependencies
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

        echo json_encode([
            'success' => true,
            'data' => $state
        ]);
        exit();
    }

    // Handle comic generation requests
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $logger->debug('Received POST request', [
            'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'not set',
            'content_length' => $_SERVER['CONTENT_LENGTH'] ?? 'not set'
        ]);

        try {
            $imageComposer = new ImageComposer($logger, $config);
            $characterProcessor = new CharacterProcessor($logger, $config);
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
            $rawInput = file_get_contents('php://input');
            if ($rawInput === false) {
                throw new Exception('Failed to read request input');
            }

            $logger->debug('Received raw input', [
                'length' => strlen($rawInput),
                'preview' => substr($rawInput, 0, 200) // First 200 chars
            ]);

            $input = json_decode($rawInput, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $logger->error('JSON decode error', [
                    'error' => json_last_error_msg(),
                    'raw_input' => substr($rawInput, 0, 1000) // Log first 1000 chars of input
                ]);
                throw new Exception('Invalid JSON input: ' . json_last_error_msg());
            }

            if (!$input) {
                throw new Exception('Empty or invalid request body');
            }

            $logger->debug('Parsed input', [
                'story_length' => strlen($input['story'] ?? ''),
                'num_characters' => count($input['characters'] ?? []),
                'style' => $input['style'] ?? 'not set',
                'background' => $input['background'] ?? 'not set'
            ]);

            // Create required directories with proper permissions
            $outputPath = $config->getOutputPath();
            if (!file_exists($outputPath)) {
                if (!mkdir($outputPath, 0755, true)) {
                    throw new Exception('Failed to create output directory');
                }
                $logger->debug('Created output directory', ['path' => $outputPath]);
            }

            // Handle the request
            $logger->debug('Handling request with controller');
            $result = $controller->handleRequest($input);  // Pass the input to handleRequest

            // Return result
            $logger->debug('Request handled successfully', ['result' => $result]);
            echo json_encode($result);
            exit();
        } catch (Throwable $e) {
            $logger->error('Error in POST request handling', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e; // Re-throw to be caught by the main try-catch
        }
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
