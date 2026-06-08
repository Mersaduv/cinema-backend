<?php

namespace App\Jobs;

use App\Services\Cinema\CatalogCacheRebuildService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RebuildCatalogCacheJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 15;

    public function __construct(public bool $warm = false)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'cinema-catalog-cache-rebuild';
    }

    public function handle(CatalogCacheRebuildService $rebuildService): void
    {
        $rebuildService->rebuild(warm: $this->warm);
    }
}
