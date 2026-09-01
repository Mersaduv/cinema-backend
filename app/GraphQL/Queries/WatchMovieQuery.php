<?php

namespace App\GraphQL\Queries;

use App\GraphQL\Support\DrmConfigHelper;
use App\GraphQL\Support\MediaUrlHelper;
use App\GraphQL\Support\WatchAuthGate;
use App\Models\Movie;
use GraphQL\Type\Definition\ResolveInfo;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class WatchMovieQuery
{
    public function __construct(
        private readonly MediaUrlHelper $mediaUrlHelper,
    ) {}

    public function __invoke(mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): array
    {
        WatchAuthGate::enforce();

        $movie = Movie::query()
            ->where('slug', $args['slug'])
            ->published()
            ->with('mediaAsset')
            ->firstOrFail();

        $metadata = is_array($movie->metadata) ? $movie->metadata : [];
        $playback = $metadata['playback'] ?? [];
        $downloads = $this->normalizeDownloads(data_get($playback, 'downloads', []));

        $hlsUrl = $this->mediaUrlHelper->resolveMoviePlaybackUrl($movie) ?? '';
        $progressiveUrl = $this->mediaUrlHelper->resolveMovieProgressiveUrl($movie);
        $dashUrl = data_get($playback, 'dash_url');
        $dashVerified = (bool) data_get($playback, 'dash_verified', false);

        return [
            'id' => (string) $movie->id,
            'slug' => (string) $movie->slug,
            'hls_url' => $hlsUrl !== '' ? $hlsUrl : null,
            'progressive_url' => $progressiveUrl,
            'dash_url' => filled($dashUrl) && $dashVerified ? (string) $dashUrl : null,
            'subtitle_url' => $this->mediaUrlHelper->resolveManagedOrExternalUrl($movie->subtitle_source_path),
            'dubbed_audio_url' => $this->resolveExternalDubbedAudioUrl($movie),
            'audio_tracks' => $this->normalizeAudioTracks($movie),
            'downloads' => $downloads,
            'drm' => DrmConfigHelper::fromColumns($movie->drm_scheme ?? null, $movie->drm_license_url ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $stored
     * @return list<array{quality: string, url: string, size_mb: ?int}>
     */
    private function normalizeDownloads(array $stored): array
    {
        if ($stored === []) {
            return [];
        }

        return collect($stored)
            ->filter(fn ($url, $quality) => filled($quality) && filled($url))
            ->map(fn ($url, $quality) => [
                'quality' => (string) $quality,
                'url' => (string) $url,
                'size_mb' => null,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{name: string, language: string, type: string}>
     */
    private function normalizeAudioTracks(Movie $movie): array
    {
        $stored = collect($movie->audio_tracks ?? [])
            ->map(fn (array $track) => [
                'name' => (string) ($track['label'] ?? $track['name'] ?? 'صوت'),
                'language' => (string) ($track['language'] ?? 'und'),
                'type' => (string) (
                    $track['type']
                    ?? (in_array(strtolower((string) ($track['language'] ?? '')), ['fa', 'fas', 'per'], true)
                        ? 'dubbed'
                        : 'original')
                ),
            ])
            ->values()
            ->all();

        if ($stored !== []) {
            return $stored;
        }

        $hasProgressiveDub = filled($movie->dubbed_source_path)
            && preg_match('#\.m3u8(\?.*)?$#i', (string) $movie->dubbed_source_path) !== 1;

        if (! $hasProgressiveDub && ! (bool) $movie->has_dubbed_audio) {
            return [];
        }

        if (! $hasProgressiveDub) {
            return [];
        }

        return [
            [
                'name' => 'زبان اصلی',
                'language' => 'und',
                'type' => 'original',
            ],
            [
                'name' => 'دوبله فارسی',
                'language' => 'fa',
                'type' => 'dubbed',
            ],
        ];
    }

    /**
     * External dub playback uses a plain <audio> element — HLS audio playlists belong in audio_tracks.
     */
    private function resolveExternalDubbedAudioUrl(Movie $movie): ?string
    {
        $path = $movie->dubbed_source_path;

        if (! filled($path)) {
            return null;
        }

        $resolved = $this->mediaUrlHelper->resolveManagedOrExternalUrl($path);

        if (! filled($resolved)) {
            return null;
        }

        if (preg_match('#\.m3u8(\?.*)?$#i', (string) $resolved) === 1) {
            return null;
        }

        return (string) $resolved;
    }
}
