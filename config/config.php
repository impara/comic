<?php

// Define base paths
$rootDir = dirname(__DIR__);
$publicDir = $rootDir . '/public';

return [
    'app' => [
        'base_url' => 'http://localhost', // Default value, can be overridden by APP_BASE_URL environment variable
        'debug' => false,
        'log_level' => 'info',
        'log_file' => $rootDir . '/logs/app.log',
        'max_concurrent_jobs' => 3,
        'max_retries' => 3,
        'retry_delay' => 1,
        'job_timeout' => 300,
        'heartbeat_interval' => 30
    ],

    'replicate' => [
        'api_token' => null, // Set via environment
        'webhook_secret' => null, // Set via environment
        'models' => [
            'sdxl' => [
                'version' => 'a00d0b7dcbb9c3fbb34ba87d2d5b46c56969c84a628bf778a7fdaec30b1b99c5',
                'params' => [
                    'prompt' => null,
                    'negative_prompt' => null,
                    'num_outputs' => 1,
                    'num_inference_steps' => 50,
                    'guidance_scale' => 7.5,
                    'width' => 1024,
                    'height' => 1024,
                    'scheduler' => "DPMSolverMultistep",
                    'refine' => "expert_ensemble_refiner",
                    'high_noise_frac' => 0.8,
                    'refine_steps' => 25,
                    'prompt_strength' => 0.8,
                    'apply_watermark' => false,
                    'lora_scale' => 0.6
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
                'version' => '5a6809ca6288247d06daf6365557e5e429063f32a21146b2a807c682652136b8',
                'params' => [
                    'prompt' => null,
                    'system' => "You are a comic strip scene segmentation expert. Your task is to break down stories into exactly 4 coherent scenes that can be visualized.",
                    'max_tokens' => 1000,
                    'temperature' => 0.7,
                    'top_p' => 0.9
                ],
                'timeout' => 120,       // 2 minute timeout for NLP
                'retry_delay' => 2,     // Wait 2 seconds between retries
                'max_retries' => 3      // Maximum number of retries per request
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
        'root' => $rootDir,
        'public' => $publicDir,
        'output' => '/generated',
        'logs' => $rootDir . '/logs',
        'temp' => '/temp',
        'cache' => $rootDir . '/cache'
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
        'photorealistic',
        'photo',
        'realistic',
        'text',
        'watermark',
        'signature',
        'blurry',
        'deformed',
        'disfigured',
        'bad art',
        'amateur',
        'poorly drawn',
        'ugly',
        'overly detailed background',
        'low quality background',
        'busy background',
        'multiple views',
        'multiple panels'
    ]
];
