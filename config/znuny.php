<?php

return [
    'default' => env('ZNUNY_CONNECTION', 'default'),

    'connections' => [
        'default' => [
            'base_url' => env('ZNUNY_BASE_URL'),
            'username' => env('ZNUNY_USERNAME'),
            'password' => env('ZNUNY_PASSWORD'),
            'verify_ssl' => env('ZNUNY_VERIFY_SSL', true),
            'timeout' => env('ZNUNY_TIMEOUT', 30),
            'connect_timeout' => env('ZNUNY_CONNECT_TIMEOUT', 3),
        ],
    ],

    'session' => [
        'cache_store' => env('ZNUNY_CACHE_STORE'),
        'cache_prefix' => 'znuny:session:',
        'ttl' => env('ZNUNY_SESSION_TTL', 21600),
        'auto_retry_on_expired' => true,
    ],

    'cache' => [
        'enabled' => env('ZNUNY_CACHE_ENABLED', true),
        'store' => env('ZNUNY_CACHE_STORE'),
        'prefix' => 'znuny:lookup:',
        'ttl' => [
            'queues' => 3600,
            'priorities' => 3600,
            'states' => 3600,
            'types' => 3600,
            'services' => 3600,
            'customers' => 600,
        ],
    ],

    'logging' => [
        'enabled' => env('ZNUNY_LOGGING_ENABLED', true),
        'channel' => env('ZNUNY_LOG_CHANNEL', 'znuny'),
        'log_requests' => true,
        'log_responses' => false,
        'log_errors' => true,
    ],

    'queues' => [
        'default' => env('ZNUNY_DEFAULT_QUEUE_ID', '803'),
        'resolution' => [
            'business' => [],
            'private' => [],
        ],
    ],

    'dynamic_fields' => [],

    'http' => [
        'pool_size' => 25,
        'retries' => 3,
        'retry_delay' => 100,
    ],

    'webhooks' => [
        'enabled' => false,
        'route_prefix' => 'znuny/webhooks',
        'middleware' => ['api'],
    ],
];
