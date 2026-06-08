<?php

namespace App\Jobs;

use App\Models\Movie;
use App\Services\Cinema\PersonIngestionService;
use App\Services\TmdbService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncMoviePersonCreditsJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, array<string, mixed>>  $cast
     * @param  array<int, array<string, mixed>>  $crew
     */
    public function __construct(
        public readonly int $movieId,
        public readonly array $cast = [],
        public readonly array $crew = [],
        public readonly ?int $tmdbId = null,
    ) {
        $this->onQueue('default');
    }

    public function handle(PersonIngestionService $personIngestionService, TmdbService $tmdbService): void
    {
        $movie = Movie::query()->find($this->movieId);

        if ($movie === null) {
            return;
        }

        if ($this->cast !== []) {
            $personIngestionService->syncMovieCredits($movie, [
                'cast' => $this->cast,
                'crew' => $this->crew,
            ]);

            return;
        }

        $tmdbId = $this->tmdbId ?? (int) ($movie->tmdb_id ?? 0);

        if ($tmdbId <= 0) {
            return;
        }

        try {
            $remote = $tmdbService->fetchMovie($tmdbId);
            $personIngestionService->syncMovieCredits($movie, [
                'cast' => $remote['cast'] ?? [],
                'crew' => $remote['crew'] ?? [],
            ]);
        } catch (\Throwable $exception) {
            Log::warning('SyncMoviePersonCreditsJob: TMDB credits fetch failed', [
                'movie_id' => $movie->id,
                'tmdb_id' => $tmdbId,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
