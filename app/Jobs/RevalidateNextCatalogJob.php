<?php

namespace App\Jobs;

use App\Services\Cinema\NextPublicCacheRevalidationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RevalidateNextCatalogJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 60;

    /**
     * @param  array<int, string>  $paths
     */
    public function __construct(public array $paths = [])
    {
        $this->onQueue('default');
    }

    public function handle(NextPublicCacheRevalidationService $revalidationService): void
    {
        $revalidationService->revalidateCatalog($this->paths);
    }
}
