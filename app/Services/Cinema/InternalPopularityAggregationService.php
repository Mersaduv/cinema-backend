<?php

namespace App\Services\Cinema;

use App\Models\ContentPlayStat;
use App\Models\Movie;
use App\Models\Series;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InternalPopularityAggregationService
{
    public function __construct(
        private readonly CatalogRankingService $catalogRankingService,
    ) {}

    public function aggregate(): int
    {
        if (! Schema::hasTable('watch_progress') || ! Schema::hasColumn('movies', 'internal_popularity_30d')) {
            return 0;
        }

        $aggregatedAt = now();
        $updated = 0;

        $movieStats = $this->aggregateMediaType('movie');
        $seriesStats = $this->aggregateMediaType('series');

        foreach ($movieStats as $mediaId => $counts) {
            $updated += $this->persistStats('movie', (int) $mediaId, $counts, $aggregatedAt, Movie::query(), 'movies');
        }

        foreach ($seriesStats as $mediaId => $counts) {
            $updated += $this->persistStats('series', (int) $mediaId, $counts, $aggregatedAt, Series::query(), 'series');
        }

        $this->zeroOutMissing(Movie::query(), 'movie', array_keys($movieStats));
        $this->zeroOutMissing(Series::query(), 'series', array_keys($seriesStats));

        return $updated;
    }

    /**
     * @return array<int, array{plays_7d: int, plays_30d: int}>
     */
    private function aggregateMediaType(string $mediaType): array
    {
        $rows = DB::table('watch_progress')
            ->select('media_id')
            ->selectRaw('COUNT(DISTINCT CASE WHEN last_watched_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN COALESCE(viewer_id, device_id, id) END) AS plays_7d')
            ->selectRaw('COUNT(DISTINCT CASE WHEN last_watched_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN COALESCE(viewer_id, device_id, id) END) AS plays_30d')
            ->where('media_type', $mediaType)
            ->where('last_watched_at', '>=', now()->subDays(30))
            ->groupBy('media_id')
            ->get();

        $stats = [];

        foreach ($rows as $row) {
            $mediaId = (int) $row->media_id;

            if ($mediaId <= 0) {
                continue;
            }

            $stats[$mediaId] = [
                'plays_7d' => (int) $row->plays_7d,
                'plays_30d' => (int) $row->plays_30d,
            ];
        }

        return $stats;
    }

    /**
     * @param  array{plays_7d: int, plays_30d: int}  $counts
     * @param  \Illuminate\Database\Eloquent\Builder<Movie|Series>  $query
     */
    private function persistStats(
        string $mediaType,
        int $mediaId,
        array $counts,
        \Illuminate\Support\Carbon $aggregatedAt,
        $query,
        string $table,
    ): int {
        ContentPlayStat::query()->updateOrCreate(
            ['media_type' => $mediaType, 'media_id' => $mediaId],
            [
                'plays_7d' => $counts['plays_7d'],
                'plays_30d' => $counts['plays_30d'],
                'aggregated_at' => $aggregatedAt,
            ],
        );

        $row = (clone $query)
            ->where('id', $mediaId)
            ->where('publish_status', 'published')
            ->first();

        if (! $row) {
            return 0;
        }

        $hybridScore = Schema::hasColumn($table, 'popular_hybrid_score')
            ? $this->catalogRankingService->popularHybridScore(
                $row->filter_popularity,
                $counts['plays_30d'],
            )
            : null;

        $row->update(array_filter([
            'internal_popularity_7d' => $counts['plays_7d'],
            'internal_popularity_30d' => $counts['plays_30d'],
            'popular_hybrid_score' => $hybridScore,
        ], fn ($value) => $value !== null));

        return 1;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<Movie|Series>  $query
     * @param  array<int, int|string>  $activeMediaIds
     */
    private function zeroOutMissing($query, string $mediaType, array $activeMediaIds): void
    {
        if ($activeMediaIds === []) {
            return;
        }

        (clone $query)
            ->where('publish_status', 'published')
            ->whereNotIn('id', $activeMediaIds)
            ->where(function ($builder): void {
                $builder
                    ->where('internal_popularity_7d', '>', 0)
                    ->orWhere('internal_popularity_30d', '>', 0);
            })
            ->update([
                'internal_popularity_7d' => 0,
                'internal_popularity_30d' => 0,
            ]);
    }
}
