<?php

require_once __DIR__ . '/../interfaces/LoggerInterface.php';

class Logger implements LoggerInterface
{
    private string $logFile;
    private int $maxFileSize;
    private int $maxFiles;
    private string $logDir;
    private array $logRates = [];
    private int $rateLimit = 60; // seconds
    private array $lastLogTimes = [];
    private array $suppressedCounts = [];

    public function __construct()
    {
        $config = Config::getInstance();

        // Test log to verify logger is working
        error_log("TEST_LOG - Logger initialized at " . date('Y-m-d H:i:s'));

        // Determine if we're in production
        $isProduction = isset($_SERVER['SERVER_NAME']) && strpos($_SERVER['SERVER_NAME'], 'comic.amertech.online') !== false;

        // Set paths based on environment
        if ($isProduction) {
            $this->logDir = '/var/www/comic.amertech.online/logs/';
        } else {
            $this->logDir = __DIR__ . '/../logs/';
        }

        $this->logFile = $this->logDir . 'comic_generator.log';
        $this->maxFileSize = 5 * 1024 * 1024; // 5MB
        $this->maxFiles = 3;

        $this->ensureLogDirectory();

        // Write initial test message to verify file is writable
        $this->error('TEST_LOG - Logger initialized', [
            'log_file' => $this->logFile,
            'log_dir' => $this->logDir,
            'debug_mode' => $config->isDebugMode(),
            'log_level' => $config->getEnv('LOG_LEVEL', 'unknown'),
            'time' => date('Y-m-d H:i:s')
        ]);
    }

    private function ensureLogDirectory(): void
    {
        // Create directory if it doesn't exist
        if (!file_exists($this->logDir)) {
            if (!mkdir($this->logDir, 0777, true)) {
                error_log("Failed to create log directory: {$this->logDir}");
                throw new RuntimeException("Failed to create log directory: {$this->logDir}");
            }
            // Set directory permissions
            chmod($this->logDir, 0777);
            // Only try to change owner in production
            if (function_exists('posix_getuid') && posix_getuid() === 0) {
                chown($this->logDir, 'www-data');
                chgrp($this->logDir, 'www-data');
            }
        }

        // Create log file if it doesn't exist
        if (!file_exists($this->logFile)) {
            if (touch($this->logFile)) {
                // Set file permissions
                chmod($this->logFile, 0666);
                // Only try to change owner in production
                if (function_exists('posix_getuid') && posix_getuid() === 0) {
                    chown($this->logFile, 'www-data');
                    chgrp($this->logFile, 'www-data');
                }
            } else {
                error_log("Failed to create log file: {$this->logFile}");
                throw new RuntimeException("Failed to create log file: {$this->logFile}");
            }
        }

        // Double check permissions
        if (!is_writable($this->logFile)) {
            error_log("Log file is not writable: {$this->logFile}");
            throw new RuntimeException("Log file is not writable: {$this->logFile}");
        }
    }

    public function debug(string $message, array $context = []): void
    {
        // Only log debug in debug mode
        if (Config::getInstance()->isDebugMode()) {
            $this->log('DEBUG', $message, $context);
        }
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('INFO', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('WARNING', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('ERROR', $message, $context);
    }

    /**
     * Filter sensitive or large data from log context
     * @param array $context Original context array
     * @return array Filtered context array
     */
    private function filterLogContext(array $context): array
    {
        $filtered = [];
        foreach ($context as $key => $value) {
            if (is_array($value)) {
                $filtered[$key] = $this->filterLogContext($value);
            } elseif (is_string($value)) {
                // Check for base64 image data
                if (preg_match('/^data:image\/[a-z]+;base64,/i', $value)) {
                    $filtered[$key] = '[BASE64_IMAGE_DATA_REMOVED]';
                } elseif (strlen($value) > 1000 && preg_match('/^[a-zA-Z0-9\/\r\n+]*={0,2}$/', $value)) {
                    // Likely base64 data without mime type
                    $filtered[$key] = '[LARGE_BASE64_DATA_REMOVED]';
                } else {
                    // Truncate long strings
                    $filtered[$key] = strlen($value) > 500 ? substr($value, 0, 500) . '...' : $value;
                }
            } else {
                $filtered[$key] = $value;
            }
        }
        return $filtered;
    }

    private function shouldLog(string $level, string $message): bool
    {
        // Always log test messages
        if (str_starts_with($message, 'TEST_LOG') || str_starts_with($message, 'DEBUG_VERIFY')) {
            return true;
        }

        // Always log errors
        if ($level === 'ERROR') {
            return true;
        }

        // Get configured log level
        $configLevel = strtoupper(Config::getInstance()->getEnv('LOG_LEVEL', 'INFO'));

        $logLevels = [
            'DEBUG' => 0,
            'INFO' => 1,
            'WARNING' => 2,
            'ERROR' => 3
        ];

        // If we're in debug mode or log level is debug, log everything
        if (Config::getInstance()->isDebugMode() || $configLevel === 'DEBUG') {
            return true;
        }

        // If level isn't recognized, allow it through
        if (!isset($logLevels[$level])) {
            return true;
        }

        return isset($logLevels[$configLevel]) && $logLevels[$level] >= $logLevels[$configLevel];
    }

    private function log(string $level, string $message, array $context): void
    {
        // Direct write for test messages and errors
        if (
            str_starts_with($message, 'TEST_LOG') ||
            str_starts_with($message, 'DEBUG_VERIFY') ||
            $level === 'ERROR'
        ) {
            $this->writeLog($level, $message, $context);
            return;
        }

        // For other messages, check if we should log
        if ($this->shouldLog($level, $message)) {
            $this->writeLog($level, $message, $context);
        }
    }

    private function writeLog(string $level, string $message, array $context): void
    {
        try {
            $this->rotateLogIfNeeded();

            // Filter sensitive data from context
            $filteredContext = $this->filterLogContext($context);

            $timestamp = date('Y-m-d H:i:s');
            $pid = getmypid();
            $contextJson = !empty($filteredContext) ? json_encode($filteredContext, JSON_UNESCAPED_SLASHES) : '';

            $logMessage = sprintf(
                "[%s] [%s] [PID:%d] %s%s%s",
                $timestamp,
                $level,
                $pid,
                $message,
                $contextJson ? " " : "",
                $contextJson
            ) . PHP_EOL;

            // Add debug info for test messages
            if (str_starts_with($message, 'TEST_LOG') || str_starts_with($message, 'DEBUG_VERIFY')) {
                $debugInfo = sprintf(
                    "[DEBUG] Log level: %s, Debug mode: %s, Config level: %s\n",
                    $level,
                    Config::getInstance()->isDebugMode() ? 'true' : 'false',
                    Config::getInstance()->getEnv('LOG_LEVEL', 'unknown')
                );
                $logMessage = $debugInfo . $logMessage;
            }

            if (!file_put_contents($this->logFile, $logMessage, FILE_APPEND | LOCK_EX)) {
                error_log("Failed to write to log file: {$this->logFile}");
                // Try to write to PHP error log as fallback
                error_log($logMessage);
            }
        } catch (Exception $e) {
            error_log("Error writing to log file: " . $e->getMessage());
            error_log($message);
        }
    }

    private function rotateLogIfNeeded(): void
    {
        if (!file_exists($this->logFile)) {
            return;
        }

        if (filesize($this->logFile) < $this->maxFileSize) {
            return;
        }

        // Rotate existing log files
        for ($i = $this->maxFiles - 1; $i >= 1; $i--) {
            $oldFile = "{$this->logFile}.{$i}";
            $newFile = "{$this->logFile}." . ($i + 1);

            if (file_exists($oldFile)) {
                rename($oldFile, $newFile);
            }
        }

        // Move current log to .1
        rename($this->logFile, "{$this->logFile}.1");

        // Create new empty log file
        touch($this->logFile);
        chmod($this->logFile, 0644);
    }

    public function getLogPath(): string
    {
        return $this->logFile;
    }
}
