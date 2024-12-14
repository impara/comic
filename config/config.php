<?php

// Debug mode configuration
if (!defined('DEBUG')) {
    define('DEBUG', getenv('APP_DEBUG') === 'true');  // Read from environment
}

return [
    'app' => [
        'base_url' => getenv('APP_BASE_URL') ?: 'http://localhost',
        'debug' => DEBUG,
        'log_level' => getenv('APP_LOG_LEVEL') ?: 'info',
        'log_file' => getenv('APP_LOG_FILE') ?: __DIR__ . '/../logs/app.log',
        'max_concurrent_jobs' => getenv('MAX_CONCURRENT_JOBS') ?: 3,
        'max_retries' => getenv('MAX_RETRIES') ?: 3,
        'retry_delay' => getenv('RETRY_DELAY') ?: 1,
        'job_timeout' => getenv('JOB_TIMEOUT') ?: 300,
        'worker_timeout' => getenv('WORKER_TIMEOUT') ?: 300,
        'heartbeat_interval' => getenv('HEARTBEAT_INTERVAL') ?: 30
    ],

    'replicate' => [
        'api_token' => getenv('REPLICATE_API_TOKEN'),
        'webhook_secret' => getenv('REPLICATE_WEBHOOK_SECRET'),
        'models' => [
            'txt2img' => [
                'version' => '7762fd07cf82c948538e41f63f77d685e02b063e37e496e96eefd46c929f9bdc',
                'params' => [
                    'prompt' => null,
                    'negative_prompt' => null,
                    'steps' => 30,
                    'width' => 1024,
                    'height' => 1024,
                    'seed' => 0,
                    'cfg_scale' => 7.5,
                    'samples' => 1
                ]
            ],
            'cartoonify' => [
                'version' => 'f109015d60170dfb20460f17da8cb863155823c85ece1115e1e9e4ec7ef51d3b',
                'params' => [
                    'seed' => 2862431,
                    'image' => null  // Will be set dynamically
                ]
            ],
            'describe' => [
                'version' => '50adaf2d3ad20a6f911a8a9e3ccf777b263b8596fbd2c8fc26e8888f8a0edbb5',
                'params' => [
                    'image' => null,  // Will be set dynamically
                    'clip_model_name' => "ViT-L-14/openai",
                    'mode' => "fast",
                    'min_length' => 50,
                    'max_length' => 300,
                    'temperature' => 0.7,
                    'num_images' => 1,
                    'num_beams' => 4
                ]
            ]
        ]
    ],

    'paths' => [
        'output' => __DIR__ . '/../public/generated',
        'logs' => __DIR__ . '/../logs',
        'temp' => __DIR__ . '/../public/temp'
    ],

    'image' => [
        'panel_width' => 1000,
        'panel_height' => 500,
        'padding' => 25,
        'character_scale' => 0.3,
        'character_offset_x' => 50,
        'character_offset_y' => 50,
        'max_file_size' => 5 * 1024 * 1024, // 5MB
        'allowed_types' => ['image/jpeg', 'image/png', 'image/gif']
    ],

    'negative_prompts' => [
        'ugly',
        'blurry',
        'low quality',
        'deformed',
        'disfigured',
        'mutated',
        'bad anatomy',
        'bad proportions',
        'duplicate',
        'cropped'
    ],

    'logging' => [
        'level' => getenv('LOG_LEVEL') ?: 'info',
        'debug_mode' => DEBUG
    ]
];
