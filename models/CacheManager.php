<?php

class CacheManager
{
    private string $cachePath;
    private LoggerInterface $logger;

    public function __construct(string $cachePath, LoggerInterface $logger)
    {
        $this->cachePath = rtrim($cachePath, '/');
        $this->logger = $logger;

        // Ensure cache directory exists
        if (!is_dir($this->cachePath)) {
            mkdir($this->cachePath, 0755, true);
        }
    }

    /**
     * Get cached data if available and not expired
     */
    public function get(string $key, int $duration = 3600): ?array
    {
        $cacheFile = $this->getCacheFilePath($key);

        if (!file_exists($cacheFile)) {
            return null;
        }

        $data = json_decode(file_get_contents($cacheFile), true);
        if (!$data || !isset($data['timestamp']) || !isset($data['value'])) {
            return null;
        }

        // Check if cache has expired
        if (time() - $data['timestamp'] > $duration) {
            unlink($cacheFile);
            return null;
        }

        $this->logger->info("Cache hit", ['key' => $key]);
        return $data['value'];
    }

    /**
     * Store data in cache
     */
    public function set(string $key, array $value): void
    {
        $cacheFile = $this->getCacheFilePath($key);
        $data = [
            'timestamp' => time(),
            'value' => $value
        ];

        file_put_contents($cacheFile, json_encode($data));
        $this->logger->info("Cache set", ['key' => $key]);
    }

    /**
     * Generate cache file path from key
     */
    private function getCacheFilePath(string $key): string
    {
        return $this->cachePath . '/' . md5($key) . '.cache';
    }

    /**
     * Clear expired cache entries
     */
    public function cleanup(int $maxAge = 3600): void
    {
        $files = glob($this->cachePath . '/*.cache');
        $now = time();

        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if (!$data || !isset($data['timestamp']) || ($now - $data['timestamp'] > $maxAge)) {
                unlink($file);
                $this->logger->info("Removed expired cache file", ['file' => basename($file)]);
            }
        }
    }
}
