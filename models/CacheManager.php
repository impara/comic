<?php

class CacheManager
{
    private string $cachePath;
    private LoggerInterface $logger;
    private const CACHE_DURATION = 3600; // 1 hour default cache duration

    public function __construct(string $cachePath, LoggerInterface $logger)
    {
        $this->cachePath = rtrim($cachePath, '/');
        $this->logger = $logger;

        // Ensure cache directory exists
        if (!is_dir($this->cachePath)) {
            mkdir($this->cachePath, 0755, true);
        }
    }

    public function get(string $key, int $duration = self::CACHE_DURATION)
    {
        $cacheFile = $this->getCacheFilePath($key);

        if (!file_exists($cacheFile)) {
            return null;
        }

        try {
            $data = json_decode(file_get_contents($cacheFile), true);
            if (!$data || !isset($data['timestamp']) || !isset($data['value'])) {
                return null;
            }

            // Check if cache has expired
            if (time() - $data['timestamp'] > $duration) {
                @unlink($cacheFile);
                return null;
            }

            $this->logger->debug('Cache hit', [
                'key' => $key,
                'age' => time() - $data['timestamp']
            ]);

            return $data['value'];
        } catch (Exception $e) {
            $this->logger->error('Cache read error', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    public function set(string $key, $value, int $duration = self::CACHE_DURATION): bool
    {
        $cacheFile = $this->getCacheFilePath($key);

        try {
            $data = [
                'timestamp' => time(),
                'value' => $value,
                'expires' => time() + $duration
            ];

            // Use atomic write
            $tempFile = $cacheFile . '.tmp';
            if (file_put_contents($tempFile, json_encode($data)) === false) {
                throw new Exception('Failed to write cache data');
            }

            if (!rename($tempFile, $cacheFile)) {
                @unlink($tempFile);
                throw new Exception('Failed to rename cache file');
            }

            chmod($cacheFile, 0644);

            $this->logger->debug('Cache set', [
                'key' => $key,
                'expires_in' => $duration
            ]);

            return true;
        } catch (Exception $e) {
            $this->logger->error('Cache write error', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function delete(string $key): bool
    {
        $cacheFile = $this->getCacheFilePath($key);
        if (file_exists($cacheFile)) {
            return @unlink($cacheFile);
        }
        return true;
    }

    public function clear(): bool
    {
        try {
            $files = glob($this->cachePath . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            return true;
        } catch (Exception $e) {
            $this->logger->error('Cache clear error', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    private function getCacheFilePath(string $key): string
    {
        // Ensure key is safe for filesystem
        $safeKey = preg_replace('/[^a-zA-Z0-9_-]/', '_', $key);
        return $this->cachePath . '/' . $safeKey . '.cache';
    }
}
