<?php

namespace App\Jobs;

use App\Services\Cinema\CatalogCacheRebuildService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RebuildCatalogCacheJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Collapse burst rebuilds from consecutive admin saves. */
    public int $uniqueFor = 120;

    public function __construct(public bool $warm = false)
    {
        $this->onQueue('low');
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
