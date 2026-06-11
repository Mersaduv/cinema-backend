<?php

namespace App\Jobs;

use App\Jobs\Concerns\PipelineAwareJob;
use App\Models\Series;
use App\Services\Cinema\PersonIngestionService;
use App\Services\TmdbService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncSeriesPersonCreditsJob implements ShouldQueue
{
    use PipelineAwareJob;
    use Queueable;

    public int $tries = 2;

    protected string $taskKey = 'credits_sync';

    /**
     * @param  array<int, array<string, mixed>>  $cast
     * @param  array<int, array<string, mixed>>  $crew
     */
    public function __construct(
        public readonly int $seriesId,
        public readonly array $cast = [],
        public readonly array $crew = [],
        public readonly ?int $tmdbId = null,
        public readonly ?int $pipelineId = null,
    ) {
    }

    public function handle(PersonIngestionService $personIngestionService, TmdbService $tmdbService): void
    {
        $this->pipelineStart('credits_sync');

        try {
            $series = Series::query()->find($this->seriesId);

            if (! $series) {
                $this->pipelineSkip(['reason' => 'series_not_found'], 'credits_sync');
                $this->pipelineSkip(['reason' => 'series_not_found'], 'bio_enrich');

                return;
            }

            $creditsData = $this->resolveCreditsData($tmdbService);
            $personIngestionService->syncSeriesCredits($series, $creditsData);

            $this->pipelineComplete([
                'cast_rows' => count($creditsData['cast'] ?? []),
                'crew_rows' => count($creditsData['crew'] ?? []),
            ], 'credits_sync');

            $this->pipelineStart('bio_enrich');
            $this->pipelineComplete(['enriched_via' => 'credits_sync_flush'], 'bio_enrich');
        } catch (\Throwable $exception) {
            Log::warning('SyncSeriesPersonCreditsJob failed non-critically', [
                'series_id' => $this->seriesId,
                'error' => $exception->getMessage(),
            ]);

            $this->pipelineFail($exception->getMessage(), 'credits_sync');
            $this->pipelineSkip(['reason' => 'credits_failed'], 'bio_enrich');
        }
    }

    /**
     * @return array{cast: array<int, array<string, mixed>>, crew: array<int, array<string, mixed>>}
     */
    private function resolveCreditsData(TmdbService $tmdbService): array
    {
        if ($this->cast !== []) {
            return [
                'cast' => $this->cast,
                'crew' => $this->crew,
            ];
        }

        if ($this->tmdbId !== null && $this->tmdbId > 0) {
            $payload = $tmdbService->fetchTv($this->tmdbId, includeSeasons: false);

            return [
                'cast' => collect($payload['cast'] ?? [])
                    ->map(fn (array $row) => $row)
                    ->values()
                    ->all(),
                'crew' => collect($payload['crew'] ?? [])
                    ->map(fn (array $row) => $row)
                    ->values()
                    ->all(),
            ];
        }

        return [
            'cast' => $this->cast,
            'crew' => $this->crew,
        ];
    }
}
