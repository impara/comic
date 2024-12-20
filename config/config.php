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
            'sdxl' => [
                'version' => '39ed52f2a78e934b3ba6e2a89f5b1c712de7dfea535525255b1aa35c5565e08b',
                'params' => [
                    'prompt' => null,
                    'negative_prompt' => null,
                    'num_inference_steps' => 75,
                    'guidance_scale' => 15.0,
                    'width' => 1024,
                    'height' => 1024,
                    'strength' => 0.45,
                    'high_noise_frac' => 0.9,
                    'prompt_2' => null,
                    'guidance_scale_2' => 12.0,
                    'scheduler' => "DDIM"
                ]
            ],
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
                ],
                'max_concurrent' => 2,  // Limit concurrent cartoonify operations
                'timeout' => 180,       // 3 minute timeout for cartoonify
                'retry_delay' => 5,     // Wait 5 seconds between retries
                'max_retries' => 2      // Maximum number of retries per request
            ],
            'nlp' => [
                'version' => '2c1608e18606fad2812020dc541930f2d0495ce32eee50074220b87300bc16e1',
                'params' => [
                    'prompt' => null,
                    'max_length' => 2048,
                    'temperature' => 0.75,
                    'top_p' => 0.9,
                    'repetition_penalty' => 1.2
                ]
            ]
        ]
    ],

    'comic_strip' => [
        'max_panels' => 4,
        'min_panels' => 2,
        'panel_gap' => 20,
        'strip_padding' => 40,
        'maintain_aspect_ratio' => true,
        'panel_dimensions' => [
            'width' => 1024,
            'height' => 1024
        ],
        'strip_dimensions' => [
            'max_width' => 4096,
            'max_height' => 1024
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
