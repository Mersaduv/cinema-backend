<?php

namespace App\Services\Cinema;

use App\Models\Movie;
use App\Models\Series;
use App\Services\TmdbService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class TmdbTrendingSyncService
{
    public function __construct(
        private readonly TmdbService $tmdbService,
        private readonly CatalogPopularityPersistence $catalogPopularityPersistence,
    ) {}

    public function refresh(string $window = 'day'): int
    {
        if (! Schema::hasColumn('movies', 'trending_rank')) {
            return 0;
        }

        $syncedAt = now();
        $matched = 0;

        $matched += $this->applyTrendingList(
            $this->tmdbService->fetchTrendingMovies($window),
            Movie::query(),
            'movies',
            $window,
            $syncedAt,
        );

        $matched += $this->applyTrendingList(
            $this->tmdbService->fetchTrendingSeries($window),
            Series::query(),
            'series',
            $window,
            $syncedAt,
        );

        $this->clearStaleTrending(Movie::query(), $window, $syncedAt);
        $this->clearStaleTrending(Series::query(), $window, $syncedAt);

        return $matched;
    }

    /**
     * @param  array<int, array{id: int, rank: int, popularity: ?float, vote_average: ?float, vote_count: ?int}>  $trending
     * @param  \Illuminate\Database\Eloquent\Builder<Movie|Series>  $query
     */
    private function applyTrendingList(
        array $trending,
        $query,
        string $table,
        string $window,
        \Illuminate\Support\Carbon $syncedAt,
    ): int {
        $matched = 0;
        $matchedTmdbIds = [];

        foreach ($trending as $item) {
            $tmdbId = (int) ($item['id'] ?? 0);
            $rank = (int) ($item['rank'] ?? 0);

            if ($tmdbId <= 0 || $rank <= 0) {
                continue;
            }

            $row = (clone $query)
                ->where('tmdb_id', $tmdbId)
                ->where('publish_status', 'published')
                ->first();

            if (! $row) {
                Log::info('TMDB trending item not in catalog', [
                    'table' => $table,
                    'tmdb_id' => $tmdbId,
                    'rank' => $rank,
                ]);

                continue;
            }

            $matchedTmdbIds[] = $tmdbId;
            $metadata = is_array($row->metadata) ? $row->metadata : [];
            $applied = $this->catalogPopularityPersistence->applyToMetadata(
                $metadata,
                $item['popularity'] ?? null,
                $item['vote_count'] ?? null,
                $table,
            );

            $update = [
                'trending_rank' => $rank,
                'trending_window' => $window,
                'trending_synced_at' => $syncedAt,
                'metadata' => $applied['metadata'],
                ...$applied['columns'],
            ];

            if (isset($item['vote_average']) && Schema::hasColumn($table, 'filter_rating')) {
                $update['filter_rating'] = $item['vote_average'];
            }

            $row->update($update);
            $matched++;
        }

        return $matched;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<Movie|Series>  $query
     * @param  array<int, int>  $matchedTmdbIds
     */
    private function clearStaleTrending($query, string $window, \Illuminate\Support\Carbon $syncedAt): void
    {
        (clone $query)
            ->where('publish_status', 'published')
            ->where(function ($builder) use ($window, $syncedAt): void {
                $builder
                    ->whereNull('trending_synced_at')
                    ->orWhere('trending_synced_at', '<', $syncedAt)
                    ->orWhere('trending_window', '!=', $window);
            })
            ->update([
                'trending_rank' => null,
                'trending_window' => null,
                'trending_synced_at' => null,
            ]);
    }
}
