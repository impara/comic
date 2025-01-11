<?php

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/php_errors.log');

require_once __DIR__ . '/bootstrap.php';

// Enable CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Initialize logger first
$logger = new Logger();

try {
    // Initialize other dependencies
    $config = Config::getInstance();
    $stateManager = new StateManager($config->getPath('temp'), $logger);
    $imageComposer = new ImageComposer($logger, $config);
    $characterProcessor = new CharacterProcessor($logger, $config, $stateManager);
    $storyParser = new StoryParser($logger, $config, $stateManager);

    $comicGenerator = new ComicGenerator(
        $stateManager,
        $logger,
        $config,
        $imageComposer,
        $characterProcessor,
        $storyParser
    );

    // Initialize orchestrator
    $orchestrator = new Orchestrator(
        $logger,
        $comicGenerator,
        $characterProcessor,
        $storyParser,
        $config->getPath('temp')
    );

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Handle status request
        if (!isset($_GET['action']) || $_GET['action'] !== 'status' || !isset($_GET['jobId'])) {
            throw new Exception('Invalid status request. Required parameters: action=status&jobId=<id>');
        }

        $jobId = $_GET['jobId'];
        $jobData = $stateManager->getStripState($jobId);

        if (!$jobData) {
            throw new Exception('Job not found');
        }

        echo json_encode([
            'success' => true,
            'status' => $jobData['status'],
            'progress' => $jobData['progress'] ?? 0,
            'output_url' => $jobData['output_url'] ?? null,
            'error' => $jobData['error'] ?? null,
            'updated_at' => $jobData['updated_at'] ?? null
        ]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Log incoming request
        $logger->debug('Received POST request', [
            'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'not set',
            'content_length' => $_SERVER['CONTENT_LENGTH'] ?? 'not set'
        ]);

        // Get raw input
        $rawInput = file_get_contents('php://input');
        $logger->debug('Raw input received', [
            'length' => strlen($rawInput),
            'content' => substr($rawInput, 0, 1000) // Log first 1000 chars
        ]);

        // Parse JSON input
        $input = json_decode($rawInput, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON: ' . json_last_error_msg());
        }

        // Validate required fields
        if (empty($input['story'])) {
            throw new Exception('Story is required');
        }
        if (empty($input['characters']) || !is_array($input['characters'])) {
            throw new Exception('Characters array is required');
        }

        // Log parsed input
        $logger->debug('Parsed input', [
            'story_length' => strlen($input['story']),
            'character_count' => count($input['characters']),
            'style' => $input['style'] ?? 'default',
            'background' => $input['background'] ?? 'default'
        ]);

        // Start new job
        $result = $orchestrator->startJob($input);

        if (!$result['success']) {
            throw new Exception($result['error'] ?? 'Failed to start job');
        }

        $logger->info('Job started successfully', [
            'job_id' => $result['data']['job_id']
        ]);

        echo json_encode([
            'success' => true,
            'jobId' => $result['data']['job_id']
        ]);
        exit;
    }

    throw new Exception('Invalid request method');
} catch (Throwable $e) {
    // Use logger if available, otherwise fall back to error_log
    if (isset($logger)) {
        $logger->error('API request failed', [
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);
    } else {
        error_log(sprintf(
            "API Error: %s in %s on line %d\nTrace: %s",
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        ));
    }

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'debug' => [
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]
    ]);
}
