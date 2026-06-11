<?php

namespace App\Jobs;

use App\Jobs\Concerns\PipelineAwareJob;
use App\Models\Movie;
use App\Services\Cinema\PersonIngestionService;
use App\Services\TmdbService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncMoviePersonCreditsJob implements ShouldQueue
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
        public readonly int $movieId,
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
            $movie = Movie::query()->find($this->movieId);

            if (! $movie) {
                $this->pipelineSkip(['reason' => 'movie_not_found'], 'credits_sync');
                $this->pipelineSkip(['reason' => 'movie_not_found'], 'bio_enrich');

                return;
            }

            $creditsData = $this->resolveCreditsData($tmdbService);
            $personIngestionService->syncMovieCredits($movie, $creditsData);

            $this->pipelineComplete([
                'cast_rows' => count($creditsData['cast'] ?? []),
                'crew_rows' => count($creditsData['crew'] ?? []),
            ], 'credits_sync');

            $this->pipelineStart('bio_enrich');
            $this->pipelineComplete(['enriched_via' => 'credits_sync_flush'], 'bio_enrich');
        } catch (\Throwable $exception) {
            Log::warning('SyncMoviePersonCreditsJob failed non-critically', [
                'movie_id' => $this->movieId,
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
        if ($this->cast !== [] || $this->crew !== []) {
            return [
                'cast' => $this->cast,
                'crew' => $this->crew,
            ];
        }

        if ($this->tmdbId !== null && $this->tmdbId > 0) {
            $payload = $tmdbService->fetchMovie($this->tmdbId);

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

        return ['cast' => [], 'crew' => []];
    }
}
