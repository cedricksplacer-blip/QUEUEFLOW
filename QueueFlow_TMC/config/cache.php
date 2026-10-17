<?php

return [
    // Laravel 10 reads CACHE_STORE; the CACHE_DRIVER fallback keeps the
    // existing .env working. Without it, CACHE_STORE=array in phpunit.xml.dist
    // is silently ignored and the suite runs against the file cache, which
    // carries rate-limiter counters between tests and between runs.
    'default' => env('CACHE_STORE', env('CACHE_DRIVER', 'file')),

    'stores' => [
        'file' => [
            'driver' => 'file',
            'path' => storage_path('framework/cache/data'),
        ],
        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],
    ],

    'prefix' => env('CACHE_PREFIX', 'queueflow_cache'),
];
