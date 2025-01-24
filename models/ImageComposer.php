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
    public function composePanelImage(string $backgroundUrl, array $characterMap, string $panelId): string
    {
        $this->logger->info("Starting panel composition", [
            'panel_id' => $panelId,
            'character_count' => count($characterMap)
        ]);

        try {
            // Load background image
            $background = $this->loadImageFromUrl($backgroundUrl);
            if (!$background) {
                throw new Exception("Failed to load background image: $backgroundUrl");
            }

            // Get background dimensions
            $bgWidth = imagesx($background);
            $bgHeight = imagesy($background);

            // Process each character
            foreach ($characterMap as $character) {
                if (empty($character['cartoonify_url'])) {
                    throw new Exception("Character missing cartoonify URL");
                }

                // Load character image
                $characterImage = $this->loadImageFromUrl($character['cartoonify_url']);
                if (!$characterImage) {
                    throw new Exception("Failed to load character image: " . $character['cartoonify_url']);
                }

                // Get character dimensions
                $charWidth = imagesx($characterImage);
                $charHeight = imagesy($characterImage);

                // Calculate position (default to bottom center)
                $position = $character['position'] ?? [
                    'x' => $bgWidth / 2 - $charWidth / 2,
                    'y' => $bgHeight - $charHeight - 20 // 20px from bottom
                ];

                // Overlay character onto background
                imagecopy(
                    $background,
                    $characterImage,
                    $position['x'],
                    $position['y'],
                    0,
                    0,
                    $charWidth,
                    $charHeight
                );

                // Clean up character image
                imagedestroy($characterImage);
            }

            // Save the composed panel
            $relativePath = "/temp/panel_$panelId.png";
            $outputPath = $this->config->getPath('public') . $relativePath;
            if (!imagepng($background, $outputPath)) {
                throw new Exception("Failed to save panel image: $outputPath");
            }

            // Clean up background image
            imagedestroy($background);

            $this->logger->info("Panel composition completed", [
                'panel_id' => $panelId,
                'output_path' => $outputPath
            ]);

            return $relativePath;
        } catch (Exception $e) {
            $this->logger->error("Panel composition failed", [
                'panel_id' => $panelId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
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
