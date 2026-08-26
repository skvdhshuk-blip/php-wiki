<?php

return [
    'root' => env('PHP_WIKI_ROOT', storage_path('app/wiki-workspace')),
    'allow_remote_model' => (bool) env('PHP_WIKI_ALLOW_REMOTE_MODEL', false),

    'model' => [
        'provider' => env('PHP_WIKI_PROVIDER', 'anthropic'),
        'base_url' => env('PHP_WIKI_BASE_URL', 'https://api.deepseek.com/anthropic'),
        'name' => env('PHP_WIKI_MODEL', 'deepseek-v4-flash-vision-exp'),
        'text_fallback' => env('PHP_WIKI_TEXT_FALLBACK_MODEL', 'deepseek-v4-flash'),
        'api_key' => env('PHP_WIKI_API_KEY'),
        'max_turns' => (int) env('PHP_WIKI_MAX_TURNS', 18),
        'max_tokens' => (int) env('PHP_WIKI_MAX_TOKENS', 8192),
        'max_budget_usd' => env('PHP_WIKI_MAX_BUDGET_USD') !== null
            && env('PHP_WIKI_MAX_BUDGET_USD') !== ''
                ? (float) env('PHP_WIKI_MAX_BUDGET_USD')
                : null,
    ],

    'visual' => [
        'pdf_batch_size' => (int) env('PHP_WIKI_PDF_BATCH_SIZE', 8),
        'image_max_bytes' => (int) env('PHP_WIKI_IMAGE_MAX_BYTES', 2_097_152),
        'image_max_edge' => (int) env('PHP_WIKI_IMAGE_MAX_EDGE', 2000),
    ],

    'reverb' => [
        'public_port' => (int) env('PHP_WIKI_REVERB_PORT', 8080),
    ],

    'supported_extensions' => [
        'md', 'txt', 'html', 'htm', 'pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp',
    ],
];
