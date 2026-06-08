<?php

namespace App\GraphQL\Support;

use App\Services\Cinema\MediaPathService;

final class MediaUrlHelper
{
    public function __construct(
        private readonly MediaPathService $mediaPathService,
    ) {}

    public function resolveManagedOrExternalUrl(?string $path, ?string $externalUrl = null): ?string
    {
        if ($path && preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }

        return $this->mediaPathService->inferPublicUrlFromAbsolutePath($path) ?: $externalUrl;
    }

    public function resolveMoviePlaybackUrl(\App\Models\Movie $movie): ?string
    {
        $metadata = is_array($movie->metadata) ? $movie->metadata : [];
        $manualUrl = data_get($metadata, 'playback.hls_url');

        if (filled($manualUrl)) {
            return (string) $manualUrl;
        }

        $playlistPath = $movie->mediaAsset?->master_playlist_relative_path;

        if (! $playlistPath || $movie->processing_status !== 'ready') {
            return null;
        }

        if (preg_match('#^https?://#i', $playlistPath) === 1) {
            return $playlistPath;
        }

        return $this->mediaPathService->toPublicUrl($playlistPath);
    }
}
