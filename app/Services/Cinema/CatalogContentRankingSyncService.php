<?php

namespace App\Services\Cinema;

use App\Jobs\RefreshTrendingJob;
use App\Jobs\SyncCatalogContentRankingJob;
use App\Models\Movie;
use App\Models\Series;
use App\Services\TmdbService;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps catalog ranking columns aligned between admin forms and TMDB scheduled jobs.
 */
class CatalogContentRankingSyncService
{
    public function __construct(
        private readonly TmdbService $tmdbService,
        private readonly CatalogPopularityPersistence $catalogPopularityPersistence,
        private readonly CatalogRankingService $catalogRankingService,
    ) {}

    /**
     * Apply hybrid score from current row columns (form-populated or prior TMDB sync).
     */
    public function applyHybridScore(Movie|Series $row): void
    {
        if (! Schema::hasColumn($row->getTable(), 'popular_hybrid_score')) {
            return;
        }

        $score = $this->catalogRankingService->popularHybridScore(
            $row->filter_popularity,
            $row->internal_popularity_30d ?? 0,
        );

        $row->update(['popular_hybrid_score' => $score]);
    }

    /**
     * After admin save — immediate hybrid score from form/TMDB preview fields.
     */
    public function syncAfterAdminSave(Movie|Series $row): void
    {
        $row->refresh();
        $this->applyHybridScore($row);
    }

    /**
     * Fetch TMDB popularity snapshot for a single catalog row.
     */
    public function refreshFromTmdb(Movie|Series $row, bool $overwriteRating = false): bool
    {
        if (! Schema::hasColumn($row->getTable(), 'filter_popularity')) {
            $this->applyHybridScore($row);

            return false;
        }

        $tmdbId = (int) ($row->tmdb_id ?? 0);

        if ($tmdbId <= 0) {
            $this->applyHybridScore($row);

            return false;
        }

        $mediaType = $row instanceof Movie ? 'movie' : 'series';
        $table = $row->getTable();

        try {
            $snapshot = $this->tmdbService->fetchPopularitySnapshot($tmdbId, $mediaType);
        } catch (\Throwable) {
            $this->applyHybridScore($row->fresh());

            return false;
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

        if (
            $overwriteRating
            || ($snapshot['vote_average'] !== null && blank($row->filter_rating))
        ) {
            if ($snapshot['vote_average'] !== null && Schema::hasColumn($table, 'filter_rating')) {
                $update['filter_rating'] = $snapshot['vote_average'];
            }
        }

        $row->update($update);
        $this->applyHybridScore($row->fresh());

        return true;
    }

    /**
     * Queue per-item TMDB refresh (debounced) and optional daily trending list refresh.
     */
    public function scheduleDeferredRankingRefresh(
        Movie|Series $row,
        bool $includeTrendingRefresh = false,
    ): void {
        if ((int) ($row->tmdb_id ?? 0) <= 0) {
            return;
        }

        $contentType = $row instanceof Movie ? 'movie' : 'series';

        SyncCatalogContentRankingJob::dispatch($contentType, (int) $row->id)
            ->afterResponse();

        if ($includeTrendingRefresh && $row->publish_status === 'published') {
            RefreshTrendingJob::dispatch('day')->afterResponse();
        }
    }

    /**
     * Full publish path — form hybrid now, TMDB + trending async.
     */
    public function syncOnPublish(Movie|Series $row): void
    {
        $this->syncAfterAdminSave($row);
        $this->scheduleDeferredRankingRefresh($row, includeTrendingRefresh: true);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<Movie|Series>  $query
     */
    public function refreshPublishedTable($query, string $mediaType, string $table): int
    {
        $updated = 0;

        (clone $query)
            ->where('publish_status', 'published')
            ->whereNotNull('tmdb_id')
            ->where('tmdb_id', '>', 0)
            ->orderBy('id')
            ->chunkById(50, function ($rows) use (&$updated, $mediaType, $table): void {
                foreach ($rows as $row) {
                    if ($this->refreshFromTmdb($row)) {
                        $updated++;
                    }
                }
            });

        return $updated;
    }
}
