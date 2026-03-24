<?php

// config for Cainy/Laragraph
return [
    'queue' => env('LARAGRAPH_QUEUE', 'default'),
    'connection' => env('LARAGRAPH_QUEUE_CONNECTION'),
    'max_node_attempts' => 3,
    'node_timeout' => 60,
    'recursion_limit' => 25,
    'prunable_after_days' => 30,
    'workflows' => [],

    'retry' => [
        'initial_interval' => 0.5,
        'backoff_factor' => 2.0,
        'max_interval' => 128.0,
        'jitter' => true,
    ],

    'broadcasting' => [
        'enabled' => env('LARAGRAPH_BROADCASTING_ENABLED', false),
        'channel_type' => env('LARAGRAPH_CHANNEL_TYPE', 'private'), // 'public', 'private', 'presence'
        'channel_prefix' => env('LARAGRAPH_CHANNEL_PREFIX', 'workflow.'),
    ],
];
