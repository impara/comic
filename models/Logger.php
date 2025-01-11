<?php

class Logger implements LoggerInterface
{
    private string $logFile;
    private int $maxFileSize = 5242880; // 5MB
    private int $maxFiles = 3;
    private string $logDir;
    private Config $config;
    private array $logLevels = [
        'DEBUG' => 0,
        'INFO' => 1,
        'WARNING' => 2,
        'ERROR' => 3
    ];
    private string $logLevel;
    private bool $debugMode;

    public function __construct(?Config $config = null)
    {
        $this->config = $config ?? Config::getInstance();
        
        // Get logs directory from config and ensure it exists
        $this->logDir = rtrim($this->config->getPath('logs'), '/');
        $this->logFile = $this->logDir . '/app.log';
        
        $this->logLevel = $this->config->getLogLevel();
        $this->debugMode = $this->config->isDebugMode();

        // Ensure log directory and file exist with proper permissions
        $this->ensureLogDirectory();
    }

    private function ensureLogDirectory(): void
    {
        if (!file_exists($this->logDir)) {
            if (!mkdir($this->logDir, 0775, true)) {
                throw new RuntimeException("Failed to create log directory: {$this->logDir}");
            }
        }

        if (!file_exists($this->logFile)) {
            if (!touch($this->logFile)) {
                throw new RuntimeException("Failed to create log file: {$this->logFile}");
            }
            chmod($this->logFile, 0664);
        }

        if (!is_writable($this->logFile)) {
            throw new RuntimeException("Log file is not writable: {$this->logFile}");
        }
    }

    public function debug(string $message, array $context = []): void
    {
        if ($this->shouldLog('DEBUG')) {
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

    private function log(string $level, string $message, array $context): void
    {
        if (!$this->shouldLog($level)) {
            return;
        }

        try {
            $this->rotateLogIfNeeded();

            $timestamp = date('Y-m-d H:i:s');
            $pid = getmypid();
            $contextJson = !empty($context) ? ' ' . json_encode($this->filterContext($context)) : '';

            $logMessage = sprintf(
                "[%s] [%s] [PID:%d] %s%s\n",
                $timestamp,
                $level,
                $pid,
                $message,
                $contextJson
            );

            if (file_put_contents($this->logFile, $logMessage, FILE_APPEND) === false) {
                error_log("Failed to write to log file: {$this->logFile}");
            }
        } catch (Exception $e) {
            error_log("Logging failed: " . $e->getMessage());
        }
    }

    private function shouldLog(string $level): bool
    {
        $configLevel = strtoupper($this->config->getLogLevel());

        if (!isset($this->logLevels[$level]) || !isset($this->logLevels[$configLevel])) {
            return true; // Log if level is unknown
        }

        return $this->logLevels[$level] >= $this->logLevels[$configLevel];
    }

    private function filterContext(array $context): array
    {
        $filtered = [];
        foreach ($context as $key => $value) {
            if (is_array($value)) {
                $filtered[$key] = $this->filterContext($value);
            } elseif (is_string($value)) {
                if (preg_match('/^data:image\/[a-z]+;base64,/i', $value)) {
                    $filtered[$key] = '[BASE64_IMAGE_DATA]';
                } elseif (strlen($value) > 1000) {
                    $filtered[$key] = '[LARGE_DATA_TRUNCATED]';
                } else {
                    $filtered[$key] = $value;
                }
            } else {
                $filtered[$key] = $value;
            }
        }
        return $filtered;
    }

    private function rotateLogIfNeeded(): void
    {
        if (!file_exists($this->logFile) || filesize($this->logFile) < $this->maxFileSize) {
            return;
        }

        for ($i = $this->maxFiles - 1; $i > 0; $i--) {
            $oldFile = "{$this->logFile}.{$i}";
            $newFile = "{$this->logFile}." . ($i + 1);
            if (file_exists($oldFile)) {
                rename($oldFile, $newFile);
            }
        }

        rename($this->logFile, "{$this->logFile}.1");
        touch($this->logFile);
        chmod($this->logFile, 0664);
    }
}
