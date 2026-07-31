<?php

declare(strict_types=1);

return [
    'default' => env('QUEUE_CONNECTION', 'redis'),

    'connections' => [
        'sync' => ['driver' => 'sync'],
        'database' => [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 300,
            'after_commit' => true,
        ],
        'redis' => [
            'driver' => 'redis',
            'connection' => 'default',
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => 300,
            'block_for' => 5,
            'after_commit' => true,
        ],
    ],

    // Logical queues, highest priority first. Workers are started with
    //   php artisan queue:work --queue=realtime,notifications,default,ai,reports
    'priorities' => ['realtime', 'notifications', 'default', 'ai', 'reports'],

    'failed' => [
        'driver' => 'database-uuids',
        'database' => env('DB_CONNECTION', 'mysql'),
        'table' => 'failed_jobs',
    ],
];
