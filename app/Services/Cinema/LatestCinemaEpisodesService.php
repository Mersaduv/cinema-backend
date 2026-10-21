<?php

namespace App\Services\Cinema;

use App\Models\SeriesEpisode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One newest playable episode per published series, ordered by the series
 * publish time set in the admin panel. Home landscape rail, max 12.
 */
class LatestCinemaEpisodesService
{
    public function __construct(
        private readonly ImagePresentationService $imagePresentationService,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(int $limit = 12): array
    {
        $limit = min(max($limit, 1), 20);

        $ranked = DB::table('series_episodes as e')
            ->join('series as s', 's.id', '=', 'e.series_id')
            ->where('s.publish_status', 'published')
            ->whereNotNull('s.published_at')
            ->where(function ($visibility): void {
                if (! Schema::hasColumn('series', 'catalog_visibility')) {
                    return;
                }

                $visibility->whereIn('s.catalog_visibility', ['catalog_search', 'catalog'])
                    ->orWhereNull('s.catalog_visibility');
            })
            ->where(function ($playable): void {
                $playable->where(function ($progressive): void {
                    $progressive->whereIn('e.source_method', ['url', 'upload', 'video'])
                        ->whereNotNull('e.video_url')
                        ->where('e.video_url', '!=', '');
                })->orWhere(function ($embed): void {
                    $embed->where('e.source_method', 'embed')
                        ->whereNotNull('e.embed_url')
                        ->where('e.embed_url', '!=', '');
                })->orWhere(function ($hls): void {
                    $hls->whereNotNull('e.hls_url')
                        ->where('e.hls_url', '!=', '');
                });
            })
            ->select('e.id', 's.published_at')
            ->selectRaw(
                'ROW_NUMBER() OVER (PARTITION BY e.series_id ORDER BY e.season_number DESC, e.episode_number DESC, e.air_date DESC, e.id DESC) as episode_rank',
            );

        $ids = DB::query()
            ->fromSub($ranked, 'latest')
            ->where('latest.episode_rank', 1)
            ->orderByDesc('latest.published_at')
            ->orderByDesc('latest.id')
            ->limit($limit)
            ->pluck('latest.id');

        if ($ids->isEmpty()) {
            return [];
        }

        $episodes = SeriesEpisode::query()
            ->with(['series:id,slug,title,backdrop_url,poster_url'])
            ->whereIn('id', $ids->all())
            ->get()
            ->sortBy(fn (SeriesEpisode $episode) => $ids->search($episode->id))
            ->values();

        return $episodes
            ->map(function (SeriesEpisode $episode): ?array {
                $series = $episode->series;
                if ($series === null || ! filled($series->slug)) {
                    return null;
                }

                $metadata = is_array($episode->metadata) ? $episode->metadata : [];
                $storedStill = data_get($metadata, 'images.still');
                $still = $this->imagePresentationService->enrichListImageFields(
                    $episode->still_url,
                    is_array($storedStill) ? $storedStill : null,
                );
                $stillUrl = $still['url'] ?? null;
                $backdropUrl = filled($series->backdrop_url) ? (string) $series->backdrop_url : null;
                $posterUrl = filled($series->poster_url) ? (string) $series->poster_url : null;

                return [
                    'id' => (int) $episode->id,
                    'title' => filled($episode->title) ? (string) $episode->title : null,
                    'season_number' => (int) $episode->season_number,
                    'episode_number' => (int) $episode->episode_number,
                    'updated_at' => $episode->updated_at?->toIso8601String(),
                    'still_url' => $stillUrl ?: $backdropUrl ?: $posterUrl,
                    'still_blur_url' => $still['blur_url'] ?? null,
                    'series' => [
                        'slug' => (string) $series->slug,
                        'title' => (string) $series->title,
                    ],
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
