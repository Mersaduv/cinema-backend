<?php

namespace App\Jobs;

use App\Services\Cinema\InternalPopularityAggregationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class AggregateInternalPopularityJob implements ShouldQueue
{
    use Queueable;

    public function handle(InternalPopularityAggregationService $aggregationService): void
    {
        $updated = $aggregationService->aggregate();

        Log::info('AggregateInternalPopularityJob completed', [
            'updated' => $updated,
        ]);
    }
}
