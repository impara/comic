<?php

require_once __DIR__ . '/../interfaces/LoggerInterface.php';
require_once __DIR__ . '/Config.php';

class FileManager
{
    private LoggerInterface $logger;
    private Config $config;
    private const MAX_FILE_AGE = 86400; // 1 day

    public function __construct(LoggerInterface $logger, Config $config)
    {
        $this->logger = $logger;
        $this->config = $config;
    }

    /**
     * Save a base64 encoded image to a file
     */
    public function saveBase64Image(string $base64Data, string $prefix = 'img'): string
    {
        try {
            // Remove data URI prefix if present
            $imageData = preg_replace('/^data:image\/\w+;base64,/', '', $base64Data);
            $decodedData = base64_decode($imageData);

            if ($decodedData === false) {
                throw new RuntimeException("Failed to decode base64 image data");
            }

            $outputDir = rtrim($this->config->getPath('output'), '/');

            // Generate a unique filename
            $filename = $prefix . '_' . uniqid() . '.png';
            $outputPath = $outputDir . '/' . $filename;

            if (file_put_contents($outputPath, $decodedData) === false) {
                throw new RuntimeException("Failed to save image to: $outputPath");
            }

            chmod($outputPath, 0664);

            $this->logger->debug("Image saved successfully", [
                'path' => $outputPath,
                'size' => strlen($decodedData)
            ]);

            return $outputPath;
        } catch (Exception $e) {
            $this->logger->error("Failed to save base64 image", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Save an image from a URL
     */
    public function saveImageFromUrl(string $url, string $prefix = 'img'): string
    {
        try {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            $imageContent = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                throw new RuntimeException("Failed to download image: $error");
            }

            if ($httpCode !== 200) {
                throw new RuntimeException("Failed to download image: HTTP $httpCode");
            }

            $outputDir = rtrim($this->config->getPath('output'), '/');

            // Generate a unique filename
            $filename = $prefix . '_' . uniqid() . '.png';
            $outputPath = $outputDir . '/' . $filename;

            if (file_put_contents($outputPath, $imageContent) === false) {
                throw new RuntimeException("Failed to save image to: $outputPath");
            }

            chmod($outputPath, 0664);

            $this->logger->debug("Image saved successfully", [
                'url' => $url,
                'path' => $outputPath,
                'size' => strlen($imageContent)
            ]);

            return $outputPath;
        } catch (Exception $e) {
            $this->logger->error("Failed to save image from URL", [
                'url' => $url,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Clean up old files in a directory
     */
    public function cleanupDirectory(string $directory, int $maxAge = self::MAX_FILE_AGE, array $excludePatterns = []): void
    {
        try {
            if (!is_dir($directory)) {
                throw new RuntimeException("Directory does not exist: $directory");
            }

            $now = time();
            $files = glob($directory . '/*');

            foreach ($files as $file) {
                if (!is_file($file)) {
                    continue;
                }

                // Check if file matches any exclude pattern
                $shouldExclude = false;
                foreach ($excludePatterns as $pattern) {
                    if (fnmatch($pattern, basename($file))) {
                        $shouldExclude = true;
                        break;
                    }
                }

                if ($shouldExclude) {
                    continue;
                }

                // Remove if older than max age
                if ($now - filemtime($file) > $maxAge) {
                    if (unlink($file)) {
                        $this->logger->debug("Removed old file", [
                            'file' => $file,
                            'age' => $now - filemtime($file)
                        ]);
                    } else {
                        $this->logger->warning("Failed to remove old file", [
                            'file' => $file,
                            'error' => error_get_last()
                        ]);
                    }
                }
            }
        } catch (Exception $e) {
            $this->logger->error("Error during directory cleanup", [
                'directory' => $directory,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
}
