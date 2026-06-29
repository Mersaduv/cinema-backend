<?php

namespace App\GraphQL\Queries;

use App\GraphQL\Support\DrmConfigHelper;
use App\GraphQL\Support\MediaUrlHelper;
use App\GraphQL\Support\WatchAuthGate;
use App\Models\Series;
use App\Models\SeriesEpisode;
use App\Services\Cinema\SeriesHlsPathService;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Support\Collection;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class WatchEpisodeQuery
{
    public function __construct(
        private readonly SeriesHlsPathService $seriesHlsPathService,
        private readonly MediaUrlHelper $mediaUrlHelper,
    ) {
    }

    public function __invoke(mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): array
    {
        WatchAuthGate::enforce();

        $series = Series::query()
            ->where('slug', $args['seriesSlug'])
            ->published()
            ->with(['seasons.episodes'])
            ->firstOrFail();

        $season = $series->seasons->firstWhere('season_number', (int) $args['season']);
        abort_unless($season !== null, 404, 'Season not found');

        $episode = $season->episodes->firstWhere('episode_number', (int) $args['episode']);
        abort_unless($episode !== null, 404, 'Episode not found');

        $seriesMetadata = is_array($series->metadata) ? $series->metadata : [];
        $seasonMetadata = is_array($season->metadata) ? $season->metadata : [];
        $seriesHlsBase = data_get($seriesMetadata, 'playback.hls_base_url');
        $seasonHlsBase = data_get($seasonMetadata, 'playback.hls_base_url');

        $sourceMethod = (string) ($episode->source_method ?? 'hls');
        $playback = $this->resolveEpisodePlayback(
            $episode,
            $sourceMethod,
            $seriesHlsBase,
            $seasonHlsBase,
        );
        abort_unless(
            filled($playback['hls_url']) || filled($playback['video_url']) || filled($playback['embed_url']),
            404,
            'Episode playback is not available.',
        );

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
            'dubbed_audio_url' => $this->resolveExternalDubbedAudioUrl($episode),
            'audio_tracks' => $this->normalizeAudioTracks($episode, $series),
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
        ];
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
    private function normalizeAudioTracks(SeriesEpisode $episode, Series $series): array
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

        return [
            [
                'name' => 'Original Audio',
                'language' => 'eng',
                'type' => 'original',
            ],
            [
                'name' => 'دوبله فارسی',
                'language' => 'fa',
                'type' => 'dubbed',
            ],
        ];
    }

    private function resolveExternalDubbedAudioUrl(SeriesEpisode $episode): ?string
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
