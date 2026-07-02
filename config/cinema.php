<?php

return [
    /**
     * Guzzle TLS verification when fetching remote HLS/metadata URLs from the API server.
     * Set false only if you must hit HTTPS origins with broken certs in dev.
     */
    'remote_media_ssl_verify' => env('CINEMA_REMOTE_MEDIA_SSL_VERIFY', true),
    /**
     * Progressive (MP4/MKV) files for admin "download links" live under this folder relative to each HLS package root.
     * Example: video/sources/1080/Filename.mkv
     */
    'hls_package_source_video_dir' => trim(env('CINEMA_HLS_SOURCE_VIDEO_DIR', 'video/sources'), '/'),
    /**
     * When metadata.json has no "downloads" map, probe these filenames per quality folder (in order),
     * then scan the folder for any .mkv/.mp4/.webm file.
     */
    'hls_package_source_probe_filenames' => array_values(array_filter(array_map('trim', explode(',', env(
        'CINEMA_HLS_SOURCE_PROBE_FILENAMES',
        'source.mp4,movie.mp4,download.mp4,full.mp4'
    ))))),
    'revalidation_url' => env('CINEMA_REVALIDATION_URL'),
    'revalidation_secret' => env('CINEMA_REVALIDATION_SECRET'),
    'ffmpeg_binary' => env('CINEMA_FFMPEG_BINARY', 'ffmpeg'),
    'movies_directory' => 'movies',
    'hls_directory' => 'hls',
    'assets_directory' => 'assets',
    'hls_segment_duration' => 6,
    /**
     * Optional HMAC signing for playback manifest URLs (Phase 4).
     * When empty, playback URLs are returned unchanged.
     */
    'playback_signing_secret' => env('CINEMA_PLAYBACK_SIGNING_SECRET'),
    'playback_session_ttl_seconds' => (int) env('CINEMA_PLAYBACK_SESSION_TTL_SECONDS', 3600),
    /**
     * When true, watchMovie / watchEpisode GraphQL resolvers require an authenticated user.
     */
    'watch_auth_required' => (bool) env('CINEMA_WATCH_AUTH_REQUIRED', false),
    'hls_profiles' => [
        '360p' => [
            'width' => 640,
            'height' => 360,
            'video_bitrate' => '800k',
            'maxrate' => '856k',
            'bufsize' => '1200k',
            'audio_bitrate' => '96k',
            'bandwidth' => 956000,
            'codecs' => 'avc1.4d401e,mp4a.40.2',
        ],
        '480p' => [
            'width' => 854,
            'height' => 480,
            'video_bitrate' => '1400k',
            'maxrate' => '1498k',
            'bufsize' => '2100k',
            'audio_bitrate' => '128k',
            'bandwidth' => 1528000,
            'codecs' => 'avc1.4d401f,mp4a.40.2',
        ],
        '720p' => [
            'width' => 1280,
            'height' => 720,
            'video_bitrate' => '2800k',
            'maxrate' => '2996k',
            'bufsize' => '4200k',
            'audio_bitrate' => '128k',
            'bandwidth' => 2928000,
            'codecs' => 'avc1.64001f,mp4a.40.2',
        ],
        '1080p' => [
            'width' => 1920,
            'height' => 1080,
            'video_bitrate' => '5000k',
            'maxrate' => '5350k',
            'bufsize' => '7500k',
            'audio_bitrate' => '192k',
            'bandwidth' => 5192000,
            'codecs' => 'avc1.640028,mp4a.40.2',
        ],
    ],
    'popular_hybrid_tmdb_weight' => (float) env('CINEMA_POPULAR_HYBRID_TMDB_WEIGHT', 0.6),
    'popular_hybrid_internal_weight' => (float) env('CINEMA_POPULAR_HYBRID_INTERNAL_WEIGHT', 0.4),
    'catalog' => [
        'top_rated_min_vote_count' => (int) env('CINEMA_TOP_RATED_MIN_VOTE_COUNT', 30),
        'trending_stale_hours' => (int) env('CINEMA_TRENDING_STALE_HOURS', 48),
        'trending_fallback_min_items' => (int) env('CINEMA_TRENDING_FALLBACK_MIN_ITEMS', 6),
    ],
];
