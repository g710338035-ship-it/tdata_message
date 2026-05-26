<?php

return [

    'api_id' => env('TELEGRAM_API_ID'),
    'api_hash' => env('TELEGRAM_API_HASH'),
    
    'settings' => [
        'api_id' => env('TELEGRAM_API_ID'),
        'api_hash' => env('TELEGRAM_API_HASH'),
        'ipc' => ['enabled' => false],
        'logger' => [
            'logger_level' => 3,
            'logger' => runtime_path('telegram/logs/madeline.log'),
        ],
        'connection_settings' => [
            'all' => [
                'retry_timeout' => 5,
                'max_retries' => 3,
                'pfs' => true,
                'test_mode' => false,
                'timeout' => 10,
            ],
        ],
        'serialization' => [
            'serialization_interval' => 30,
        ],
        'upload' => [
            'allow_automatic_upload' => true,
        ],
        'download' => [
            'parallel_chunk_count' => 10,
        ],
    ],
    'session_path' => runtime_path('telegram/sessions'),
    'session' => [
        'type' => 'file',
        'lock' => true,
        'gc' => [
            'probability' => 1,
            'divisor' => 1000,
            'maxlifetime' => 86400
        ],
        'serializer' => [
            'type' => 'php',
            'compress' => true
        ]
    ],
];
