<?php

require_once __DIR__ . '/../interfaces/LoggerInterface.php';
require_once __DIR__ . '/Config.php';

class FileManager
{
    private LoggerInterface $logger;
    private Config $config;
    private static ?self $instance = null;
    private const MAX_FILE_AGE = 86400; // 1 day

    /**
     * Private constructor to enforce singleton pattern
     * @param LoggerInterface $logger Logger instance
     */
    private function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->config = Config::getInstance();
    }

    /**
     * Get singleton instance
     * @param LoggerInterface $logger Logger instance
     * @return self FileManager instance
     */
    public static function getInstance(LoggerInterface $logger): self
    {
        if (self::$instance === null) {
            self::$instance = new self($logger);
        }
        return self::$instance;
    }

    /**
     * Save a base64 encoded image to a file
     * @param string $base64Data Base64 encoded image data
     * @param string $prefix Prefix for the filename
     * @return string Path to the saved file
     * @throws RuntimeException if saving fails
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

            $outputDir = $this->getOutputPath();
            if (!file_exists($outputDir)) {
                mkdir($outputDir, 0755, true);
            }

            // Generate a unique filename
            $filename = $prefix . '_' . uniqid() . '.png';
            $outputPath = $outputDir . '/' . $filename;

            if (file_put_contents($outputPath, $decodedData) === false) {
                throw new RuntimeException("Failed to save image to: $outputPath");
            }

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
     * @param string $url URL of the image
     * @param string $prefix Prefix for the filename
     * @return string Path to the saved file
     * @throws RuntimeException if saving fails
     */
    public function saveImageFromUrl(string $url, string $prefix = 'img'): string
    {
        try {
            $imageContent = file_get_contents($url);
            if ($imageContent === false) {
                throw new RuntimeException("Failed to download image from URL: $url");
            }

            $outputDir = $this->getOutputPath();
            if (!file_exists($outputDir)) {
                mkdir($outputDir, 0755, true);
            }

            // Generate a unique filename
            $filename = $prefix . '_' . uniqid() . '.png';
            $outputPath = $outputDir . '/' . $filename;

            if (file_put_contents($outputPath, $imageContent) === false) {
                throw new RuntimeException("Failed to save image to: $outputPath");
            }

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
     * Clean up unused temporary files
     * @param array $usedFiles Array of files that should be kept
     * @param int|null $maxAge Maximum age in seconds for files to keep
     * @return void
     */
    public function cleanupTempFiles(array $usedFiles, ?int $maxAge = null): void
    {
        $outputDir = $this->getOutputPath();
        $maxAge = $maxAge ?? self::MAX_FILE_AGE;

        $this->logger->debug("Starting file cleanup", [
            'output_dir' => $outputDir,
            'used_files' => $usedFiles,
            'max_age' => $maxAge
        ]);

        try {
            // Get all files in the output directory
            $files = glob($outputDir . '/*');
            $now = time();

            foreach ($files as $file) {
                // Skip if file is in use
                if (in_array($file, $usedFiles)) {
                    $this->logger->debug("Keeping file in use", ['file' => $file]);
                    continue;
                }

                // Remove old files
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
            $this->logger->error("Error during file cleanup", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Get the output directory path
     * @return string Output directory path
     */
    private function getOutputPath(): string
    {
        $outputPath = $this->config->get('paths.output');

        // Convert relative path to absolute if needed
        if (!str_starts_with($outputPath, '/')) {
            $outputPath = realpath(__DIR__ . '/../' . $outputPath);
        }

        if (!$outputPath) {
            throw new RuntimeException('Invalid output path configuration');
        }

        if (!file_exists($outputPath)) {
            mkdir($outputPath, 0755, true);
        }

        $this->logger->debug("Using output path", [
            'path' => $outputPath,
            'exists' => file_exists($outputPath),
            'writable' => is_writable($outputPath)
        ]);

        return $outputPath;
    }
}
