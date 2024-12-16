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
        if (empty($images)) {
            throw new Exception("No images provided for panel composition");
        }

        // Log complete images array at start with VERIFICATION prefix
        $this->logger->info("VERIFICATION - Starting panel composition", [
            'total_images' => count($images),
            'scene_context' => $sceneContext,
            'raw_images' => array_map(function ($url, $index) {
                return [
                    'index' => $index,
                    'url' => $url,
                    'type' => $this->determineImageType($url),
                    'is_replicate_url' => strpos($url, 'replicate.delivery') !== false,
                    'url_length' => strlen($url)
                ];
            }, $images, array_keys($images))
        ]);

        // Get art style from scene context or use default
        $artStyle = $sceneContext['style'] ?? 'default';

        try {
            // Create output directory if it doesn't exist
            if (!file_exists($this->outputDir)) {
                mkdir($this->outputDir, 0755, true);
            }

            // Initialize GD image for the panel
            $panelWidth = 1024;  // Default panel width
            $panelHeight = 1024; // Default panel height
            $panel = imagecreatetruecolor($panelWidth, $panelHeight);

            if (!$panel) {
                throw new Exception("Failed to create panel image");
            }

            // Enable alpha blending
            imagealphablending($panel, true);
            imagesavealpha($panel, true);

            // Fill with transparent background
            $transparent = imagecolorallocatealpha($panel, 0, 0, 0, 127);
            imagefill($panel, 0, 0, $transparent);

            // Process background first if it exists
            if (isset($images['background'])) {
                $this->logger->info("VERIFICATION - Processing background image", [
                    'image_url' => $images['background'],
                    'type' => $this->determineImageType($images['background'])
                ]);
                $this->addImageToPanel($panel, $images['background'], 0, 0, $panelWidth, $panelHeight);
                unset($images['background']);
            }

            // Calculate positions for characters
            $positions = $this->calculateCharacterPositions($images, $sceneContext, $panelWidth, $panelHeight);

            // Log positions before processing
            $this->logger->info("VERIFICATION - Character positions calculated", [
                'positions' => array_map(function ($pos, $index) use ($images) {
                    return [
                        'index' => $index,
                        'position' => $pos,
                        'image_url' => $images[$index],
                        'image_type' => $this->determineImageType($images[$index])
                    ];
                }, $positions, array_keys($positions))
            ]);

            // Add character images
            foreach ($images as $index => $imageUrl) {
                if (!isset($positions[$index])) {
                    $this->logger->warning("VERIFICATION - No position found for image", [
                        'index' => $index,
                        'image_url' => $imageUrl
                    ]);
                    continue;
                }

                $pos = $positions[$index];

                $this->logger->info("VERIFICATION - Processing character image", [
                    'index' => $index,
                    'image_url' => $imageUrl,
                    'position' => $pos,
                    'type' => $this->determineImageType($imageUrl),
                    'is_replicate_url' => strpos($imageUrl, 'replicate.delivery') !== false
                ]);

                // Add the character image
                $this->addImageToPanel(
                    $panel,
                    $imageUrl,
                    $pos['x'],
                    $pos['y'],
                    $pos['width'],
                    $pos['height']
                );

                $this->logger->info("VERIFICATION - Character image added to panel", [
                    'index' => $index,
                    'position' => $pos,
                    'success' => true
                ]);
            }

            // Save the composed panel
            $outputPath = $this->outputDir . '/panel_' . uniqid() . '.png';
            $saveResult = imagepng($panel, $outputPath);

            $this->logger->info("VERIFICATION - Panel saved", [
                'output_path' => $outputPath,
                'save_success' => $saveResult,
                'file_exists' => file_exists($outputPath),
                'file_size' => file_exists($outputPath) ? filesize($outputPath) : 0,
                'total_images_processed' => count($images)
            ]);

            imagedestroy($panel);
            return $outputPath;
        } catch (Exception $e) {
            $this->logger->error("Failed to compose panel", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
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
}
