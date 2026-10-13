<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Keep the API open for the Next.js viewer/admin on cinema.af and local ports.
    | Browser "CORS blocked" is often a 500 without headers — exception handler
    | also forces these headers so failures stay readable in DevTools.
    |
    */

    'paths' => [
        'api/*',
        'graphql',
        'graphql/*',
        'sanctum/csrf-cookie',
        'login',
        'logout',
        'register',
        'forgot-password',
        'reset-password',
        'auth/*',
        '*',
    ],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_unique(array_filter([
        '*',
        'http://cinema.af:3000',
        'https://cinema.af:3000',
        'http://cinema.af',
        'https://cinema.af',
        'http://localhost:3000',
        'http://127.0.0.1:3000',
        env('FRONTEND_URL'),
        env('APP_FRONTEND_URL'),
    ]))),

    'allowed_origins_patterns' => [
        '#^https?://cinema\.af(:\d+)?$#',
        '#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [
        'Cache-Control',
        'Content-Language',
        'Content-Type',
        'Expires',
        'Last-Modified',
        'Pragma',
        'X-RateLimit-Limit',
        'X-RateLimit-Remaining',
    ],

    'max_age' => 86400,

    'supports_credentials' => false,

];
