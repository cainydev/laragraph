<?php

// config for Cainy/Laragraph
return [
    'queue'               => env('LARAGRAPH_QUEUE', 'default'),
    'connection'          => env('LARAGRAPH_QUEUE_CONNECTION'),
    'max_node_attempts'   => 3,
    'lock_timeout'        => 30,
    'prunable_after_days' => 30,
    'workflows'           => [],

    'broadcasting' => [
        'enabled'        => env('LARAGRAPH_BROADCASTING_ENABLED', false),
        'channel_type'   => env('LARAGRAPH_CHANNEL_TYPE', 'private'), // 'public', 'private', 'presence'
        'channel_prefix' => env('LARAGRAPH_CHANNEL_PREFIX', 'workflow.'),
    ],
];
