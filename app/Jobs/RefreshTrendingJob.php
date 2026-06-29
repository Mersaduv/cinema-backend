<?php

namespace App\Jobs;

use App\Services\Cinema\TmdbTrendingSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RefreshTrendingJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 3600;

    public function __construct(
        public string $window = 'day',
    ) {}

    public function uniqueId(): string
    {
        return 'refresh-trending-'.$this->window.'-'.now()->format('Y-m-d');
    }

    public function handle(TmdbTrendingSyncService $trendingSyncService): void
    {
        $matched = $trendingSyncService->refresh($this->window);

        Log::info('RefreshTrendingJob completed', [
            'window' => $this->window,
            'matched' => $matched,
        ]);
    }
}
