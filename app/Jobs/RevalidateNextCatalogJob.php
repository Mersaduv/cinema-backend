<?php

namespace App\Jobs;

use App\Services\Cinema\NextPublicCacheRevalidationService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RevalidateNextCatalogJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 60;

    /** Merge rapid revalidate requests from consecutive saves. */
    public int $uniqueFor = 60;

    /**
     * @param  array<int, string>  $paths
     */
    public function __construct(public array $paths = [])
    {
        $this->onQueue('low');
    }

    public function uniqueId(): string
    {
        $normalized = $this->paths;
        sort($normalized);

        return 'cinema-next-revalidate:' . hash('sha256', json_encode($normalized) ?: '');
    }

    public function handle(NextPublicCacheRevalidationService $revalidationService): void
    {
        $revalidationService->revalidateCatalog($this->paths);
    }
}
