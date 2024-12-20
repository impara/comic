<?php

require_once __DIR__ . '/../models/Logger.php';
require_once __DIR__ . '/../models/ComicGenerator.php';
require_once __DIR__ . '/../models/Config.php';

class ComicController
{
    private $logger;
    private $config;
    private $comicGenerator;

    // Valid styles and backgrounds
    private const VALID_STYLES = ['manga', 'comic', 'european', 'modern'];
    private const VALID_BACKGROUNDS = ['simple', 'detailed', 'atmospheric'];
    private const PANEL_COUNT = 4;

    public function __construct(Logger $logger, Config $config, ComicGenerator $comicGenerator)
    {
        $this->logger = $logger;
        $this->config = $config;
        $this->comicGenerator = $comicGenerator;
    }

    /**
     * Handle incoming API request
     */
    public function handleRequest(): void
    {
        // Get request body
        $input = json_decode(file_get_contents('php://input'), true);

        if (!$input) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Invalid request body'
            ]);
            return;
        }

        try {
            $result = $this->handleGenerateRequest($input);
            echo json_encode($result);
        } catch (Exception $e) {
            $this->logger->error('Request handling failed', [
                'error' => $e->getMessage()
            ]);

            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Validate and process comic generation request
     */
    public function handleGenerateRequest(array $input): array
    {
        $this->logger->info('Processing comic strip generation request', [
            'input' => [
                'character_count' => count($input['characters'] ?? []),
                'story_length' => strlen($input['story'] ?? ''),
                'art_style' => $input['style'] ?? 'not set',
                'background_style' => $input['background'] ?? 'not set'
            ]
        ]);

        try {
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

            return [
                'success' => true,
                'data' => $result
            ];
        } catch (Exception $e) {
            $this->logger->error('Comic generation failed', [
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Validate all input parameters
     */
    private function validateInput(array $input): void
    {
        $this->logger->info('Validating comic generation input', [
            'input_keys' => array_keys($input),
            'style' => $input['style'] ?? 'not set',
            'valid_styles' => self::VALID_STYLES,
            'background' => $input['background'] ?? 'not set',
            'valid_backgrounds' => self::VALID_BACKGROUNDS,
            'character_count' => count($input['characters'] ?? []),
            'story_length' => strlen($input['story'] ?? '')
        ]);

        // Validate story
        if (empty($input['story'])) {
            throw new RuntimeException('Story is required');
        }

        if (strlen($input['story']) < 50) {
            throw new RuntimeException('Story must be at least 50 characters long');
        }

        // Validate style
        if (empty($input['style']) || !in_array($input['style'], self::VALID_STYLES)) {
            $this->logger->error('Invalid art style', [
                'provided_style' => $input['style'] ?? 'not set',
                'valid_styles' => self::VALID_STYLES
            ]);
            throw new RuntimeException('Invalid or missing art style. Valid styles are: ' . implode(', ', self::VALID_STYLES));
        }

        // Validate background
        if (empty($input['background']) || !in_array($input['background'], self::VALID_BACKGROUNDS)) {
            throw new RuntimeException('Invalid or missing background style. Valid backgrounds are: ' . implode(', ', self::VALID_BACKGROUNDS));
        }

        // Validate characters
        if (empty($input['characters'])) {
            throw new RuntimeException('At least one character is required');
        }

        foreach ($input['characters'] as $index => $character) {
            $this->validateCharacter($character, $index);
        }
    }

    /**
     * Validate individual character data
     */
    private function validateCharacter(array $character, int $index): void
    {
        if (empty($character['id'])) {
            throw new RuntimeException("Character at index $index must have an ID");
        }

        if (empty($character['name'])) {
            throw new RuntimeException("Character at index $index must have a name");
        }

        if (empty($character['image'])) {
            throw new RuntimeException("Character at index $index must have an image");
        }

        // Validate image format
        if (
            !preg_match('/^data:image\/(\w+);base64,/', $character['image'])
            && !filter_var($character['image'], FILTER_VALIDATE_URL)
            && strpos($character['image'], '/public/generated/') === false
        ) {
            throw new RuntimeException("Character image at index $index must be base64 encoded, a valid URL, or a generated image path");
        }
    }

    /**
     * Prepare generation options from input
     */
    private function prepareGenerationOptions(array $input): array
    {
        return [
            'panel_count' => self::PANEL_COUNT,
            'style' => [
                'name' => $input['style'],
                'background' => $input['background'],
                'line_weight' => $input['style_params']['line_weight'] ?? 'medium',
                'shading' => $input['style_params']['shading'] ?? 'cel',
                'color_palette' => $input['style_params']['color_palette'] ?? 'vibrant',
                'consistency_params' => [
                    'character_scale' => 1.0,
                    'background_detail' => $input['background'] === 'detailed' ? 'high' : 'medium',
                    'lighting_scheme' => 'neutral'
                ]
            ],
            'panel_settings' => [
                'width' => 800,
                'height' => 600,
                'margin' => 50,
                'spacing' => 20
            ],
            'generation_params' => [
                'quality' => 'high',
                'style_strength' => 0.8,
                'background_strength' => 0.7
            ]
        ];
    }
}
