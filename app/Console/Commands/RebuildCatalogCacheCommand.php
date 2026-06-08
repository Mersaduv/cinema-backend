<?php

namespace App\Console\Commands;

use App\Services\Cinema\CatalogCacheRebuildService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RebuildCatalogCacheCommand extends Command
{
    protected $signature = 'cinema:rebuild-catalog-cache {--warm : Pre-fetch public catalog endpoints}';

    protected $description = 'Clear catalog caches, backfill denormalized series counts, and optionally warm list endpoints';

    public function handle(CatalogCacheRebuildService $rebuildService): int
    {
        $this->info('Rebuilding catalog cache...');
        $rebuildService->rebuild(warm: (bool) $this->option('warm'));

        $movieCount = DB::table('movies')->count();
        $seriesCount = DB::table('series')->count();
        $publishedMovies = DB::table('movies')->where('publish_status', 'published')->count();
        $publishedSeries = DB::table('series')->where('publish_status', 'published')->count();

        $this->table(
            ['Metric', 'Count'],
            [
                ['Movies (total)', $movieCount],
                ['Movies (published)', $publishedMovies],
                ['Series (total)', $seriesCount],
                ['Series (published)', $publishedSeries],
            ],
        );

        $this->info('Catalog cache rebuild complete.');

        return self::SUCCESS;
    }
}
