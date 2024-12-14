<?php

require_once __DIR__ . '/../models/Logger.php';
require_once __DIR__ . '/../models/ComicGenerator.php';
require_once __DIR__ . '/../models/Config.php';

class ComicController
{
    private LoggerInterface $logger;
    private ComicGenerator $comicGenerator;
    private Config $config;

    public function __construct()
    {
        $this->logger = new Logger();
        $this->config = Config::getInstance();
        $this->comicGenerator = new ComicGenerator($this->logger);
    }

    /**
     * Main request handler
     */
    public function handleRequest(): void
    {
        // Only accept POST requests
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->logger->error('Invalid request method', [
                'method' => $_SERVER['REQUEST_METHOD']
            ]);
            http_response_code(405);
            echo json_encode([
                'success' => false,
                'message' => 'Method not allowed'
            ]);
            return;
        }

        // Get JSON input
        $rawInput = file_get_contents('php://input');
        $this->logger->info('Received request', [
            'raw_input_length' => strlen($rawInput),
            'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'not set'
        ]);

        $input = json_decode($rawInput, true);
        if (!$input) {
            $this->logger->error('Invalid JSON input', [
                'json_error' => json_last_error_msg(),
                'raw_input' => substr($rawInput, 0, 1000) // Log first 1000 chars
            ]);
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Invalid JSON input'
            ]);
            return;
        }

        // Handle comic generation
        try {
            $this->logger->info('Processing comic generation request', [
                'input' => [
                    'character_count' => count($input['characters'] ?? []),
                    'scene_description_length' => strlen($input['scene_description'] ?? ''),
                    'art_style' => $input['art_style'] ?? 'not set'
                ]
            ]);

            // Validate input
            $this->validateInput($input);

            // Generate the comic panel
            $result = $this->comicGenerator->generatePanel(
                $input['characters'],
                $input['scene_description']
            );

            $this->logger->info('Comic generation successful', [
                'result' => $result
            ]);

            // Return the result
            echo json_encode([
                'success' => true,
                'message' => 'Comic generated successfully',
                'result' => $result
            ]);
        } catch (Exception $e) {
            $this->logger->error('Comic generation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'input' => $input ?? null
            ]);

            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => $this->config->isDebugMode() ? $e->getMessage() : 'Failed to generate comic',
                'error' => $this->config->isDebugMode() ? $e->getTraceAsString() : null
            ]);
        }
    }

    /**
     * Validate comic generation input
     * @throws RuntimeException if validation fails
     */
    private function validateInput(array $input): void
    {
        $this->logger->info('Validating input', [
            'has_characters' => isset($input['characters']),
            'has_scene' => isset($input['scene_description']),
            'has_style' => isset($input['art_style'])
        ]);

        if (!isset($input['characters']) || !is_array($input['characters'])) {
            throw new RuntimeException('Characters array is required');
        }

        if (empty($input['characters'])) {
            throw new RuntimeException('At least one character is required');
        }

        foreach ($input['characters'] as $index => $character) {
            $this->logger->info('Validating character', [
                'index' => $index,
                'has_description' => isset($character['description']),
                'has_image' => isset($character['image']),
                'image_type' => isset($character['image']) ? (
                    strpos($character['image'], 'data:image') === 0 ? 'base64' : (filter_var($character['image'], FILTER_VALIDATE_URL) ? 'url' : 'unknown')
                ) : 'none'
            ]);

            if (!isset($character['description']) && !isset($character['image'])) {
                throw new RuntimeException("Character at index $index must have either description or image");
            }

            if (isset($character['image'])) {
                if (
                    !preg_match('/^data:image\/(\w+);base64,/', $character['image'])
                    && !filter_var($character['image'], FILTER_VALIDATE_URL)
                    && strpos($character['image'], '/public/generated/') === false
                ) {
                    throw new RuntimeException("Character image at index $index must be base64 encoded, a valid URL, or a generated image path");
                }
            }
        }

        if (!isset($input['scene_description']) || empty($input['scene_description'])) {
            throw new RuntimeException('Scene description is required');
        }

        $this->logger->info('Input validation successful');
    }
}
