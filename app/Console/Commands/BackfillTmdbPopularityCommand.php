<?php

namespace App\Console\Commands;

use App\Models\Movie;
use App\Models\Series;
use App\Services\Cinema\CatalogPopularityPersistence;
use App\Services\TmdbService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class BackfillTmdbPopularityCommand extends Command
{
    protected $signature = 'cinema:backfill-tmdb-popularity
                            {--movies : Only backfill movies}
                            {--series : Only backfill series}
                            {--limit=0 : Max rows per media type (0 = all)}';

    protected $description = 'Backfill filter_popularity and vote_count from lightweight TMDB detail calls';

    public function handle(TmdbService $tmdbService, CatalogPopularityPersistence $persistence): int
    {
        if (! Schema::hasColumn('movies', 'filter_popularity')) {
            $this->error('Run migrations first (filter_popularity column missing).');

            return self::FAILURE;
        }

        $onlyMovies = (bool) $this->option('movies');
        $onlySeries = (bool) $this->option('series');
        $runMovies = ! $onlySeries || $onlyMovies;
        $runSeries = ! $onlyMovies || $onlySeries;
        $limit = max(0, (int) $this->option('limit'));

        $updated = 0;

        if ($runMovies) {
            $updated += $this->backfillTable(
                Movie::query()
                    ->where('publish_status', 'published')
                    ->whereNotNull('tmdb_id')
                    ->where('tmdb_id', '>', 0)
                    ->orderBy('id'),
                'movie',
                $tmdbService,
                $persistence,
                $limit,
            );
        }

        if ($runSeries) {
            $updated += $this->backfillTable(
                Series::query()
                    ->where('publish_status', 'published')
                    ->whereNotNull('tmdb_id')
                    ->where('tmdb_id', '>', 0)
                    ->orderBy('id'),
                'series',
                $tmdbService,
                $persistence,
                $limit,
            );
        }

        $this->info("Backfill complete. Updated {$updated} row(s).");

        return self::SUCCESS;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<Movie|Series>  $query
     */
    private function backfillTable(
        $query,
        string $mediaType,
        TmdbService $tmdbService,
        CatalogPopularityPersistence $persistence,
        int $limit,
    ): int {
        $updated = 0;

        if ($limit > 0) {
            $query->limit($limit);
        }

        $query->chunkById(50, function ($rows) use (&$updated, $mediaType, $tmdbService, $persistence): void {
            foreach ($rows as $row) {
                $tmdbId = (int) $row->tmdb_id;

                if ($tmdbId <= 0) {
                    continue;
                }

                try {
                    $snapshot = $tmdbService->fetchPopularitySnapshot($tmdbId, $mediaType);
                } catch (\Throwable $exception) {
                    $this->warn("Skipped {$mediaType} #{$row->id}: {$exception->getMessage()}");

                    continue;
                }

                $metadata = is_array($row->metadata) ? $row->metadata : [];
                $applied = $persistence->applyToMetadata(
                    $metadata,
                    $snapshot['popularity'],
                    $snapshot['vote_count'],
                    $mediaType === 'series' ? 'series' : 'movies',
                );

                $update = [
                    'metadata' => $applied['metadata'],
                    ...$applied['columns'],
                ];

                if ($snapshot['vote_average'] !== null && Schema::hasColumn($row->getTable(), 'filter_rating')) {
                    $update['filter_rating'] = $snapshot['vote_average'];
                }

                $row->update($update);
                $updated++;
            }
        });

        return $updated;
    }
}
