<?php

class CacheManager
{
    private string $cachePath;
    private LoggerInterface $logger;
    private const CACHE_DURATION = 3600; // 1 hour default cache duration
    private const CLEANUP_PROBABILITY = 0.01; // 1% chance of cleanup on each operation

    public function __construct(string $cachePath, LoggerInterface $logger)
    {
        $this->cachePath = rtrim($cachePath, '/');
        $this->logger = $logger;
        $this->ensureCacheDirectory();
    }

    private function ensureCacheDirectory(): void
    {
        if (!is_dir($this->cachePath)) {
            if (!mkdir($this->cachePath, 0775, true)) {
                throw new RuntimeException("Failed to create cache directory: {$this->cachePath}");
            }
        }

        if (!is_writable($this->cachePath)) {
            throw new RuntimeException("Cache directory is not writable: {$this->cachePath}");
        }
    }

    public function get(string $key, int $duration = self::CACHE_DURATION): mixed
    {
        $this->maybeCleanup();

        try {
            $cacheFile = $this->getCacheFilePath($key);
            if (!file_exists($cacheFile)) {
                return null;
            }

            $data = $this->readCache($cacheFile);
            if (!$data || !isset($data['timestamp']) || !isset($data['value'])) {
                $this->delete($key);
                return null;
            }

            if (time() - $data['timestamp'] > $duration) {
                $this->delete($key);
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

    public function set(string $key, mixed $value, int $duration = self::CACHE_DURATION): bool
    {
        $this->maybeCleanup();

        try {
            $cacheFile = $this->getCacheFilePath($key);
            $data = [
                'timestamp' => time(),
                'expires' => time() + $duration,
                'value' => $value
            ];

            return $this->writeCache($cacheFile, $data);
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
        try {
            $cacheFile = $this->getCacheFilePath($key);
            if (file_exists($cacheFile)) {
                return unlink($cacheFile);
            }
            return true;
        } catch (Exception $e) {
            $this->logger->error('Cache delete error', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function clear(): bool
    {
        try {
            $files = glob($this->cachePath . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
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
        $safeKey = preg_replace('/[^a-zA-Z0-9_-]/', '_', $key);
        return $this->cachePath . '/' . $safeKey . '.cache';
    }

    private function readCache(string $cacheFile): ?array
    {
        $content = file_get_contents($cacheFile);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return $data;
    }

    private function writeCache(string $cacheFile, array $data): bool
    {
        $tempFile = $cacheFile . '.tmp';
        if (file_put_contents($tempFile, json_encode($data)) === false) {
            return false;
        }

        if (!rename($tempFile, $cacheFile)) {
            unlink($tempFile);
            return false;
        }

        chmod($cacheFile, 0664);
        return true;
    }

    private function maybeCleanup(): void
    {
        if (mt_rand() / mt_getrandmax() < self::CLEANUP_PROBABILITY) {
            $this->cleanup();
        }
    }

    private function cleanup(): void
    {
        try {
            $files = glob($this->cachePath . '/*');
            $now = time();

            foreach ($files as $file) {
                if (!is_file($file)) {
                    continue;
                }

                $data = $this->readCache($file);
                if (!$data || !isset($data['expires']) || $now > $data['expires']) {
                    unlink($file);
                    $this->logger->debug('Removed expired cache file', [
                        'file' => basename($file)
                    ]);
                }
            }
        } catch (Exception $e) {
            $this->logger->error('Cache cleanup error', [
                'error' => $e->getMessage()
            ]);
        }
    }
}
