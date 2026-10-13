<?php

namespace App\Services\Cinema;

use Illuminate\Support\Facades\DB;

class CatalogCacheRebuildService
{
    public function __construct(
        private readonly CatalogCacheService $catalogCache,
        private readonly CatalogListCacheWarmer $catalogListCacheWarmer,
    ) {
    }

    public function rebuild(bool $warm = true): void
    {
        $this->catalogCache->invalidateImmediately();
        app(PublicContentCacheService::class)->forgetHomeCritical();
        $this->backfillSeriesCounts();

        if ($warm) {
            $this->catalogListCacheWarmer->warmDefaultLists();
        }
    }

    private function backfillSeriesCounts(): void
    {
        DB::statement('
            UPDATE series s
            SET
                s.seasons_count = (
                    SELECT COUNT(*) FROM series_seasons ss WHERE ss.series_id = s.id
                ),
                s.episodes_count = (
                    SELECT COUNT(*) FROM series_episodes se WHERE se.series_id = s.id
                )
        ');
    }

}
