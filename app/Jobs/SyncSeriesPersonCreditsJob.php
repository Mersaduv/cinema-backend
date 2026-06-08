<?php

namespace App\Jobs;

use App\Models\Series;
use App\Services\Cinema\PersonIngestionService;
use App\Services\TmdbService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncSeriesPersonCreditsJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, array<string, mixed>>  $cast
     * @param  array<int, array<string, mixed>>  $crew
     */
    public function __construct(
        public readonly int $seriesId,
        public readonly array $cast = [],
        public readonly array $crew = [],
        public readonly ?int $tmdbId = null,
    ) {
        $this->onQueue('default');
    }

    public function handle(PersonIngestionService $personIngestionService, TmdbService $tmdbService): void
    {
        $series = Series::query()->find($this->seriesId);

        if ($series === null) {
            return;
        }

        if ($this->cast !== []) {
            $personIngestionService->syncSeriesCredits($series, [
                'cast' => $this->cast,
                'crew' => $this->crew,
            ]);

            return;
        }

        $tmdbId = $this->tmdbId ?? (int) ($series->tmdb_id ?? 0);

        if ($tmdbId <= 0) {
            return;
        }

        try {
            $remote = $tmdbService->fetchTv($tmdbId);
            $personIngestionService->syncSeriesCredits($series, [
                'cast' => $remote['cast'] ?? [],
                'crew' => $remote['crew'] ?? [],
            ]);
        } catch (\Throwable $exception) {
            Log::warning('SyncSeriesPersonCreditsJob: TMDB credits fetch failed', [
                'series_id' => $series->id,
                'tmdb_id' => $tmdbId,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
