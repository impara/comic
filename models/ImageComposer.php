<?php

require_once __DIR__ . '/../interfaces/LoggerInterface.php';
require_once __DIR__ . '/Config.php';

class ImageComposer
{
    private LoggerInterface $logger;
    private Config $config;
    private string $outputDir;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->config = Config::getInstance();
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
        $this->logger->error("TEST_LOG - composePanel received images", [
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
                    $state['composition_status'] = 'processing';
                    $state['composition_started_at'] = time();
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
                $this->logger->error("TEST_LOG - Adding image to panel", [
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

                $this->logger->error("TEST_LOG - Image dimensions for panel", [
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
                    $state['composition_status'] = 'succeeded';
                    $state['composition_completed_at'] = time();
                    $state['composed_panel_path'] = $outputPath;
                    file_put_contents($stateFile, json_encode($state));
                }
            }

            $this->logger->error("TEST_LOG - Panel composition completed", [
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
            $state['composition_status'] = 'failed';
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
            $this->logger->error("TEST_LOG - Loading image from URL", [
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

            $this->logger->error("TEST_LOG - Successfully loaded image", [
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
     * Update the strip state file
     * @param string $stripId Strip ID
     * @param array $update Update data
     */
    private function updateStripState(string $stripId, array $update): void
    {
        $stateFile = $this->config->getTempPath() . "strip_state_{$stripId}.json";
        if (file_exists($stateFile)) {
            $state = json_decode(file_get_contents($stateFile), true);
            $state = array_merge($state, $update, ['updated_at' => time()]);
            file_put_contents($stateFile, json_encode($state));
        }
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
}
