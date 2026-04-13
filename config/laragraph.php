<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | These options control which queue connection and queue name are used when
    | dispatching node execution jobs. Individual nodes may override the queue
    | and connection by implementing the HasQueue contract.
    |
    */

    'queue' => env('LARAGRAPH_QUEUE', 'default'),

    'connection' => env('LARAGRAPH_QUEUE_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | After Commit Dispatching
    |--------------------------------------------------------------------------
    |
    | When enabled, node jobs are held until the current database transaction
    | commits before being pushed to the queue. Enable this if you call
    | Laragraph::run() inside your own transactions, otherwise workers may
    | pick up a job before the WorkflowRun row is visible in the database.
    |
    */

    'after_commit' => env('LARAGRAPH_AFTER_COMMIT', false),

    /*
    |--------------------------------------------------------------------------
    | Node Execution Limits
    |--------------------------------------------------------------------------
    |
    | Here you may configure how many times a node may be attempted and how
    | long a single execution may run before the worker times out. A timed out
    | node is marked as failed rather than silently re-queued, so set the
    | timeout generously for nodes that perform long-running I/O (e.g. LLMs).
    |
    */

    'max_node_attempts' => 3,

    'node_timeout' => 60,

    /*
    |--------------------------------------------------------------------------
    | Recursion Limit
    |--------------------------------------------------------------------------
    |
    | The maximum number of node executions permitted within a single workflow
    | run. This guards against infinite loops in cyclic graphs. The counter
    | increments once per node execution and the run is marked as failed when
    | the limit is exceeded. Individual workflows may override this value by
    | calling withRecursionLimit() on the workflow builder.
    |
    */

    'recursion_limit' => 25,

    /*
    |--------------------------------------------------------------------------
    | Pruning
    |--------------------------------------------------------------------------
    |
    | Completed and failed workflow runs are pruned from the database after the
    | number of days specified here. Running and paused runs are never pruned.
    | Schedule the model:prune Artisan command to activate automatic pruning.
    |
    */

    'prunable_after_days' => 30,

    /*
    |--------------------------------------------------------------------------
    | Retry Policy
    |--------------------------------------------------------------------------
    |
    | These defaults govern the exponential backoff applied when a node fails
    | and is retried. Each interval is multiplied by the backoff factor up to
    | the maximum. Optional jitter adds up to ±25% randomness to each interval
    | to prevent thundering herd retries against external APIs.
    |
    | Individual nodes may override these defaults by implementing HasRetryPolicy.
    |
    */

    'retry' => [
        'initial_interval' => 0.5,
        'backoff_factor' => 2.0,
        'max_interval' => 128.0,
        'jitter' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Broadcasting
    |--------------------------------------------------------------------------
    |
    | When enabled, workflow and node lifecycle events are broadcast over the
    | specified channel type. This allows real-time progress updates to be
    | pushed to connected clients via Laravel Echo or similar.
    |
    | Supported channel types: "public", "private", "presence"
    |
    */

    'broadcasting' => [
        'enabled' => env('LARAGRAPH_BROADCASTING_ENABLED', false),
        'channel_type' => env('LARAGRAPH_CHANNEL_TYPE', 'private'),
        'channel_prefix' => env('LARAGRAPH_CHANNEL_PREFIX', 'workflow.'),
    ],

];
