<?php

namespace App\Services\Cinema;

use App\GraphQL\Support\DrmConfigHelper;
use App\GraphQL\Support\MediaUrlHelper;
use App\GraphQL\Support\WatchAuthGate;
use App\Models\Movie;
use App\Models\Series;
use App\Models\SeriesEpisode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class WatchPlaybackService
{
    public function __construct(
        private readonly MediaUrlHelper $mediaUrlHelper,
        private readonly SeriesHlsPathService $seriesHlsPathService,
        private readonly PublicContentCacheService $contentCache,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function movieWatchPayload(string $slug): array
    {
        WatchAuthGate::enforce();

        $cacheKey = $this->contentCache->watchMovieKey($slug);
        $ttl = $this->contentCache->watchTtl();

        return Cache::remember($cacheKey, $ttl, function () use ($slug) {
            $movie = Movie::query()
                ->where('slug', $slug)
                ->published()
                ->with('mediaAsset')
                ->firstOrFail();

            return $this->buildMovieWatchPayload($movie);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function episodeWatchPayload(string $seriesSlug, int $season, int $episode): array
    {
        WatchAuthGate::enforce();

        $cacheKey = $this->contentCache->watchEpisodeKey($seriesSlug, $season, $episode);
        $ttl = $this->contentCache->watchTtl();

        return Cache::remember($cacheKey, $ttl, function () use ($seriesSlug, $season, $episode) {
            $series = Series::query()
                ->where('slug', $seriesSlug)
                ->published()
                ->with(['seasons.episodes'])
                ->firstOrFail();

            $seasonModel = $series->seasons->firstWhere('season_number', $season);
            abort_unless($seasonModel !== null, 404, 'Season not found');

            $episodeModel = $seasonModel->episodes->firstWhere('episode_number', $episode);
            abort_unless($episodeModel !== null, 404, 'Episode not found');

            return $this->buildEpisodeWatchPayload($series, $episodeModel);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function buildMovieWatchPayload(Movie $movie): array
    {
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
            'dubbed_audio_url' => $this->resolveExternalMovieDubbedAudioUrl($movie),
            'audio_tracks' => $this->normalizeMovieAudioTracks($movie),
            'downloads' => $downloads,
            'drm' => DrmConfigHelper::fromColumns($movie->drm_scheme ?? null, $movie->drm_license_url ?? null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildEpisodeWatchPayload(Series $series, SeriesEpisode $episode): array
    {
        $seriesMetadata = is_array($series->metadata) ? $series->metadata : [];
        $season = $series->seasons->firstWhere('id', $episode->series_season_id);
        $seasonMetadata = is_array($season?->metadata) ? $season->metadata : [];
        $seriesHlsBase = data_get($seriesMetadata, 'playback.hls_base_url');
        $seasonHlsBase = data_get($seasonMetadata, 'playback.hls_base_url');

        $sourceMethod = (string) ($episode->source_method ?? 'hls');
        $playback = $this->resolveEpisodePlayback(
            $episode,
            $sourceMethod,
            $seriesHlsBase,
            $seasonHlsBase,
        );

        // Missing episode play links must not 404/hang the watch page — return a soft empty payload.
        [$nextEpisode, $prevEpisode] = $this->resolveAdjacentEpisodes(
            $series,
            $episode,
            $seriesHlsBase,
        );

        return [
            'hls_url' => $playback['hls_url'],
            'video_url' => $playback['video_url'],
            'embed_url' => $playback['embed_url'],
            'source_method' => $sourceMethod,
            'dash_url' => filled($episode->dash_url) && (bool) ($episode->dash_verified ?? false)
                ? (string) $episode->dash_url
                : null,
            'subtitle_url' => $this->mediaUrlHelper->resolveManagedOrExternalUrl($episode->subtitle_url),
            'dubbed_audio_url' => $this->resolveExternalEpisodeDubbedAudioUrl($episode),
            'audio_tracks' => $this->normalizeEpisodeAudioTracks($episode, $series),
            'drm' => DrmConfigHelper::fromColumns($series->drm_scheme ?? null, $series->drm_license_url ?? null),
            'episode' => $episode,
            'series' => [
                'id' => $series->id,
                'slug' => $series->slug,
                'title' => $series->title,
                'poster_url' => $series->poster_url,
                'publish_status' => $series->publish_status,
                'show_type' => $series->show_type,
            ],
            'next_episode' => $nextEpisode,
            'prev_episode' => $prevEpisode,
            'playback_available' => filled($playback['hls_url'])
                || filled($playback['video_url'])
                || filled($playback['embed_url']),
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
    private function normalizeMovieAudioTracks(Movie $movie): array
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
            ['name' => 'زبان اصلی', 'language' => 'und', 'type' => 'original'],
            ['name' => 'دوبله فارسی', 'language' => 'fa', 'type' => 'dubbed'],
        ];
    }

    private function resolveExternalMovieDubbedAudioUrl(Movie $movie): ?string
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

    /**
     * @return array{hls_url: ?string, video_url: ?string, embed_url: ?string}
     */
    private function resolveEpisodePlayback(
        SeriesEpisode $episode,
        string $sourceMethod,
        ?string $seriesHlsBase,
        ?string $seasonHlsBase,
    ): array {
        if ($sourceMethod === 'embed') {
            $embedUrl = $this->mediaUrlHelper->resolveManagedOrExternalUrl($episode->embed_url);

            return [
                'hls_url' => null,
                'video_url' => null,
                'embed_url' => filled($embedUrl) ? (string) $embedUrl : null,
            ];
        }

        if (in_array($sourceMethod, ['url', 'upload', 'video'], true)) {
            $videoUrl = $this->mediaUrlHelper->resolveManagedOrExternalUrl($episode->video_url);

            return [
                'hls_url' => null,
                'video_url' => filled($videoUrl) ? (string) $videoUrl : null,
                'embed_url' => null,
            ];
        }

        $hlsUrl = $this->resolvePlayableHlsUrl($episode, $seriesHlsBase, $seasonHlsBase);

        return [
            'hls_url' => $hlsUrl,
            'video_url' => null,
            'embed_url' => null,
        ];
    }

    /**
     * @return array{0: ?SeriesEpisode, 1: ?SeriesEpisode}
     */
    private function resolveAdjacentEpisodes(
        Series $series,
        SeriesEpisode $current,
        ?string $seriesHlsBase,
    ): array {
        $ordered = $this->orderedPlayableEpisodes($series, $seriesHlsBase);
        $index = $ordered->search(fn (SeriesEpisode $episode) => $episode->id === $current->id);

        if ($index === false) {
            return [null, null];
        }

        $position = (int) $index;

        return [
            $ordered->get($position + 1),
            $position > 0 ? $ordered->get($position - 1) : null,
        ];
    }

    /**
     * @return Collection<int, SeriesEpisode>
     */
    private function orderedPlayableEpisodes(Series $series, ?string $seriesHlsBase): Collection
    {
        return $series->seasons
            ->sortBy('season_number')
            ->flatMap(function ($season) use ($seriesHlsBase) {
                $seasonMetadata = is_array($season->metadata) ? $season->metadata : [];
                $seasonHlsBase = data_get($seasonMetadata, 'playback.hls_base_url');

                return $season->episodes
                    ->sortBy('episode_number')
                    ->filter(function (SeriesEpisode $episode) use ($seriesHlsBase, $seasonHlsBase) {
                        $sourceMethod = (string) ($episode->source_method ?? 'hls');
                        $playback = $this->resolveEpisodePlayback(
                            $episode,
                            $sourceMethod,
                            $seriesHlsBase,
                            $seasonHlsBase,
                        );

                        return filled($playback['hls_url'])
                            || filled($playback['video_url'])
                            || filled($playback['embed_url']);
                    });
            })
            ->values();
    }

    private function resolvePlayableHlsUrl(
        SeriesEpisode $episode,
        ?string $seriesHlsBase,
        ?string $seasonHlsBase,
    ): ?string {
        if (data_get($episode->metadata, 'playback.hls_verified') === false) {
            return null;
        }

        $resolved = $this->seriesHlsPathService->resolveEpisodeHlsUrl(
            $seriesHlsBase,
            $seasonHlsBase,
            $episode->hls_url,
            $episode->season_number,
            $episode->episode_number,
        );

        $url = $resolved['hls_url'] ?? null;

        if (! filled($url)) {
            return null;
        }

        if (data_get($episode->metadata, 'playback.hls_verified') === true) {
            return (string) $url;
        }

        if (filled($episode->hls_url)) {
            return (string) $url;
        }

        return null;
    }

    /**
     * @return list<array{name: string, language: string, type: string}>
     */
    private function normalizeEpisodeAudioTracks(SeriesEpisode $episode, Series $series): array
    {
        $metadata = is_array($episode->metadata) ? $episode->metadata : [];
        $stored = data_get($metadata, 'playback.audio_tracks', []);

        if (is_array($stored) && $stored !== []) {
            return collect($stored)
                ->filter(fn ($track) => is_array($track))
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
        }

        if (! (bool) ($series->has_dubbed ?? false) && ! filled($episode->dubbed_audio_url)) {
            return [];
        }

        $hasProgressiveDub = filled($episode->dubbed_audio_url)
            && preg_match('#\.m3u8(\?.*)?$#i', (string) $episode->dubbed_audio_url) !== 1;

        if (! $hasProgressiveDub) {
            return [];
        }

        return [
            ['name' => 'زبان اصلی', 'language' => 'und', 'type' => 'original'],
            ['name' => 'دوبله فارسی', 'language' => 'fa', 'type' => 'dubbed'],
        ];
    }

    private function resolveExternalEpisodeDubbedAudioUrl(SeriesEpisode $episode): ?string
    {
        $path = $episode->dubbed_audio_url;

        if (! filled($path)) {
            return null;
        }

        $resolved = $this->mediaUrlHelper->resolveManagedOrExternalUrl((string) $path);

        if (! filled($resolved)) {
            return null;
        }

        if (preg_match('#\.m3u8(\?.*)?$#i', (string) $resolved) === 1) {
            return null;
        }

        return (string) $resolved;
    }
}
