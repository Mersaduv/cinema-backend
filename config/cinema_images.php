<?php

return [
    'storage_root' => env('CINEMA_IMAGE_STORAGE_ROOT', 'E:\\content'),

    'public_base_url' => rtrim((string) env('CINEMA_IMAGE_PUBLIC_BASE_URL', ''), '/'),

    'public_prefix' => '/' . trim((string) env('CINEMA_IMAGE_PUBLIC_PREFIX', '/images'), '/'),

    'regular_folder_strategy' => 'year',

    'collection_folder' => 'collections',

    'genre_folder' => 'genres',

    'responsive_widths' => [256, 384, 640, 750, 828, 1080, 1200, 1920, 2048, 3840],

    'responsive_quality' => 65,

    'responsive_sharpen' => 60,

    'default_src_width' => 384,

    'blur_width' => 20,

    'blur_quality' => 10,

    'blur_sharpen' => 0,

    'max_download_size' => (int) env('CINEMA_IMAGE_MAX_DOWNLOAD_SIZE', 15 * 1024 * 1024),

    'download_timeout' => (int) env('CINEMA_IMAGE_DOWNLOAD_TIMEOUT', 30),

    'download_retries' => (int) env('CINEMA_IMAGE_DOWNLOAD_RETRIES', 2),

    'allowed_mime_types' => [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/svg+xml',
    ],

    'allowed_raster_mime_types' => [
        'image/jpeg',
        'image/png',
        'image/webp',
    ],

    'tmdb_image_base_url' => rtrim((string) config('services.tmdb.image_base_url', 'https://image.tmdb.org/t/p'), '/'),

    'tmdb_sync_max_actor_profiles' => (int) env('CINEMA_TMDB_SYNC_MAX_ACTOR_PROFILES', 50),

    'tmdb_sync_max_episode_stills' => (int) env('CINEMA_TMDB_SYNC_MAX_EPISODE_STILLS', 50),
];
