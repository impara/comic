<?php

class ComicController
{
    private $logger;
    private $config;
    private $comicGenerator;
    private $stateManager;

    // Valid styles and backgrounds
    private const VALID_STYLES = ['classic', 'modern', 'manga', 'comic'];
    private const VALID_BACKGROUNDS = ['city', 'nature', 'space', 'fantasy'];
    private const PANEL_COUNT = 4;

    public function __construct(Logger $logger, Config $config, ComicGenerator $comicGenerator, StateManager $stateManager)
    {
        $this->logger = $logger;
        $this->config = $config;
        $this->comicGenerator = $comicGenerator;
        $this->stateManager = $stateManager;
    }

    /**
     * Handle incoming API request
     */
    public function handleRequest(): array
    {
        try {
            // Get request body
            $input = json_decode(file_get_contents('php://input'), true);

            if (!$input) {
                throw new Exception('Invalid request body');
            }

            // Check if this is a status check request
            if (isset($_GET['action']) && $_GET['action'] === 'status') {
                return $this->handleStatusRequest($_GET['jobId'] ?? null);
            }

            // Validate required fields
            if (empty($input['story'])) {
                throw new Exception('Story is required');
            }
            if (empty($input['characters'])) {
                throw new Exception('At least one character is required');
            }
            if (empty($input['style'])) {
                throw new Exception('Style is required');
            }
            if (empty($input['background'])) {
                throw new Exception('Background is required');
            }

            // Return result directly without wrapping
            return $this->handleGenerateRequest($input);
        } catch (Exception $e) {
            $this->logger->error('Request handling failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Handle status check request
     */
    private function handleStatusRequest(?string $jobId): array
    {
        if (!$jobId) {
            throw new Exception('Job ID is required for status check');
        }

        $state = $this->stateManager->getStripState($jobId);
        if (!$state) {
            throw new Exception('Job not found');
        }

        return [
            'success' => true,
            'data' => [
                'job_id' => $jobId,
                'status' => $state['status'],
                'phase' => $state['phase'],
                'progress' => $state['progress'],
                'error' => $state['error'] ?? null,
                'output_url' => $state['output_url'] ?? null
            ]
        ];
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

            // Initialize comic strip generation
            $result = $this->comicGenerator->initializeComicStrip(
                $input['story'],
                $input['characters'],
                [
                    'style' => $input['style'],
                    'background' => $input['background'],
                    'panel_count' => self::PANEL_COUNT,
                    'generation_params' => $this->prepareGenerationOptions($input)
                ]
            );

            if (!$result['success']) {
                throw new Exception($result['error'] ?? 'Failed to initialize comic generation');
            }

            return [
                'success' => true,
                'data' => [
                    'job_id' => $result['data']['id'],
                    'status' => $result['data']['status'],
                    'message' => $result['data']['message']
                ]
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

        // Get the generated path from config
        $generatedPath = basename($this->config->getPath('output'));

        // Validate image format
        $isBase64 = preg_match('/^data:image\/(\w+);base64,/', $character['image']);
        $isUrl = filter_var($character['image'], FILTER_VALIDATE_URL);
        $isGeneratedPath = strpos($character['image'], '/' . $generatedPath . '/') !== false;

        if (!$isBase64 && !$isUrl && !$isGeneratedPath) {
            $this->logger->error('Invalid character image format', [
                'character_index' => $index,
                'image_data' => substr($character['image'], 0, 100) . '...',
                'is_base64' => $isBase64,
                'is_url' => $isUrl,
                'is_generated' => $isGeneratedPath,
                'expected_path' => $generatedPath
            ]);
            throw new RuntimeException("Character image at index $index must be base64 encoded, a valid URL, or a generated image path");
        }

        // Log validation success
        $this->logger->info('Character validation passed', [
            'character_index' => $index,
            'character_id' => $character['id'],
            'image_type' => $isBase64 ? 'base64' : ($isUrl ? 'url' : 'generated'),
            'generated_path' => $isGeneratedPath ? $generatedPath : null
        ]);
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
                    'background_detail' => match ($input['background']) {
                        'city' => 'high',
                        'nature' => 'high',
                        'space' => 'medium',
                        'fantasy' => 'high'
                    },
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
