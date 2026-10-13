<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env(
            'GOOGLE_REDIRECT_URI',
            rtrim((string) env('APP_URL', 'http://localhost:8005'), '/').'/auth/google/callback',
        ),
    ],

    'tmdb' => [
        'api_key' => env('TMDB_API_KEY'),
        'access_token' => env('TMDB_ACCESS_TOKEN'),
        'api_key_fallback' => env('TMDB_API_KEY_FALLBACK'),
        'access_token_fallback' => env('TMDB_ACCESS_TOKEN_FALLBACK'),
        'base_url' => env('TMDB_BASE_URL', 'https://api.themoviedb.org/3'),
        'image_base_url' => env('TMDB_IMAGE_BASE_URL', 'https://image.tmdb.org/t/p'),
        'default_language' => env('TMDB_DEFAULT_LANGUAGE', 'fa-IR'),
        'verify_ssl' => env('TMDB_VERIFY_SSL', env('APP_ENV', 'production') !== 'local'),
        'ca_bundle' => env('TMDB_CA_BUNDLE'),
    ],

    'overview_translation' => [
        'enabled' => env('OVERVIEW_TRANSLATION_ENABLED', true),
        'driver' => env('OVERVIEW_TRANSLATION_DRIVER', 'auto'),
        'mymemory_email' => env('OVERVIEW_TRANSLATION_MYMEMORY_EMAIL'),
        'libretranslate_url' => env('LIBRETRANSLATE_URL'),
        'libretranslate_api_key' => env('LIBRETRANSLATE_API_KEY'),
        'api_key' => env('OVERVIEW_TRANSLATION_API_KEY', env('OPENAI_API_KEY')),
        'base_url' => env('OVERVIEW_TRANSLATION_BASE_URL', 'https://api.openai.com/v1'),
        'model' => env('OVERVIEW_TRANSLATION_MODEL', 'gpt-4o-mini'),
        'cache_ttl_days' => (int) env('OVERVIEW_TRANSLATION_CACHE_DAYS', 90),
        'timeout' => (int) env('OVERVIEW_TRANSLATION_TIMEOUT', 45),
        'verify_ssl' => env('OVERVIEW_TRANSLATION_VERIFY_SSL', env('TMDB_VERIFY_SSL', env('APP_ENV', 'production') !== 'local')),
        'ca_bundle' => env('OVERVIEW_TRANSLATION_CA_BUNDLE', env('TMDB_CA_BUNDLE')),
    ],

];
