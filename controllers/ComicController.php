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
            http_response_code(405);
            echo json_encode([
                'success' => false,
                'message' => 'Method not allowed'
            ]);
            return;
        }

        // Get JSON input
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Invalid JSON input'
            ]);
            return;
        }

        // Handle comic generation
        try {
            // Validate input
            $this->validateInput($input);

            // Generate the comic panel
            $result = $this->comicGenerator->generatePanel(
                $input['characters'],
                $input['scene_description']
            );

            // Return the result
            echo json_encode([
                'success' => true,
                'message' => 'Comic generated successfully',
                'result' => $result
            ]);
        } catch (Exception $e) {
            $this->logger->error('Comic generation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
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
        if (!isset($input['characters']) || !is_array($input['characters'])) {
            throw new RuntimeException('Characters array is required');
        }

        if (empty($input['characters'])) {
            throw new RuntimeException('At least one character is required');
        }

        if (!isset($input['scene_description']) || empty($input['scene_description'])) {
            throw new RuntimeException('Scene description is required');
        }

        foreach ($input['characters'] as $index => $character) {
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
    }
}
