<?php

namespace App\Jobs;

use App\Services\Cinema\TmdbPopularityRefreshService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RefreshTmdbPopularityJob implements ShouldQueue
{
    use Queueable;

    public function handle(TmdbPopularityRefreshService $refreshService): void
    {
        $updated = $refreshService->refreshPublishedCatalog();

        Log::info('RefreshTmdbPopularityJob completed', [
            'updated' => $updated,
        ]);
    }
}
