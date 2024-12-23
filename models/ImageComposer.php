<?php

require_once __DIR__ . '/../interfaces/LoggerInterface.php';
require_once __DIR__ . '/Config.php';

class ImageComposer
{
    private LoggerInterface $logger;
    private Config $config;
    private string $outputDir;

    public function __construct(LoggerInterface $logger, Config $config)
    {
        $this->logger = $logger;
        $this->config = $config;
        $this->outputDir = $this->config->getOutputPath();
    }

    /**
     * Compose a comic panel with intelligent character placement
     *
     * @param array $images Array of character images with roles and attributes
     * @param array $sceneContext Additional context about the scene
     * @param array $userPositions Optional user-defined positions
     * @return string Path to the composed panel
     * @throws Exception on failure
     */
    public function composePanel(array $images, array $sceneContext = [], array $userPositions = []): string
    {
        $panelId = $sceneContext['panel_id'] ?? null;
        $this->logger->error("DEBUG_VERIFY - composePanel received images", [
            'image_count' => count($images),
            'panel_id' => $panelId,
            'images' => array_map(function ($url, $index) {
                return [
                    'index' => $index,
                    'url' => $url,
                    'is_replicate_url' => strpos($url, 'replicate.delivery') !== false,
                    'is_valid_url' => filter_var($url, FILTER_VALIDATE_URL) !== false
                ];
            }, $images, array_keys($images))
        ]);

        try {
            // Update state if panel ID is provided
            if ($panelId) {
                $stateFile = $this->config->getTempPath() . "state_{$panelId}.json";
                if (file_exists($stateFile)) {
                    $state = json_decode(file_get_contents($stateFile), true);
                    $state['composition_status'] = StateManager::STATE_PANELS_COMPOSING;
                    $state['updated_at'] = time();
                    file_put_contents($stateFile, json_encode($state));
                }
            }

            // Validate all images are valid URLs
            $validImages = array_filter($images, function ($url) {
                return filter_var($url, FILTER_VALIDATE_URL) !== false;
            });

            if (count($validImages) !== count($images)) {
                $this->logger->error("Invalid image URLs found", [
                    'valid_count' => count($validImages),
                    'total_count' => count($images),
                    'invalid_urls' => array_diff($images, $validImages)
                ]);

                // Update state with error
                if ($panelId) {
                    $this->updateStateWithError($panelId, "Some images have invalid URLs");
                }

                throw new Exception("Some images have invalid URLs");
            }

            // Create a new image with transparent background
            $width = 1024;  // Standard width for the panel
            $height = 1024; // Standard height for the panel
            $panel = imagecreatetruecolor($width, $height);

            // Enable alpha blending and save alpha channel
            imagealphablending($panel, true);
            imagesavealpha($panel, true);

            // Fill with transparent background
            $transparent = imagecolorallocatealpha($panel, 0, 0, 0, 127);
            imagefill($panel, 0, 0, $transparent);

            // Calculate positions for each character
            $positions = $this->calculateCharacterPositions($validImages, $sceneContext, $width, $height);

            // Add each character to the panel
            foreach ($validImages as $index => $imageUrl) {
                $this->logger->error("DEBUG_VERIFY - Adding image to panel", [
                    'index' => $index,
                    'image_url' => $imageUrl,
                    'position' => $positions[$index] ?? 'unknown',
                    'is_replicate_url' => strpos($imageUrl, 'replicate.delivery') !== false
                ]);

                // Download and process the image
                $characterImage = $this->loadImageFromUrl($imageUrl);
                if (!$characterImage) {
                    $this->logger->error("Failed to load character image", [
                        'url' => $imageUrl,
                        'index' => $index
                    ]);

                    // Update state with error
                    if ($panelId) {
                        $this->updateStateWithError($panelId, "Failed to load character image: $imageUrl");
                    }

                    continue;
                }

                // Get image dimensions
                $srcWidth = imagesx($characterImage);
                $srcHeight = imagesy($characterImage);

                // Calculate target dimensions (e.g., 1/3 of panel width)
                $targetWidth = $width / 3;
                $targetHeight = $height / 3;

                // Calculate scaling factor to maintain aspect ratio
                $scale = min($targetWidth / $srcWidth, $targetHeight / $srcHeight);
                $newWidth = $srcWidth * $scale;
                $newHeight = $srcHeight * $scale;

                // Get position from calculated positions array
                $position = $positions[$index] ?? ['x' => 0, 'y' => 0];
                $x = $position['x'] - ($newWidth / 2);  // Center horizontally
                $y = $position['y'] - ($newHeight / 2); // Center vertically

                $this->logger->error("DEBUG_VERIFY - Image dimensions for panel", [
                    'index' => $index,
                    'original_width' => $srcWidth,
                    'original_height' => $srcHeight,
                    'new_width' => $newWidth,
                    'new_height' => $newHeight,
                    'position_x' => $x,
                    'position_y' => $y
                ]);

                // Copy and resize the character onto the panel
                imagecopyresampled(
                    $panel,
                    $characterImage,
                    $x,
                    $y,
                    0,
                    0,
                    $newWidth,
                    $newHeight,
                    $srcWidth,
                    $srcHeight
                );

                // Clean up
                imagedestroy($characterImage);
            }

            // Save the composed panel
            $outputPath = $this->config->getTempPath() . 'composed_panel_' . uniqid() . '.png';
            imagepng($panel, $outputPath);
            imagedestroy($panel);

            // Update state with success
            if ($panelId) {
                $stateFile = $this->config->getTempPath() . "state_{$panelId}.json";
                if (file_exists($stateFile)) {
                    $state = json_decode(file_get_contents($stateFile), true);
                    $state['composition_status'] = StateManager::STATE_COMPLETE;
                    $state['updated_at'] = time();
                    $state['composed_panel_path'] = $outputPath;
                    file_put_contents($stateFile, json_encode($state));
                }
            }

            $this->logger->error("DEBUG_VERIFY - Panel composition completed", [
                'output_path' => $outputPath,
                'file_exists' => file_exists($outputPath),
                'file_size' => file_exists($outputPath) ? filesize($outputPath) : 0,
                'images_used' => $validImages
            ]);

            return $outputPath;
        } catch (Exception $e) {
            $this->logger->error("Panel composition failed", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Update state with error
            if ($panelId) {
                $this->updateStateWithError($panelId, $e->getMessage());
            }

            throw $e;
        }
    }

    /**
     * Update state file with error information
     */
    private function updateStateWithError(string $panelId, string $error): void
    {
        $stateFile = $this->config->getTempPath() . "state_{$panelId}.json";
        if (file_exists($stateFile)) {
            $state = json_decode(file_get_contents($stateFile), true);
            $state['composition_status'] = StateManager::STATE_FAILED;
            $state['composition_error'] = $error;
            $state['composition_failed_at'] = time();
            file_put_contents($stateFile, json_encode($state));
        }
    }

    /**
     * Determine the type of image from its data
     */
    private function determineImageType(string $imageData): string
    {
        if (strpos($imageData, 'replicate.delivery') !== false) {
            return 'replicate_url';
        } elseif (filter_var($imageData, FILTER_VALIDATE_URL)) {
            return 'external_url';
        } elseif (strpos($imageData, 'data:image') === 0) {
            return 'base64';
        } elseif (file_exists($imageData)) {
            return 'local_file';
        }
        return 'unknown';
    }

    /**
     * Add an image to the panel at specified position
     * @param \GdImage $panel GD image resource of the panel
     * @param string $imageData URL or base64 data of the image
     * @param int $x X position
     * @param int $y Y position
     * @param int $width Desired width
     * @param int $height Desired height
     * @throws Exception if image processing fails
     */
    private function addImageToPanel(\GdImage $panel, string $imageData, int $x, int $y, int $width, int $height): void
    {
        try {
            $imageType = $this->determineImageType($imageData);
            $this->logger->info("addImageToPanel: Processing image", [
                'type' => $imageType,
                'position' => ['x' => $x, 'y' => $y],
                'dimensions' => ['width' => $width, 'height' => $height],
                'url_or_path' => $imageType === 'base64' ? 'base64_data' : $imageData
            ]);

            // Handle different image sources
            if (filter_var($imageData, FILTER_VALIDATE_URL)) {
                // For remote URLs (like replicate.delivery)
                $imageContent = @file_get_contents($imageData);
                if ($imageContent === false) {
                    throw new Exception("Failed to download image from URL: $imageData");
                }
            } elseif (strpos($imageData, 'data:image') === 0) {
                // For base64 encoded images
                $imageContent = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $imageData));
                if ($imageContent === false) {
                    throw new Exception("Failed to decode base64 image data");
                }
            } elseif (file_exists($imageData)) {
                // For local file paths
                $imageContent = file_get_contents($imageData);
                if ($imageContent === false) {
                    throw new Exception("Failed to read local image file: $imageData");
                }
            } else {
                throw new Exception("Invalid image data source: $imageData");
            }

            // Create image from content
            $image = @imagecreatefromstring($imageContent);
            if (!$image) {
                throw new Exception("Failed to create image from data");
            }

            // Get original image dimensions
            $origWidth = imagesx($image);
            $origHeight = imagesy($image);

            // Create a temporary image for alpha handling
            $temp = imagecreatetruecolor($width, $height);
            if (!$temp) {
                imagedestroy($image);
                throw new Exception("Failed to create temporary image");
            }

            // Enable alpha blending
            imagealphablending($temp, true);
            imagesavealpha($temp, true);

            // Fill with transparent background
            $transparent = imagecolorallocatealpha($temp, 0, 0, 0, 127);
            imagefill($temp, 0, 0, $transparent);

            // Copy and resize the image
            if (!imagecopyresampled(
                $temp,
                $image,
                0,
                0,
                0,
                0,
                $width,
                $height,
                $origWidth,
                $origHeight
            )) {
                imagedestroy($image);
                imagedestroy($temp);
                throw new Exception("Failed to resize image");
            }

            // Copy the temporary image to the panel
            if (!imagecopy(
                $panel,
                $temp,
                $x,
                $y,
                0,
                0,
                $width,
                $height
            )) {
                imagedestroy($image);
                imagedestroy($temp);
                throw new Exception("Failed to copy image to panel");
            }

            // Clean up
            imagedestroy($image);
            imagedestroy($temp);

            $this->logger->info("Successfully added image to panel", [
                'position' => ['x' => $x, 'y' => $y],
                'dimensions' => ['width' => $width, 'height' => $height]
            ]);
        } catch (Exception $e) {
            $this->logger->error("Failed to add image to panel", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Calculate positions for characters in the panel
     * @param array $images Array of character images
     * @param array $sceneContext Scene context information
     * @param int $panelWidth Width of the panel
     * @param int $panelHeight Height of the panel
     * @return array Array of character positions
     */
    private function calculateCharacterPositions(array $images, array $sceneContext, int $panelWidth, int $panelHeight): array
    {
        $positions = [];
        $numCharacters = count($images);

        if ($numCharacters === 0) {
            return $positions;
        }

        // Default character size (adjustable based on panel size)
        $defaultWidth = (int)($panelWidth * 0.3);
        $defaultHeight = (int)($panelHeight * 0.4);

        // Calculate positions based on number of characters
        foreach ($images as $index => $imageData) {
            $x = ($panelWidth - $defaultWidth) * ($index + 1) / ($numCharacters + 1);
            $y = ($panelHeight - $defaultHeight) * 0.6; // Place characters in lower 60% of panel

            $positions[$index] = [
                'x' => (int)$x,
                'y' => (int)$y,
                'width' => $defaultWidth,
                'height' => $defaultHeight
            ];
        }

        return $positions;
    }

    /**
     * Load an image from a URL and return a GD image resource
     * @param string $url URL of the image to load
     * @return \GdImage|false GD image resource or false on failure
     */
    private function loadImageFromUrl(string $url): \GdImage|false
    {
        try {
            $this->logger->error("DEBUG_VERIFY - Loading image from URL", [
                'url' => $url,
                'is_replicate_url' => strpos($url, 'replicate.delivery') !== false
            ]);

            // Download image content
            $imageContent = @file_get_contents($url);
            if ($imageContent === false) {
                $this->logger->error("Failed to download image", [
                    'url' => $url,
                    'error' => error_get_last()['message'] ?? 'Unknown error'
                ]);
                return false;
            }

            // Create image from content
            $image = @imagecreatefromstring($imageContent);
            if (!$image) {
                $this->logger->error("Failed to create image from content", [
                    'url' => $url,
                    'content_length' => strlen($imageContent)
                ]);
                return false;
            }

            $this->logger->error("DEBUG_VERIFY - Successfully loaded image", [
                'url' => $url,
                'width' => imagesx($image),
                'height' => imagesy($image)
            ]);

            return $image;
        } catch (Exception $e) {
            $this->logger->error("Image loading failed", [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Compose a complete comic strip from multiple panels
     * @param array $panels Array of panel image paths
     * @param string $stripId Comic strip ID
     * @param array $options Layout options
     * @return string Path to the composed comic strip
     * @throws Exception on failure
     */
    public function composeStrip(array $panels, string $stripId, array $options = []): string
    {
        $this->logger->info("Starting comic strip composition", [
            'panel_count' => count($panels),
            'strip_id' => $stripId,
            'options' => $options
        ]);

        try {
            // Get strip dimensions from config
            $stripConfig = $this->config->get('comic_strip');
            $maxWidth = $stripConfig['strip_dimensions']['max_width'];
            $maxHeight = $stripConfig['strip_dimensions']['max_height'];
            $panelGap = $stripConfig['panel_gap'];
            $stripPadding = $stripConfig['strip_padding'];

            // Calculate optimal layout
            $layout = $this->calculateStripLayout(
                count($panels),
                $stripConfig['panel_dimensions']['width'],
                $stripConfig['panel_dimensions']['height'],
                $maxWidth,
                $maxHeight,
                $panelGap,
                $stripPadding
            );

            // Create the strip canvas
            $strip = imagecreatetruecolor($layout['width'], $layout['height']);
            imagealphablending($strip, true);
            imagesavealpha($strip, true);

            // Fill with white background
            $white = imagecolorallocate($strip, 255, 255, 255);
            imagefill($strip, 0, 0, $white);

            // Add each panel to the strip
            foreach ($panels as $index => $panelPath) {
                if (!file_exists($panelPath)) {
                    throw new RuntimeException("Panel image not found: $panelPath");
                }

                // Calculate panel position
                $position = $this->getPanelPosition($index, $layout, $stripConfig);

                // Load and add panel
                $panelImage = $this->loadImage($panelPath);
                if (!$panelImage) {
                    throw new RuntimeException("Failed to load panel image: $panelPath");
                }

                // Copy panel to strip
                imagecopy(
                    $strip,
                    $panelImage,
                    $position['x'],
                    $position['y'],
                    0,
                    0,
                    imagesx($panelImage),
                    imagesy($panelImage)
                );

                imagedestroy($panelImage);
            }

            // Save the composed strip
            $outputPath = $this->outputDir . "/strip_{$stripId}.png";
            imagepng($strip, $outputPath, 9); // Maximum compression
            imagedestroy($strip);

            // Update strip state
            $this->updateStripState($stripId, [
                'status' => 'completed',
                'output_path' => $outputPath,
                'completed_at' => time()
            ]);

            return $outputPath;
        } catch (Exception $e) {
            $this->logger->error("Strip composition failed", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Update strip state with error
            $this->updateStripState($stripId, [
                'status' => 'failed',
                'error' => $e->getMessage(),
                'failed_at' => time()
            ]);

            throw $e;
        }
    }

    /**
     * Calculate optimal layout for the comic strip
     * @param int $panelCount Number of panels
     * @param int $panelWidth Single panel width
     * @param int $panelHeight Single panel height
     * @param int $maxWidth Maximum strip width
     * @param int $maxHeight Maximum strip height
     * @param int $gap Gap between panels
     * @param int $padding Strip padding
     * @return array Layout information
     */
    private function calculateStripLayout(
        int $panelCount,
        int $panelWidth,
        int $panelHeight,
        int $maxWidth,
        int $maxHeight,
        int $gap,
        int $padding
    ): array {
        // Calculate number of rows and columns
        $maxPanelsPerRow = floor(($maxWidth - 2 * $padding + $gap) / ($panelWidth + $gap));
        $rows = ceil($panelCount / $maxPanelsPerRow);
        $cols = min($maxPanelsPerRow, $panelCount);

        // Calculate actual dimensions
        $width = 2 * $padding + $cols * $panelWidth + ($cols - 1) * $gap;
        $height = 2 * $padding + $rows * $panelHeight + ($rows - 1) * $gap;

        return [
            'width' => $width,
            'height' => $height,
            'rows' => $rows,
            'cols' => $cols,
            'panel_width' => $panelWidth,
            'panel_height' => $panelHeight,
            'gap' => $gap,
            'padding' => $padding
        ];
    }

    /**
     * Calculate position for a panel in the strip
     * @param int $index Panel index
     * @param array $layout Layout information
     * @param array $config Strip configuration
     * @return array Position coordinates
     */
    private function getPanelPosition(int $index, array $layout, array $config): array
    {
        $row = floor($index / $layout['cols']);
        $col = $index % $layout['cols'];

        return [
            'x' => $layout['padding'] + $col * ($layout['panel_width'] + $layout['gap']),
            'y' => $layout['padding'] + $row * ($layout['panel_height'] + $layout['gap'])
        ];
    }

    /**
     * Load an image from a file path
     * @param string $path Image file path
     * @return \GdImage|false GD image resource or false on failure
     */
    private function loadImage(string $path): \GdImage|false
    {
        $type = exif_imagetype($path);
        switch ($type) {
            case IMAGETYPE_JPEG:
                return imagecreatefromjpeg($path);
            case IMAGETYPE_PNG:
                return imagecreatefrompng($path);
            case IMAGETYPE_GIF:
                return imagecreatefromgif($path);
            default:
                return false;
        }
    }

    /**
     * Compose a panel image with consistent styling
     * @param array $panelComposition Array of character data with positions
     * @param string $sceneDescription Description of the scene
     * @param array $options Panel options including style parameters
     * @return string Path to the composed panel image
     * @throws Exception on failure
     */
    public function composePanelImage(array $panelComposition, string $sceneDescription, array $options = []): string
    {
        $this->logger->info("Starting panel composition", [
            'character_count' => count($panelComposition),
            'scene_length' => strlen($sceneDescription),
            'style_options' => $options['style'] ?? []
        ]);

        try {
            // Get panel dimensions from options or use defaults
            $width = $options['style']['width'] ?? 1024;
            $height = $options['style']['height'] ?? 1024;

            // Build background prompt
            $style = $options['style'] ?? [];
            $backgroundPrompt = $this->buildBackgroundPrompt($sceneDescription, $style);

            // Call Replicate SDXL API to generate background
            $response = $this->makeReplicateRequest('predictions', [
                'version' => $this->config->get('replicate.models.sdxl.version'),
                'input' => [
                    'prompt' => $backgroundPrompt,
                    'negative_prompt' => 'text, watermark, logo, signature, characters, people, blurry, low quality',
                    'num_inference_steps' => 50,
                    'guidance_scale' => 7.5,
                    'width' => $width,
                    'height' => $height,
                    'scheduler' => 'DPM++ 2M Karras',
                    'refine' => 'expert_ensemble_refiner',
                    'high_noise_frac' => 0.8,
                    'refine_steps' => 25
                ],
                'webhook' => $this->config->getBaseUrl() . '/webhook.php',
                'webhook_events_filter' => ['completed']
            ]);

            // Poll for completion
            $predictionId = $response['id'];
            $maxAttempts = 60; // 5 minutes with 5-second intervals
            $attempt = 0;
            $backgroundUrl = null;

            while ($attempt < $maxAttempts) {
                $status = $this->makeReplicateRequest("predictions/{$predictionId}", [], 'GET');

                if ($status['status'] === 'succeeded') {
                    $backgroundUrl = $status['output'][0] ?? null;
                    break;
                } elseif ($status['status'] === 'failed') {
                    throw new Exception($status['error'] ?? 'Background generation failed');
                }

                $attempt++;
                sleep(5);
            }

            if (!$backgroundUrl) {
                throw new Exception('Background generation timed out or failed to produce output');
            }

            // Load the generated background
            $panel = $this->loadImageFromUrl($backgroundUrl);
            if (!$panel) {
                throw new Exception('Failed to load generated background image');
            }

            // Sort characters by z-index for proper layering
            uasort($panelComposition, function ($a, $b) {
                return ($a['position']['z_index'] ?? 1) <=> ($b['position']['z_index'] ?? 1);
            });

            // Process each character
            foreach ($panelComposition as $charId => $charData) {
                if (!isset($charData['image'])) {
                    throw new Exception("Character $charId is missing image data");
                }

                // Load character image
                $characterImage = $this->loadImageFromUrl($charData['image']);
                if (!$characterImage) {
                    throw new Exception("Failed to load image for character $charId");
                }

                // Apply style adjustments
                $characterImage = $this->applyStyleAdjustments(
                    $characterImage,
                    $style,
                    $charData['position']['scale'] ?? 1.0
                );

                // Get character dimensions
                $srcWidth = imagesx($characterImage);
                $srcHeight = imagesy($characterImage);

                // Calculate position
                $position = $charData['position'];
                $x = $position['x'] - ($srcWidth / 2);  // Center horizontally
                $y = $position['y'] - ($srcHeight / 2); // Center vertically

                // Add character to panel
                imagecopy(
                    $panel,
                    $characterImage,
                    $x,
                    $y,
                    0,
                    0,
                    $srcWidth,
                    $srcHeight
                );

                imagedestroy($characterImage);
            }

            // Apply panel-wide style effects
            $this->applyPanelEffects($panel, $style);

            // Save the composed panel
            $outputPath = $this->config->getTempPath() . 'composed_panel_' . uniqid() . '.png';
            imagepng($panel, $outputPath);
            imagedestroy($panel);

            return $outputPath;
        } catch (Exception $e) {
            $this->logger->error("Panel composition failed", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Build background prompt for SDXL
     */
    private function buildBackgroundPrompt(string $sceneDescription, array $style): string
    {
        $styleDesc = match ($style['name'] ?? 'default') {
            'manga' => 'manga style, black and white, high contrast, detailed linework',
            'comic' => 'western comic book style, vibrant colors, dynamic shading',
            'cartoon' => 'cartoon style, simple shapes, bold colors',
            default => 'digital art style, detailed, high quality'
        };

        $background = $style['background'] ?? 'neutral';
        $backgroundDesc = match ($background) {
            'city' => 'urban cityscape background, buildings, streets',
            'nature' => 'natural landscape background, trees, mountains',
            'indoor' => 'indoor room background, walls, furniture',
            'fantasy' => 'fantasy environment background, magical elements',
            default => 'simple gradient background'
        };

        $mood = $style['consistency_anchors']['panel_mood'] ?? 'neutral';
        $moodDesc = match ($mood) {
            'dramatic' => 'dramatic lighting, high contrast',
            'bright' => 'bright and cheerful atmosphere',
            'dim' => 'dark and moody atmosphere',
            'warm' => 'warm color tones',
            'cool' => 'cool color tones',
            default => 'balanced lighting'
        };

        // Enhanced prompt engineering for SDXL
        return "Background scene: $sceneDescription. $styleDesc, $backgroundDesc, $moodDesc, no characters or people, establishing shot, masterpiece, best quality, highly detailed, sharp focus, 8k resolution";
    }

    /**
     * Make HTTP request to Replicate API
     */
    private function makeReplicateRequest(string $endpoint, array $data = [], string $method = 'POST'): array
    {
        $url = 'https://api.replicate.com/v1/' . $endpoint;
        $token = $this->config->get('replicate.api_token');

        $this->logger->debug('Making Replicate API request', [
            'endpoint' => $endpoint,
            'method' => $method,
            'has_webhook' => isset($data['webhook']),
            'webhook_url' => $data['webhook'] ?? null,
            'webhook_events' => $data['webhook_events_filter'] ?? []
        ]);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Token ' . $token,
            'Content-Type: application/json'
        ]);

        if ($method === 'POST') {
            // Get base model parameters from config
            $modelParams = $this->config->get('replicate.models.sdxl.params');

            // Merge with provided data, allowing overrides
            $data['input'] = array_merge($modelParams, $data['input'] ?? []);

            // Add negative prompts from config
            $negativePrompts = $this->config->get('negative_prompts');
            $data['input']['negative_prompt'] = implode(', ', $negativePrompts);

            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 && $httpCode !== 201) {
            $this->logger->error('Replicate API request failed', [
                'endpoint' => $endpoint,
                'method' => $method,
                'http_code' => $httpCode,
                'response' => $response
            ]);
            throw new Exception('Replicate API request failed: ' . $response);
        }

        $responseData = json_decode($response, true);
        $this->logger->debug('Replicate API response received', [
            'endpoint' => $endpoint,
            'method' => $method,
            'http_code' => $httpCode,
            'prediction_id' => $responseData['id'] ?? null,
            'status' => $responseData['status'] ?? null
        ]);

        return $responseData;
    }

    /**
     * Apply style adjustments to a character image
     * @param \GdImage $image Character image resource
     * @param array $style Style options
     * @param float $scale Character scale factor
     * @return \GdImage Processed image
     */
    private function applyStyleAdjustments(\GdImage $image, array $style, float $scale): \GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);

        // Apply scaling
        $newWidth = (int)($width * $scale);
        $newHeight = (int)($height * $scale);
        $scaled = imagecreatetruecolor($newWidth, $newHeight);
        imagealphablending($scaled, true);
        imagesavealpha($scaled, true);
        imagecopyresampled($scaled, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($image);

        // Apply color adjustments based on style
        $this->adjustImageColors($scaled, $style);

        return $scaled;
    }

    /**
     * Apply panel-wide effects for style consistency
     * @param \GdImage $panel Panel image resource
     * @param array $style Style options
     */
    private function applyPanelEffects(\GdImage $panel, array $style): void
    {
        // Apply lighting based on mood and scheme
        $lightingScheme = $style['consistency_anchors']['lighting_scheme'] ?? 'neutral';
        $this->applyLighting($panel, $lightingScheme);

        // Apply art style effects
        $artStyle = $style['art_style'] ?? [];
        $this->applyArtStyle($panel, $artStyle);
    }

    /**
     * Apply lighting effects to the panel
     * @param \GdImage $panel Panel image resource
     * @param string $scheme Lighting scheme
     */
    private function applyLighting(\GdImage $panel, string $scheme): void
    {
        $width = imagesx($panel);
        $height = imagesy($panel);

        switch ($scheme) {
            case 'dramatic':
                // Add vignette effect
                $this->addVignette($panel, 0.3);
                break;
            case 'bright':
                // Increase brightness
                imagefilter($panel, IMG_FILTER_BRIGHTNESS, 10);
                break;
            case 'dim':
                // Decrease brightness
                imagefilter($panel, IMG_FILTER_BRIGHTNESS, -10);
                break;
            case 'warm':
                // Add warm color overlay
                imagefilter($panel, IMG_FILTER_COLORIZE, 10, 5, 0);
                break;
            case 'cool':
                // Add cool color overlay
                imagefilter($panel, IMG_FILTER_COLORIZE, 0, 5, 10);
                break;
        }
    }

    /**
     * Apply art style effects to the panel
     * @param \GdImage $panel Panel image resource
     * @param array $style Art style parameters
     */
    private function applyArtStyle(\GdImage $panel, array $style): void
    {
        // Apply line weight effect
        $lineWeight = $style['line_weight'] ?? 'medium';
        switch ($lineWeight) {
            case 'bold':
                imagefilter($panel, IMG_FILTER_EDGEDETECT);
                break;
            case 'light':
                imagefilter($panel, IMG_FILTER_SMOOTH, 5);
                break;
        }

        // Apply color palette adjustments
        $colorPalette = $style['color_palette'] ?? 'vibrant';
        switch ($colorPalette) {
            case 'vibrant':
                imagefilter($panel, IMG_FILTER_CONTRAST, -10);
                imagefilter($panel, IMG_FILTER_BRIGHTNESS, 5);
                break;
            case 'muted':
                imagefilter($panel, IMG_FILTER_CONTRAST, -20);
                imagefilter($panel, IMG_FILTER_GRAYSCALE);
                imagefilter($panel, IMG_FILTER_COLORIZE, 10, 10, 10);
                break;
            case 'noir':
                imagefilter($panel, IMG_FILTER_GRAYSCALE);
                imagefilter($panel, IMG_FILTER_CONTRAST, 10);
                break;
        }
    }

    /**
     * Add vignette effect to panel
     * @param \GdImage $panel Panel image resource
     * @param float $intensity Effect intensity (0-1)
     */
    private function addVignette(\GdImage $panel, float $intensity): void
    {
        $width = imagesx($panel);
        $height = imagesy($panel);
        $centerX = $width / 2;
        $centerY = $height / 2;
        $maxDistance = sqrt($centerX * $centerX + $centerY * $centerY);

        // Create vignette overlay
        $vignette = imagecreatetruecolor($width, $height);
        imagealphablending($vignette, true);
        imagesavealpha($vignette, true);

        // Fill vignette with gradient
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $distance = sqrt(pow($x - $centerX, 2) + pow($y - $centerY, 2));
                $alpha = min(127, (int)(($distance / $maxDistance) * 127 * $intensity));
                $color = imagecolorallocatealpha($vignette, 0, 0, 0, 127 - $alpha);
                imagesetpixel($vignette, $x, $y, $color);
            }
        }

        // Apply vignette to panel
        imagecopy($panel, $vignette, 0, 0, 0, 0, $width, $height);
        imagedestroy($vignette);
    }

    /**
     * Adjust image colors based on style settings
     * @param \GdImage $image Image resource
     * @param array $style Style options
     */
    private function adjustImageColors(\GdImage $image, array $style): void
    {
        $artStyle = $style['art_style'] ?? [];
        $colorPalette = $artStyle['color_palette'] ?? 'vibrant';

        switch ($colorPalette) {
            case 'vibrant':
                imagefilter($image, IMG_FILTER_CONTRAST, -10);
                imagefilter($image, IMG_FILTER_BRIGHTNESS, 5);
                break;
            case 'muted':
                imagefilter($image, IMG_FILTER_CONTRAST, -20);
                imagefilter($image, IMG_FILTER_GRAYSCALE);
                imagefilter($image, IMG_FILTER_COLORIZE, 10, 10, 10);
                break;
            case 'noir':
                imagefilter($image, IMG_FILTER_GRAYSCALE);
                imagefilter($image, IMG_FILTER_CONTRAST, 10);
                break;
        }
    }

    /**
     * Centralized state management for panel composition
     * @param string $panelId Panel ID
     * @param array $update State update data
     * @return array Updated state
     */
    private function updatePanelState(string $panelId, array $update): array
    {
        $stateFile = $this->config->getTempPath() . "state_{$panelId}.json";
        $state = [];

        if (file_exists($stateFile)) {
            $state = json_decode(file_get_contents($stateFile), true) ?? [];
        }

        // Merge updates with existing state
        $state = array_merge($state, $update, [
            'updated_at' => time()
        ]);

        // Ensure required fields
        $state['id'] = $state['id'] ?? $panelId;
        $state['status'] = $state['status'] ?? 'initializing';

        // Write state to file
        file_put_contents($stateFile, json_encode($state));

        $this->logger->info("Panel state updated", [
            'panel_id' => $panelId,
            'status' => $state['status'],
            'update' => $update
        ]);

        return $state;
    }

    /**
     * Centralized state management for strip composition
     * @param string $stripId Strip ID
     * @param array $update State update data
     * @return array Updated state
     */
    private function updateStripState(string $stripId, array $update): array
    {
        $stateFile = $this->config->getTempPath() . "strip_state_{$stripId}.json";
        $state = [];

        if (file_exists($stateFile)) {
            $state = json_decode(file_get_contents($stateFile), true) ?? [];
        }

        // Merge updates with existing state
        $state = array_merge($state, $update, [
            'updated_at' => time()
        ]);

        // Ensure required fields
        $state['id'] = $state['id'] ?? $stripId;
        $state['status'] = $state['status'] ?? 'initializing';
        $state['panels'] = $state['panels'] ?? [];

        // Write state to file
        file_put_contents($stateFile, json_encode($state));

        $this->logger->info("Strip state updated", [
            'strip_id' => $stripId,
            'status' => $state['status'],
            'update' => $update
        ]);

        return $state;
    }

    /**
     * Handle composition error and update state
     * @param string $id Panel or strip ID
     * @param string $type 'panel' or 'strip'
     * @param string $error Error message
     * @param Exception|null $exception Optional exception for logging
     */
    private function handleCompositionError(string $id, string $type, string $error, ?Exception $exception = null): void
    {
        $update = [
            'status' => 'failed',
            'error' => $error,
            'failed_at' => time()
        ];

        if ($type === 'panel') {
            $this->updatePanelState($id, $update);
        } else {
            $this->updateStripState($id, $update);
        }

        if ($exception) {
            $this->logger->error("Composition failed", [
                'type' => $type,
                'id' => $id,
                'error' => $error,
                'trace' => $exception->getTraceAsString()
            ]);
        }
    }

    /**
     * Initialize panel composition state
     * @param string $panelId Panel ID
     * @param array $options Panel options
     * @return array Initial state
     */
    private function initializePanelState(string $panelId, array $options = []): array
    {
        return $this->updatePanelState($panelId, [
            'id' => $panelId,
            'strip_id' => $options['strip_id'] ?? null,
            'panel_index' => $options['panel_index'] ?? null,
            'status' => 'initializing',
            'started_at' => time(),
            'style_options' => $options['style'] ?? []
        ]);
    }

    /**
     * Initialize strip composition state
     * @param string $stripId Strip ID
     * @param array $options Strip options
     * @return array Initial state
     */
    private function initializeStripState(string $stripId, array $options = []): array
    {
        return $this->updateStripState($stripId, [
            'id' => $stripId,
            'status' => 'initializing',
            'started_at' => time(),
            'panels' => [],
            'options' => $options,
            'progress' => 0
        ]);
    }

    /**
     * Update strip progress based on panel states
     * @param string $stripId Strip ID
     * @param array $state Current strip state
     */
    private function updateStripProgress(string $stripId, array $state): void
    {
        if (empty($state['panels'])) {
            return;
        }

        $completedCount = count(array_filter($state['panels'], function ($panel) {
            return $panel['status'] === 'completed';
        }));

        $progress = ($completedCount / count($state['panels'])) * 100;

        $this->updateStripState($stripId, [
            'progress' => round($progress, 2),
            'completed_count' => $completedCount,
            'total_count' => count($state['panels'])
        ]);
    }

    /**
     * Generate background using SDXL
     * @param string $description Scene description
     * @param array $style Style options
     * @return array Replicate API response
     */
    public function generateBackground(string $description, array $style): array
    {
        try {
            // Build background prompt
            $backgroundPrompt = $this->buildBackgroundPrompt($description, $style);

            // Get model version from config
            $modelVersion = $this->config->get('replicate.models.sdxl.version');

            // Get base parameters from config
            $baseParams = $this->config->get('replicate.models.sdxl.params');

            // Prepare request data
            $requestData = [
                'version' => $modelVersion,
                'input' => array_merge($baseParams, [
                    'prompt' => $backgroundPrompt,
                    'negative_prompt' => 'text, watermark, logo, signature, characters, people, blurry, low quality, deformed, disfigured, mutated, bad anatomy',
                    'width' => $style['width'] ?? $baseParams['width'],
                    'height' => $style['height'] ?? $baseParams['height'],
                    'apply_watermark' => false,
                    'scheduler' => "DPM++ 2M Karras",
                    'refine' => "expert_ensemble_refiner",
                    'high_noise_frac' => 0.8,
                    'refine_steps' => 25,
                    'prompt_strength' => 0.8
                ]),
                'webhook' => $this->config->get('app.base_url') . '/webhook.php',
                'webhook_events_filter' => ['completed']
            ];

            $this->logger->info('Generating background with SDXL', [
                'prompt' => $backgroundPrompt,
                'model_version' => $modelVersion,
                'parameters' => $requestData['input']
            ]);

            // Make API request
            return $this->makeReplicateRequest('predictions', $requestData);
        } catch (Exception $e) {
            $this->logger->error('Background generation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
}
