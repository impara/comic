<?php

class ImageComposer
{
    private LoggerInterface $logger;
    private Config $config;
    private string $outputDir;

    public function __construct(LoggerInterface $logger, Config $config)
    {
        $this->logger = $logger;
        $this->config = $config;
        $this->outputDir = rtrim($this->config->getPath('output'), '/');
    }

    /**
     * Compose a comic panel with intelligent character placement
     */
    public function composePanelImage(array $composition, string $description, array $options = []): string
    {
        $jobId = $options['job_id'] ?? null;
        $this->logger->info("Starting panel composition", [
            'character_count' => count($composition),
            'description' => $description,
            'job_id' => $jobId
        ]);

        try {
            // Validate all images
            foreach ($composition as $charId => $data) {
                if (!isset($data['image']) || !filter_var($data['image'], FILTER_VALIDATE_URL)) {
                    throw new Exception("Invalid image URL for character $charId");
                }
            }

            // Create a new image with transparent background
            $width = $options['width'] ?? 1024;
            $height = $options['height'] ?? 1024;
            $panel = imagecreatetruecolor($width, $height);

            // Enable alpha blending and save alpha channel
            imagealphablending($panel, true);
            imagesavealpha($panel, true);

            // Fill with transparent background
            $transparent = imagecolorallocatealpha($panel, 0, 0, 0, 127);
            imagefill($panel, 0, 0, $transparent);

            // Add each character to the panel
            foreach ($composition as $charId => $data) {
                $this->logger->debug("Processing character image", [
                    'character_id' => $charId,
                    'image_url' => $data['image'],
                    'position' => $data['position'] ?? 'default'
                ]);

                // Download and process the image
                $characterImage = $this->loadImageFromUrl($data['image']);
                if (!$characterImage) {
                    throw new Exception("Failed to load character image: $charId");
                }

                // Get image dimensions
                $srcWidth = imagesx($characterImage);
                $srcHeight = imagesy($characterImage);

                // Get position from composition data
                $position = $data['position'] ?? ['x' => 0, 'y' => 0];
                $scale = $data['position']['scale'] ?? 1.0;

                // Calculate target dimensions
                $targetWidth = $srcWidth * $scale;
                $targetHeight = $srcHeight * $scale;

                // Calculate position (centered on the specified point)
                $x = $position['x'] - ($targetWidth / 2);
                $y = $position['y'] - ($targetHeight / 2);

                // Copy and resize the character onto the panel
                imagecopyresampled(
                    $panel,
                    $characterImage,
                    $x,
                    $y,
                    0,
                    0,
                    $targetWidth,
                    $targetHeight,
                    $srcWidth,
                    $srcHeight
                );

                // Clean up
                imagedestroy($characterImage);
            }

            // Save the composed panel
            $outputPath = rtrim($this->config->getPath('temp'), '/') . '/panel_' . ($jobId ?? uniqid()) . '.png';
            if (!imagepng($panel, $outputPath)) {
                throw new Exception("Failed to save panel image: $outputPath");
            }
            imagedestroy($panel);

            $this->logger->info("Panel composition completed", [
                'output_path' => $outputPath,
                'job_id' => $jobId
            ]);

            return $outputPath;
        } catch (Exception $e) {
            $this->logger->error("Panel composition failed", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'job_id' => $jobId
            ]);
            throw $e;
        }
    }

    /**
     * Load an image from a URL
     */
    private function loadImageFromUrl(string $url): ?\GdImage
    {
        $this->logger->debug("Loading image from URL", ['url' => $url]);

        try {
            // Download image data
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            $imageData = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                throw new Exception("Failed to download image: $error");
            }

            if ($httpCode !== 200) {
                throw new Exception("Failed to download image: HTTP $httpCode");
            }

            // Create image from string
            $image = imagecreatefromstring($imageData);
            if (!$image) {
                throw new Exception("Failed to create image from data");
            }

            return $image;
        } catch (Exception $e) {
            $this->logger->error("Failed to load image", [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}
