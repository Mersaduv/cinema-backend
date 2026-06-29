<?php

namespace App\Services\Cinema;

use App\Models\Movie;
use App\Models\Series;
use Illuminate\Support\Facades\Schema;

class TmdbPopularityRefreshService
{
    public function __construct(
        private readonly CatalogContentRankingSyncService $catalogContentRankingSyncService,
    ) {}

    public function refreshPublishedCatalog(): int
    {
        if (! Schema::hasColumn('movies', 'filter_popularity')) {
            return 0;
        }

        $updated = 0;
        $updated += $this->catalogContentRankingSyncService->refreshPublishedTable(
            Movie::query(),
            'movie',
            'movies',
        );
        $updated += $this->catalogContentRankingSyncService->refreshPublishedTable(
            Series::query(),
            'series',
            'series',
        );

        return $updated;
    }
}
