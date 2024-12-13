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
     * Compose multiple images into a comic panel
     * @param array $images Array of image URLs or base64 data
     * @return string Path to composed panel
     * @throws Exception if image processing fails
     */
    public function composePanel(array $images): string
    {
        if (empty($images)) {
            throw new Exception("No images provided for panel composition");
        }

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

            // Calculate positions for characters
            $numCharacters = count($images);
            $positions = $this->calculateCharacterPositions($numCharacters, $panelWidth, $panelHeight);

            // Add character images
            foreach ($images as $index => $imageData) {
                if (!isset($positions[$index])) continue;

                $pos = $positions[$index];
                $this->addImageToPanel(
                    $panel,
                    $imageData,
                    $pos['x'],
                    $pos['y'],
                    $pos['width'],
                    $pos['height']
                );
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
        // Handle both URL and base64 data
        if (filter_var($imageData, FILTER_VALIDATE_URL)) {
            $imageContent = file_get_contents($imageData);
            if ($imageContent === false) {
                throw new Exception("Failed to download image from URL");
            }
        } else {
            $imageContent = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $imageData));
            if ($imageContent === false) {
                throw new Exception("Failed to decode base64 image data");
            }
        }

        $image = @imagecreatefromstring($imageContent);
        if (!$image) {
            throw new Exception("Failed to create image from data");
        }

        // Resize and copy the image onto the panel
        if (!imagecopyresampled(
            $panel,
            $image,
            $x,
            $y,
            0,
            0,
            $width,
            $height,
            imagesx($image),
            imagesy($image)
        )) {
            imagedestroy($image);
            throw new Exception("Failed to copy image to panel");
        }

        imagedestroy($image);
    }

    /**
     * Calculate positions for character images in the panel
     * @param int $numCharacters Number of characters to position
     * @param int $panelWidth Panel width
     * @param int $panelHeight Panel height
     * @return array Array of position data for each character
     */
    private function calculateCharacterPositions(int $numCharacters, int $panelWidth, int $panelHeight): array
    {
        $positions = [];
        $padding = 20;  // Padding between characters

        // For custom characters, we'll use a simple grid layout
        $cols = ceil(sqrt($numCharacters));
        $rows = ceil($numCharacters / $cols);
        $charWidth = (int)(($panelWidth - (($cols + 1) * $padding)) / $cols);
        $charHeight = (int)(($panelHeight - (($rows + 1) * $padding)) / $rows);

        for ($i = 0; $i < $numCharacters; $i++) {
            $row = floor($i / $cols);
            $col = $i % $cols;
            $positions[$i] = [
                'x' => $padding + ($col * ($charWidth + $padding)),
                'y' => $padding + ($row * ($charHeight + $padding)),
                'width' => $charWidth,
                'height' => $charHeight
            ];
        }

        return $positions;
    }
}
