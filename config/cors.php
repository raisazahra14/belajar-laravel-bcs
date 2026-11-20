<?php

$rawOrigins = (string) env(
    'CORS_ALLOWED_ORIGINS',
    implode(',', array_filter([
        env('APP_URL', 'http://localhost'),
        'http://localhost:8000',
        'http://127.0.0.1:8000',
        'http://localhost:3000',
    ])),
);

$allowedOrigins = array_values(array_unique(array_filter(
    array_map('trim', explode(',', $rawOrigins)),
    static fn (string $origin): bool => $origin !== '' && $origin !== '*',
)));

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Membatasi akses cross-origin ke endpoint API LogistikKu hanya untuk
    | domain internal/mitra terpercaya yang terdaftar di CORS_ALLOWED_ORIGINS.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => $allowedOrigins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'Origin',
        'X-Requested-With',
        'X-CSRF-TOKEN',
    ],

    'exposed_headers' => [
        'X-API-Version',
        'Location',
        'Retry-After',
        'X-RateLimit-Limit',
        'X-RateLimit-Remaining',
    ],

    'max_age' => 3600,

    'supports_credentials' => true,

];
