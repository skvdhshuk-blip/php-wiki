<?php

$appHost = parse_url((string) env('APP_URL', 'http://localhost:8000'), PHP_URL_HOST);

return [
    'default' => env('REVERB_SERVER', 'reverb'),

    'servers' => [
        'reverb' => [
            'host' => env('REVERB_SERVER_HOST', '0.0.0.0'),
            'port' => env('REVERB_SERVER_PORT', 8080),
            'path' => env('REVERB_SERVER_PATH', ''),
            'hostname' => env('REVERB_HOST'),
            'options' => ['tls' => []],
            'max_request_size' => env('REVERB_MAX_REQUEST_SIZE', 10_000),
            'scaling' => [
                'enabled' => false,
                'channel' => 'php-wiki-reverb',
                'server' => [],
            ],
            'pulse_ingest_interval' => 15,
            'telescope_ingest_interval' => 15,
        ],
    ],

    'apps' => [
        'provider' => 'config',
        'apps' => [[
            'key' => env('REVERB_APP_KEY', 'php-wiki-local'),
            'secret' => env('REVERB_APP_SECRET') ?: env('APP_KEY'),
            'app_id' => env('REVERB_APP_ID', 'php-wiki'),
            'options' => [
                'host' => env('REVERB_HOST', '127.0.0.1'),
                'port' => env('REVERB_PORT', 8080),
                'scheme' => env('REVERB_SCHEME', 'http'),
                'useTLS' => env('REVERB_SCHEME', 'http') === 'https',
            ],
            'allowed_origins' => array_values(array_unique(array_filter([
                is_string($appHost) ? $appHost : null,
                'localhost',
                '127.0.0.1',
            ]))),
            'ping_interval' => 60,
            'activity_timeout' => 30,
            'max_connections' => 100,
            'max_message_size' => 10_000,
            'accept_client_events_from' => 'none',
            'rate_limiting' => ['enabled' => false],
        ]],
    ],
];
