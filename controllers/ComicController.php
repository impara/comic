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
            'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'not set',
            'raw_input' => $rawInput
        ]);

        $input = json_decode($rawInput, true);
        if (!$input) {
            $this->logger->error('Invalid JSON input', [
                'json_error' => json_last_error_msg(),
                'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'not set',
                'request_method' => $_SERVER['REQUEST_METHOD'],
                'headers' => getallheaders(),
                'raw_input' => $rawInput
            ]);
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Invalid JSON input: ' . json_last_error_msg()
            ]);
            return;
        }

        // Handle comic generation
        try {
            $this->logger->info('Processing comic strip generation request', [
                'input' => [
                    'character_count' => count($input['characters'] ?? []),
                    'story_length' => strlen($input['story'] ?? ''),
                    'story_value' => $input['story'] ?? null,
                    'story_type' => gettype($input['story'] ?? null),
                    'art_style' => $input['art_style'] ?? 'not set',
                    'raw_input' => $input
                ]
            ]);

            // Validate input
            $this->validateInput($input);

            // Prepare generation options
            $options = $this->prepareGenerationOptions($input);

            // Generate the comic strip
            $result = $this->comicGenerator->generateComicStrip(
                $input['story'],
                $input['characters'],
                $options
            );

            // Return appropriate response based on generation status
            if ($result['status'] === 'processing') {
                $this->logger->info('Comic strip generation in processing state', [
                    'result' => $result
                ]);

                echo json_encode([
                    'success' => true,
                    'message' => 'Comic strip generation started',
                    'result' => [
                        'id' => $result['id'],
                        'status' => 'processing',
                        'total_panels' => $result['total_panels'],
                        'pending_panels' => $result['pending_panels']
                    ]
                ]);
            } else {
                $this->logger->info('Comic strip generation completed', [
                    'result' => $result
                ]);

                echo json_encode([
                    'success' => true,
                    'message' => 'Comic strip generated successfully',
                    'result' => [
                        'id' => $result['id'],
                        'status' => 'completed',
                        'total_panels' => $result['total_panels'],
                        'output_path' => $result['output_path'] ?? null,
                        'panels' => $result['completed_panels']
                    ]
                ]);
            }
        } catch (Exception $e) {
            $this->logger->error('Comic strip generation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'input' => $input ?? null
            ]);

            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => $this->config->isDebugMode() ? $e->getMessage() : 'Failed to generate comic strip',
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
            'has_story' => isset($input['story']),
            'has_style' => isset($input['art_style']),
            'has_background' => isset($input['background'])
        ]);

        // Validate story
        if (!isset($input['story']) || empty($input['story'])) {
            throw new RuntimeException('Story is required');
        }

        // Validate characters
        if (!isset($input['characters']) || !is_array($input['characters'])) {
            throw new RuntimeException('Characters array is required');
        }

        if (empty($input['characters'])) {
            throw new RuntimeException('At least one character is required');
        }

        // Validate each character
        foreach ($input['characters'] as $index => $character) {
            $this->validateCharacter($character, $index);
        }

        // Validate style
        if (!isset($input['art_style']) || empty($input['art_style'])) {
            throw new RuntimeException('Art style is required');
        }

        $this->logger->info('Input validation successful');
    }

    /**
     * Validate a single character
     * @throws RuntimeException if validation fails
     */
    private function validateCharacter(array $character, int $index): void
    {
        $this->logger->info('Validating character', [
            'index' => $index,
            'has_description' => isset($character['description']),
            'has_image' => isset($character['image']),
            'has_id' => isset($character['id'])
        ]);

        // Validate character ID
        if (!isset($character['id'])) {
            throw new RuntimeException("Character at index $index must have an ID");
        }

        // Validate character description or image
        if (!isset($character['description']) && !isset($character['image'])) {
            throw new RuntimeException("Character at index $index must have either description or image");
        }

        // Validate image format if provided
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

    /**
     * Prepare generation options from input
     * @param array $input Request input
     * @return array Generation options
     */
    private function prepareGenerationOptions(array $input): array
    {
        $options = [
            'style' => [
                'art_style' => $input['art_style'],
                'background' => $input['background']
            ]
        ];

        // Add any additional style parameters
        if (isset($input['style_params'])) {
            $options['style_params'] = $input['style_params'];
        }

        // Add panel-specific options if provided
        if (isset($input['panel_options'])) {
            $options['panel_options'] = $input['panel_options'];
        }

        return $options;
    }
}
