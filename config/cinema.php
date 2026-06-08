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
    'media_root' => env('CINEMA_MEDIA_ROOT', 'D:\\media'),
    'media_public_base_url' => env('CINEMA_MEDIA_PUBLIC_BASE_URL', '/media'),
    'ffmpeg_binary' => env('CINEMA_FFMPEG_BINARY', 'ffmpeg'),
    'movies_directory' => 'movies',
    'hls_directory' => 'hls',
    'assets_directory' => 'assets',
    'hls_segment_duration' => 6,
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
];
