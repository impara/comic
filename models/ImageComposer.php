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
     * Compose a comic panel with intelligent character placement and speech bubbles
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
                $this->addImageToPanel($panel, $images['background'], 0, 0, $panelWidth, $panelHeight);
                unset($images['background']);
            }

            // Calculate positions for characters using force-directed algorithm
            $positions = $this->calculateCharacterPositions($images, $sceneContext, $panelWidth, $panelHeight);

            // Add character images and bubbles
            foreach ($images as $index => $imageData) {
                if (!isset($positions[$index])) continue;

                $pos = $positions[$index];

                // Handle both string and array image data
                $imageSource = is_array($imageData) ? $imageData['image'] : $imageData;

                // Add the character image
                $this->addImageToPanel(
                    $panel,
                    $imageSource,
                    $pos['x'],
                    $pos['y'],
                    $pos['width'],
                    $pos['height']
                );

                // Add speech bubble if text is provided
                if (isset($sceneContext['dialogues']) && isset($sceneContext['dialogues'][$index])) {
                    $speech = $sceneContext['dialogues'][$index];
                    if (!empty($speech)) {
                        $bubbleWidth = min(300, $pos['width'] * 1.5);
                        $bubbleHeight = min(150, 50 + (strlen($speech) / 20) * 10);
                        $bubbleX = $pos['x'] + ($pos['width'] - $bubbleWidth) / 2;
                        $bubbleY = max(20, $pos['y'] - $bubbleHeight - 20);

                        $this->addSpeechBubble(
                            $panel,
                            $speech,
                            $bubbleX,
                            $bubbleY,
                            $bubbleWidth,
                            $bubbleHeight,
                            false, // Speech bubble
                            $artStyle
                        );
                    }
                }

                // Add thought bubble if thoughts are provided
                if (isset($sceneContext['thoughts']) && isset($sceneContext['thoughts'][$index])) {
                    $thought = $sceneContext['thoughts'][$index];
                    if (!empty($thought)) {
                        $bubbleWidth = min(250, $pos['width'] * 1.3);
                        $bubbleHeight = min(120, 40 + (strlen($thought) / 20) * 10);
                        $bubbleX = $pos['x'] + ($pos['width'] - $bubbleWidth) / 2;
                        $bubbleY = max(20, $pos['y'] - $bubbleHeight - 40);

                        $this->addSpeechBubble(
                            $panel,
                            $thought,
                            $bubbleX,
                            $bubbleY,
                            $bubbleWidth,
                            $bubbleHeight,
                            true, // Thought bubble
                            $artStyle
                        );
                    }
                }
            }

            // Save the composed panel
            $outputPath = $this->outputDir . '/panel_' . uniqid() . '.png';
            imagepng($panel, $outputPath);
            imagedestroy($panel);

            $this->logger->info("Panel composed successfully", ['path' => $outputPath]);
            return $outputPath;
        } catch (Exception $e) {
            $this->logger->error("Failed to compose panel", ['error' => $e->getMessage()]);
            throw $e;
        }
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
                'image_data' => substr($imageData, 0, 100) . '...'
            ]);
            throw $e;
        }
    }

    /**
     * Calculate character positions using a force-directed algorithm
     *
     * @param array $images Array of character images
     * @param array $sceneContext Scene context details (unused in this version)
     * @param int $panelWidth Panel width
     * @param int $panelHeight Panel height
     * @return array Positions for each character
     */
    private function calculateCharacterPositions(array $images, array $sceneContext, int $panelWidth, int $panelHeight): array
    {
        $positions = [];
        $padding = 50; // Minimum distance between characters
        $defaultSize = 200; // Default character size

        // Initialize random positions
        foreach ($images as $index => $_) {
            $positions[$index] = [
                'x' => rand($padding, $panelWidth - $defaultSize - $padding),
                'y' => rand($padding, $panelHeight - $defaultSize - $padding),
                'width' => $defaultSize,
                'height' => $defaultSize
            ];
        }

        // Apply force-directed adjustments
        $iterations = 50; // Reduced from 100 for better performance
        for ($i = 0; $i < $iterations; $i++) {
            $movements = [];

            // Initialize movement array
            foreach (array_keys($positions) as $index) {
                $movements[$index] = ['x' => 0, 'y' => 0];
            }

            // Calculate repulsion forces between characters
            foreach ($positions as $indexA => $posA) {
                foreach ($positions as $indexB => $posB) {
                    if ($indexA === $indexB) continue;

                    // Calculate distance between centers
                    $dx = ($posA['x'] + $posA['width'] / 2) - ($posB['x'] + $posB['width'] / 2);
                    $dy = ($posA['y'] + $posA['height'] / 2) - ($posB['y'] + $posB['height'] / 2);
                    $distance = sqrt($dx * $dx + $dy * $dy);

                    if ($distance < $padding * 2) {
                        // Calculate repulsion force
                        $force = ($padding * 2 - $distance) / $distance;
                        $movements[$indexA]['x'] += $force * $dx * 0.1;
                        $movements[$indexA]['y'] += $force * $dy * 0.1;
                    }
                }

                // Add center attraction force
                $centerX = $panelWidth / 2;
                $centerY = $panelHeight / 2;
                $dx = $centerX - ($posA['x'] + $posA['width'] / 2);
                $dy = $centerY - ($posA['y'] + $posA['height'] / 2);
                $distance = sqrt($dx * $dx + $dy * $dy);

                if ($distance > $padding * 3) {
                    $movements[$indexA]['x'] += $dx * 0.01;
                    $movements[$indexA]['y'] += $dy * 0.01;
                }
            }

            // Apply movements
            foreach ($movements as $index => $move) {
                $positions[$index]['x'] += $move['x'];
                $positions[$index]['y'] += $move['y'];

                // Boundary checks
                $positions[$index]['x'] = max($padding, min(
                    $positions[$index]['x'],
                    $panelWidth - $positions[$index]['width'] - $padding
                ));
                $positions[$index]['y'] = max($padding, min(
                    $positions[$index]['y'],
                    $panelHeight - $positions[$index]['height'] - $padding
                ));
            }
        }

        return $positions;
    }

    /**
     * Add a speech or thought bubble to the panel with style-specific rendering
     * 
     * @param \GdImage $panel Panel image resource
     * @param string $text Bubble text
     * @param float $x X position
     * @param float $y Y position
     * @param int $width Bubble width
     * @param int $height Bubble height
     * @param bool $isThought Whether this is a thought bubble
     * @param string $style Art style (manga, comic, european, default)
     * @throws Exception on failure
     */
    private function addSpeechBubble(\GdImage $panel, string $text, float $x, float $y, int $width, int $height, bool $isThought = false, string $style = 'default'): void
    {
        try {
            // Create temporary bubble canvas
            $bubble = imagecreatetruecolor($width, $height + ($isThought ? 25 : 15));
            if (!$bubble) {
                throw new Exception("Failed to create speech bubble canvas");
            }

            // Enable transparency
            imagealphablending($bubble, true);
            imagesavealpha($bubble, true);
            $transparent = imagecolorallocatealpha($bubble, 0, 0, 0, 127);
            imagefill($bubble, 0, 0, $transparent);

            // Colors
            $white = imagecolorallocate($bubble, 255, 255, 255);
            $black = imagecolorallocate($bubble, 0, 0, 0);
            $bubbleColor = imagecolorallocate($bubble, 255, 255, 255);

            if ($isThought) {
                // Draw thought bubble based on style
                switch ($style) {
                    case 'manga':
                        $this->drawMangaThoughtBubble($bubble, 0, 0, $width - 1, $height - 1, $bubbleColor);
                        break;
                    case 'european':
                        $this->drawEuropeanThoughtBubble($bubble, 0, 0, $width - 1, $height - 1, $bubbleColor);
                        break;
                    default:
                        $this->drawThoughtBubble($bubble, 0, 0, $width - 1, $height - 1, $bubbleColor);
                }
            } else {
                // Draw speech bubble based on style
                switch ($style) {
                    case 'manga':
                        $this->drawMangaSpeechBubble($bubble, 0, 0, $width - 1, $height - 1, $bubbleColor);
                        break;
                    case 'european':
                        $this->drawEuropeanSpeechBubble($bubble, 0, 0, $width - 1, $height - 1, $bubbleColor);
                        break;
                    default:
                        // Draw standard comic-style bubble
                        $radius = 10;
                        $this->drawRoundedRectangle($bubble, 0, 0, $width - 1, $height - 1, $radius, $bubbleColor);

                        // Draw tail
                        $points = [
                            $width / 2 - 10,
                            $height - 1,    // Left point
                            $width / 2 + 10,
                            $height - 1,    // Right point
                            $width / 2,
                            $height + 15         // Bottom point
                        ];
                        imagefilledpolygon($bubble, $points, 3, $bubbleColor);
                }
            }

            // Add text with style-specific font handling
            $this->addStyledText($bubble, $text, $width, $height, $black, $style);

            // Copy bubble to panel
            imagecopy($panel, $bubble, (int)$x, (int)$y, 0, 0, $width, $height + ($isThought ? 25 : 15));
            imagedestroy($bubble);
        } catch (Exception $e) {
            $this->logger->error("Failed to add speech bubble", ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Draw a thought bubble (cloud-like shape)
     * 
     * @param \GdImage $image Image resource
     * @param int $x X position
     * @param int $y Y position
     * @param int $width Width
     * @param int $height Height
     * @param int $color Color resource
     */
    private function drawThoughtBubble(\GdImage $image, int $x, int $y, int $width, int $height, int $color): void
    {
        // Base ellipse for the bubble
        imagefilledellipse($image, $x + $width / 2, $y + $height / 2, $width, $height, $color);

        // Add bumps around the edges to create cloud effect
        $numBumps = 8;
        $bumpSize = min($width, $height) * 0.2;

        for ($i = 0; $i < $numBumps; $i++) {
            $angle = (2 * M_PI * $i) / $numBumps;
            $bx = $x + $width / 2 + cos($angle) * ($width / 2 - $bumpSize / 2);
            $by = $y + $height / 2 + sin($angle) * ($height / 2 - $bumpSize / 2);
            imagefilledellipse($image, (int)$bx, (int)$by, (int)$bumpSize, (int)$bumpSize, $color);
        }
    }

    /**
     * Draw a rounded rectangle
     * 
     * @param \GdImage $image Image resource
     * @param int $x X position
     * @param int $y Y position
     * @param int $width Width
     * @param int $height Height
     * @param int $radius Corner radius
     * @param int $color Color resource
     */
    private function drawRoundedRectangle(\GdImage $image, int $x, int $y, int $width, int $height, int $radius, int $color): void
    {
        // Draw the main rectangle
        imagefilledrectangle($image, $x + $radius, $y, $width - $radius, $height, $color);
        imagefilledrectangle($image, $x, $y + $radius, $width, $height - $radius, $color);

        // Draw the four corners
        imagefilledarc($image, $x + $radius, $y + $radius, $radius * 2, $radius * 2, 180, 270, $color, IMG_ARC_PIE);
        imagefilledarc($image, $width - $radius, $y + $radius, $radius * 2, $radius * 2, 270, 360, $color, IMG_ARC_PIE);
        imagefilledarc($image, $x + $radius, $height - $radius, $radius * 2, $radius * 2, 90, 180, $color, IMG_ARC_PIE);
        imagefilledarc($image, $width - $radius, $height - $radius, $radius * 2, $radius * 2, 0, 90, $color, IMG_ARC_PIE);
    }

    /**
     * Draw a manga-style speech bubble (more angular)
     */
    private function drawMangaSpeechBubble(\GdImage $image, int $x, int $y, int $width, int $height, int $color): void
    {
        // Add subtle shadow effect
        $shadowColor = imagecolorallocatealpha($image, 0, 0, 0, 100);
        $shadowOffset = 3;

        // Shadow points
        $shadowPoints = [
            $x + 10 + $shadowOffset,
            $y + $shadowOffset,
            $x + $width - 10 + $shadowOffset,
            $y + $shadowOffset,
            $x + $width + $shadowOffset,
            $y + 10 + $shadowOffset,
            $x + $width + $shadowOffset,
            $y + $height - 10 + $shadowOffset,
            $x + $width - 10 + $shadowOffset,
            $y + $height + $shadowOffset,
            $x + ($width / 2) + 15 + $shadowOffset,
            $y + $height + $shadowOffset,
            $x + $width / 2 + $shadowOffset,
            $y + $height + 20 + $shadowOffset,
            $x + ($width / 2) - 15 + $shadowOffset,
            $y + $height + $shadowOffset,
            $x + 10 + $shadowOffset,
            $y + $height + $shadowOffset,
            $x + $shadowOffset,
            $y + $height - 10 + $shadowOffset,
            $x + $shadowOffset,
            $y + 10 + $shadowOffset
        ];
        imagefilledpolygon($image, $shadowPoints, count($shadowPoints) / 2, $shadowColor);

        // Main bubble points (more angular for manga style)
        $points = [
            $x + 10,
            $y,                    // Top left
            $x + $width - 10,
            $y,          // Top right
            $x + $width,
            $y + 10,          // Right top
            $x + $width,
            $y + $height - 10, // Right bottom
            $x + $width - 10,
            $y + $height, // Bottom right
            $x + ($width / 2) + 15,
            $y + $height, // Bottom right before tail
            $x + $width / 2,
            $y + $height + 20,   // Tail point (sharper)
            $x + ($width / 2) - 15,
            $y + $height, // Bottom left after tail
            $x + 10,
            $y + $height,         // Bottom left
            $x,
            $y + $height - 10,         // Left bottom
            $x,
            $y + 10                    // Left top
        ];
        imagefilledpolygon($image, $points, count($points) / 2, $color);

        // Add manga-style emphasis lines
        $emphasisColor = imagecolorallocatealpha($image, 0, 0, 0, 110);
        imageline($image, $x + 5, $y + 5, $x + 15, $y + 5, $emphasisColor);
        imageline($image, $x + 5, $y + $height - 5, $x + 15, $y + $height - 5, $emphasisColor);
    }

    /**
     * Draw a manga-style thought bubble (more geometric)
     */
    private function drawMangaThoughtBubble(\GdImage $image, int $x, int $y, int $width, int $height, int $color): void
    {
        // Shadow for main bubble
        $shadowColor = imagecolorallocatealpha($image, 0, 0, 0, 100);
        $shadowOffset = 3;

        // Main octagonal bubble with shadow
        $points = [];
        $shadowPoints = [];
        $sides = 8;
        for ($i = 0; $i < $sides; $i++) {
            $angle = (2 * M_PI * $i) / $sides - M_PI / $sides;
            $px = $x + $width / 2 + cos($angle) * $width / 2;
            $py = $y + $height / 2 + sin($angle) * $height / 2;
            $points[] = (int)$px;
            $points[] = (int)$py;
            $shadowPoints[] = (int)($px + $shadowOffset);
            $shadowPoints[] = (int)($py + $shadowOffset);
        }

        // Draw shadow first
        imagefilledpolygon($image, $shadowPoints, $sides, $shadowColor);
        // Draw main bubble
        imagefilledpolygon($image, $points, $sides, $color);

        // Square dots with shadows
        $centerX = $width / 2;
        $dotY = $height + 5;
        for ($i = 0; $i < 3; $i++) {
            $size = 8 - ($i * 2);
            // Shadow
            imagefilledrectangle(
                $image,
                (int)($centerX - $size / 2 + $shadowOffset),
                (int)($dotY - $size / 2 + $shadowOffset),
                (int)($centerX + $size / 2 + $shadowOffset),
                (int)($dotY + $size / 2 + $shadowOffset),
                $shadowColor
            );
            // Main dot
            imagefilledrectangle(
                $image,
                (int)($centerX - $size / 2),
                (int)($dotY - $size / 2),
                (int)($centerX + $size / 2),
                (int)($dotY + $size / 2),
                $color
            );
            $dotY += $size + 2;
        }

        // Add manga-style decorative lines
        $emphasisColor = imagecolorallocatealpha($image, 0, 0, 0, 110);
        for ($i = 0; $i < 4; $i++) {
            $angle = M_PI * $i / 2;
            $lineX = $x + $width / 2 + cos($angle) * ($width / 2 - 10);
            $lineY = $y + $height / 2 + sin($angle) * ($height / 2 - 10);
            imageline($image, (int)$lineX, (int)$lineY, (int)($lineX + 5), (int)($lineY + 5), $emphasisColor);
        }
    }

    /**
     * Draw a European-style speech bubble (more ornate)
     */
    private function drawEuropeanSpeechBubble(\GdImage $image, int $x, int $y, int $width, int $height, int $color): void
    {
        // Add decorative border
        $borderColor = imagecolorallocatealpha($image, 0, 0, 0, 80);
        $radius = 20;

        // Draw outer decorative border
        $this->drawRoundedRectangle($image, $x - 2, $y - 2, $width + 1, $height + 1, $radius + 2, $borderColor);

        // Draw main bubble
        $this->drawRoundedRectangle($image, $x, $y, $width - 1, $height - 1, $radius, $color);

        // Draw curved tail with artistic flourish
        $points = [];
        $cx = $width / 2;
        $cy = $height;

        // More elaborate curved tail using Bezier curve points
        for ($i = 0; $i <= 20; $i++) {
            $t = $i / 20;
            // Control points for S-curve
            $px = $cx + (1 - $t) * (1 - $t) * (1 - $t) * -20 +
                3 * (1 - $t) * (1 - $t) * $t * -5 +
                    3 * (1 - $t) * $t * $t * 5 +
                    $t * $t * $t * 20;
            $py = $cy + (1 - $t) * (1 - $t) * (1 - $t) * 0 +
                3 * (1 - $t) * (1 - $t) * $t * 25 +
                3 * (1 - $t) * $t * $t * 25 +
                $t * $t * $t * 0;
            $points[] = (int)$px;
            $points[] = (int)$py;
        }

        // Draw tail border first
        $tailBorderPoints = array_map(function ($p, $i) {
            return $p + ($i % 2 === 0 ? -1 : 1);
        }, $points, array_keys($points));
        imagefilledpolygon($image, $tailBorderPoints, count($tailBorderPoints) / 2, $borderColor);

        // Draw main tail
        imagefilledpolygon($image, $points, count($points) / 2, $color);

        // Add decorative corner flourishes
        $flourishColor = imagecolorallocatealpha($image, 0, 0, 0, 100);
        $flourishSize = 8;
        // Top left flourish
        imagearc($image, $x + $radius, $y + $radius, $flourishSize, $flourishSize, 180, 270, $flourishColor);
        // Top right flourish
        imagearc($image, $x + $width - $radius, $y + $radius, $flourishSize, $flourishSize, 270, 360, $flourishColor);
        // Bottom flourishes
        imagearc($image, $x + $radius, $y + $height - $radius, $flourishSize, $flourishSize, 90, 180, $flourishColor);
        imagearc($image, $x + $width - $radius, $y + $height - $radius, $flourishSize, $flourishSize, 0, 90, $flourishColor);
    }

    /**
     * Draw a European-style thought bubble (more decorative)
     */
    private function drawEuropeanThoughtBubble(\GdImage $image, int $x, int $y, int $width, int $height, int $color): void
    {
        // Draw outer decorative border
        $borderColor = imagecolorallocatealpha($image, 0, 0, 0, 80);
        imagefilledellipse($image, $x + $width / 2, $y + $height / 2, $width + 2, $height + 2, $borderColor);

        // Draw main elliptical bubble
        imagefilledellipse($image, $x + $width / 2, $y + $height / 2, $width, $height, $color);

        // Add more elaborate decorative swirls
        $numSwirls = 16; // Increased number of swirls
        $swirlSize = min($width, $height) * 0.12;

        // Draw outer swirls
        for ($i = 0; $i < $numSwirls; $i++) {
            $angle = (2 * M_PI * $i) / $numSwirls;
            $bx = $x + $width / 2 + cos($angle) * ($width / 2 - $swirlSize / 2);
            $by = $y + $height / 2 + sin($angle) * ($height / 2 - $swirlSize / 2);

            // Draw more elaborate spiral for each swirl
            for ($j = 0; $j < 6; $j++) {
                $swAngle = $angle + $j * M_PI / 3;
                $radius = $swirlSize * (6 - $j) / 6;
                $sx = $bx + cos($swAngle) * $radius;
                $sy = $by + sin($swAngle) * $radius;
                imagefilledellipse($image, (int)$sx, (int)$sy, 2, 2, $borderColor);
            }
        }

        // Add inner decorative pattern
        $innerSwirls = 8;
        $innerSize = min($width, $height) * 0.3;
        for ($i = 0; $i < $innerSwirls; $i++) {
            $angle = (2 * M_PI * $i) / $innerSwirls;
            $ix = $x + $width / 2 + cos($angle) * $innerSize;
            $iy = $y + $height / 2 + sin($angle) * $innerSize;
            imagearc($image, (int)$ix, (int)$iy, 10, 10, 0, 360, $borderColor);
        }
    }

    /**
     * Add text with style-specific formatting
     */
    private function addStyledText(\GdImage $bubble, string $text, int $width, int $height, int $color, string $style): void
    {
        $fontSize = 12;
        $fontFile = __DIR__ . '/../assets/fonts/';

        // Select font based on style
        switch ($style) {
            case 'manga':
                $fontFile .= 'manga.ttf';
                $fontSize = 14; // Slightly larger for manga
                break;
            case 'european':
                $fontFile .= 'european.ttf';
                $fontSize = 11; // Slightly smaller for european
                break;
            default:
                $fontFile .= 'arial.ttf';
        }

        // Fallback to built-in font if custom font not available
        if (!file_exists($fontFile)) {
            $fontFile = 5;
            $text = wordwrap($text, 25);
            $textBounds = imagettfbbox($fontSize, 0, $fontFile, $text);
            if ($textBounds) {
                $textWidth = $textBounds[2] - $textBounds[0];
                $textHeight = $textBounds[1] - $textBounds[7];
                $textX = ($width - $textWidth) / 2;
                $textY = ($height - $textHeight) / 2 + $textHeight;
                imagettftext($bubble, $fontSize, 0, $textX, $textY, $color, $fontFile, $text);
            } else {
                // Ultimate fallback to basic text
                $textWidth = strlen($text) * 5;
                $textX = ($width - $textWidth) / 2;
                $textY = $height / 2;
                imagestring($bubble, $fontFile, $textX, $textY, $text, $color);
            }
            return;
        }

        // Use custom font with style-specific text wrapping
        $maxChars = ($style === 'manga') ? 20 : 25; // Manga style uses fewer characters per line
        $text = wordwrap($text, $maxChars);
        $textBounds = imagettfbbox($fontSize, 0, $fontFile, $text);
        $textWidth = $textBounds[2] - $textBounds[0];
        $textHeight = $textBounds[1] - $textBounds[7];
        $textX = ($width - $textWidth) / 2;
        $textY = ($height - $textHeight) / 2 + $textHeight;

        // Add slight rotation for manga style
        if ($style === 'manga') {
            imagettftext($bubble, $fontSize, -2, $textX, $textY, $color, $fontFile, $text);
        } else {
            imagettftext($bubble, $fontSize, 0, $textX, $textY, $color, $fontFile, $text);
        }
    }
}
