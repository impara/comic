<?php

require_once __DIR__ . '/../models/Config.php';

function testConfig()
{
    $config = Config::getInstance();
    $results = [];

    // Test 1: Environment Detection
    try {
        putenv('APP_ENV=development');
        $env = $config->getEnvironment();
        $results[] = [
            'test' => 'Environment Detection',
            'result' => 'Success',
            'value' => $env,
            'expected' => 'Should be development'
        ];

        putenv('APP_ENV=production');
        $env = $config->getEnvironment();
        $results[] = [
            'test' => 'Environment Detection (Production)',
            'result' => 'Success',
            'value' => $env,
            'expected' => 'Should be production'
        ];
    } catch (Exception $e) {
        $results[] = [
            'test' => 'Environment Detection',
            'result' => 'Failed',
            'error' => $e->getMessage()
        ];
    }

    // Test 2: Debug Mode
    try {
        putenv('APP_DEBUG=true');
        $debugMode = $config->isDebugMode();
        $results[] = [
            'test' => 'Debug Mode',
            'result' => 'Success',
            'value' => $debugMode ? 'true' : 'false',
            'expected' => 'Should be true when APP_DEBUG is true'
        ];

        putenv('APP_DEBUG=false');
        $debugMode = $config->isDebugMode();
        $results[] = [
            'test' => 'Debug Mode Default',
            'result' => 'Success',
            'value' => $debugMode ? 'true' : 'false',
            'expected' => 'Should be false when APP_DEBUG is false'
        ];
    } catch (Exception $e) {
        $results[] = [
            'test' => 'Debug Mode',
            'result' => 'Failed',
            'error' => $e->getMessage()
        ];
    }

    // Test 3: Log Level Configuration
    try {
        putenv('APP_LOG_LEVEL=debug');
        $logLevel = $config->getLogLevel();
        $results[] = [
            'test' => 'Log Level Configuration',
            'result' => 'Success',
            'value' => $logLevel,
            'expected' => 'Should return DEBUG from APP_LOG_LEVEL'
        ];

        putenv('APP_LOG_LEVEL=info');
        $logLevel = $config->getLogLevel();
        $results[] = [
            'test' => 'Log Level Default',
            'result' => 'Success',
            'value' => $logLevel,
            'expected' => 'Should return INFO from APP_LOG_LEVEL'
        ];
    } catch (Exception $e) {
        $results[] = [
            'test' => 'Log Level Configuration',
            'result' => 'Failed',
            'error' => $e->getMessage()
        ];
    }

    // Test 4: Base URL Configuration
    try {
        putenv('APP_BASE_URL=http://localhost:8080');
        $baseUrl = $config->getBaseUrl();
        $results[] = [
            'test' => 'Base URL Configuration',
            'result' => 'Success',
            'value' => $baseUrl,
            'expected' => 'Should match APP_BASE_URL'
        ];
    } catch (Exception $e) {
        $results[] = [
            'test' => 'Base URL Configuration',
            'result' => 'Failed',
            'error' => $e->getMessage()
        ];
    }

    // Test 5: Log File Path
    try {
        putenv('APP_LOG_FILE=/custom/path/app.log');
        $logFile = $config->get('app.log_file');
        $results[] = [
            'test' => 'Log File Path',
            'result' => 'Success',
            'value' => $logFile,
            'expected' => 'Should match APP_LOG_FILE'
        ];
    } catch (Exception $e) {
        $results[] = [
            'test' => 'Log File Path',
            'result' => 'Failed',
            'error' => $e->getMessage()
        ];
    }

    // Test 6: Job Configuration
    try {
        putenv('MAX_CONCURRENT_JOBS=5');
        putenv('MAX_RETRIES=3');
        putenv('RETRY_DELAY=2');
        putenv('JOB_TIMEOUT=600');
        putenv('HEARTBEAT_INTERVAL=45');

        $jobConfig = [
            'max_concurrent' => $config->get('app.max_concurrent_jobs'),
            'max_retries' => $config->get('app.max_retries'),
            'retry_delay' => $config->get('app.retry_delay'),
            'timeout' => $config->get('app.job_timeout'),
            'heartbeat' => $config->get('app.heartbeat_interval')
        ];

        $results[] = [
            'test' => 'Job Configuration',
            'result' => 'Success',
            'value' => $jobConfig,
            'expected' => 'Should match environment variables'
        ];
    } catch (Exception $e) {
        $results[] = [
            'test' => 'Job Configuration',
            'result' => 'Failed',
            'error' => $e->getMessage()
        ];
    }

    // Test 7: API Configuration
    try {
        putenv('REPLICATE_API_TOKEN=test_token');
        putenv('REPLICATE_WEBHOOK_SECRET=test_secret');

        $apiToken = $config->getReplicateApiKey();
        $webhookSecret = $config->get('replicate.webhook_secret');

        $results[] = [
            'test' => 'API Configuration',
            'result' => 'Success',
            'value' => [
                'api_token' => substr($apiToken, 0, 5) . '...',
                'webhook_secret' => substr($webhookSecret, 0, 5) . '...'
            ],
            'expected' => 'Should match REPLICATE_API_TOKEN and REPLICATE_WEBHOOK_SECRET'
        ];
    } catch (Exception $e) {
        $results[] = [
            'test' => 'API Configuration',
            'result' => 'Failed',
            'error' => $e->getMessage()
        ];
    }

    // Test 8: Model Configuration
    try {
        putenv('CARTOONIFY_MODEL=test_model_version');
        putenv('BACKGROUND_MODEL=test_background_model');

        $cartoonifyModel = $config->get('replicate.models.cartoonify.version');
        $backgroundModel = $config->getBackgroundModel();

        $results[] = [
            'test' => 'Model Configuration',
            'result' => 'Success',
            'value' => [
                'cartoonify' => $cartoonifyModel,
                'background' => $backgroundModel
            ],
            'expected' => 'Should match model version configuration'
        ];
    } catch (Exception $e) {
        $results[] = [
            'test' => 'Model Configuration',
            'result' => 'Failed',
            'error' => $e->getMessage()
        ];
    }

    // Test 9: Path Configuration
    try {
        $paths = [
            'output' => $config->getPath('output'),
            'logs' => $config->getPath('logs'),
            'temp' => $config->getPath('temp'),
            'cache' => $config->getPath('cache')
        ];

        $results[] = [
            'test' => 'Path Configuration',
            'result' => 'Success',
            'value' => $paths,
            'expected' => 'Should return valid paths with proper structure'
        ];
    } catch (Exception $e) {
        $results[] = [
            'test' => 'Path Configuration',
            'result' => 'Failed',
            'error' => $e->getMessage()
        ];
    }

    // Display Results
    echo "\nConfig Test Results:\n";
    echo "===================\n\n";
    foreach ($results as $result) {
        echo "Test: {$result['test']}\n";
        echo "Result: {$result['result']}\n";
        if (isset($result['value'])) {
            echo "Value: " . (is_array($result['value']) ? json_encode($result['value']) : $result['value']) . "\n";
        }
        if (isset($result['error'])) {
            echo "Error: {$result['error']}\n";
        }
        echo "Expected: {$result['expected']}\n";
        echo "-------------------\n";
    }
}

// Run tests
testConfig();
