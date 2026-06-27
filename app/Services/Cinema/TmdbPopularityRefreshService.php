<?php

namespace App\Services\Cinema;

use App\Models\Movie;
use App\Models\Series;
use App\Services\TmdbService;
use Illuminate\Support\Facades\Schema;

class TmdbPopularityRefreshService
{
    public function __construct(
        private readonly TmdbService $tmdbService,
        private readonly CatalogPopularityPersistence $catalogPopularityPersistence,
        private readonly CatalogRankingService $catalogRankingService,
    ) {}

    public function refreshPublishedCatalog(): int
    {
        if (! Schema::hasColumn('movies', 'filter_popularity')) {
            return 0;
        }

        $updated = 0;
        $updated += $this->refreshTable(Movie::query(), 'movie', 'movies');
        $updated += $this->refreshTable(Series::query(), 'series', 'series');

        return $updated;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<Movie|Series>  $query
     */
    private function refreshTable($query, string $mediaType, string $table): int
    {
        $updated = 0;

        (clone $query)
            ->where('publish_status', 'published')
            ->whereNotNull('tmdb_id')
            ->where('tmdb_id', '>', 0)
            ->orderBy('id')
            ->chunkById(50, function ($rows) use (&$updated, $mediaType, $table): void {
                foreach ($rows as $row) {
                    try {
                        $snapshot = $this->tmdbService->fetchPopularitySnapshot((int) $row->tmdb_id, $mediaType);
                    } catch (\Throwable) {
                        continue;
                    }

                    $metadata = is_array($row->metadata) ? $row->metadata : [];
                    $applied = $this->catalogPopularityPersistence->applyToMetadata(
                        $metadata,
                        $snapshot['popularity'],
                        $snapshot['vote_count'],
                        $table,
                    );

                    $update = [
                        'metadata' => $applied['metadata'],
                        ...$applied['columns'],
                    ];

                    if ($snapshot['vote_average'] !== null && Schema::hasColumn($table, 'filter_rating')) {
                        $update['filter_rating'] = $snapshot['vote_average'];
                    }

                    if (Schema::hasColumn($table, 'popular_hybrid_score')) {
                        $update['popular_hybrid_score'] = $this->catalogRankingService->popularHybridScore(
                            $snapshot['popularity'] ?? $row->filter_popularity,
                            $row->internal_popularity_30d ?? 0,
                        );
                    }

                    $row->update($update);
                    $updated++;
                }
            });

        return $updated;
    }
}
