<?php

namespace App\Jobs;

use App\Jobs\Concerns\PipelineAwareJob;
use App\Models\Movie;
use App\Services\Cinema\ContentImageIngestionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessMovieImagesJob implements ShouldQueue
{
    use PipelineAwareJob;
    use Queueable;

    public int $tries = 3;

    protected string $taskKey = 'image_actors';

    public function __construct(
        public readonly int $movieId,
        /** @var array<string, mixed> */
        public readonly array $payload = [],
        public readonly ?int $pipelineId = null,
    ) {
        $this->onQueue('high');
    }

    public function handle(ContentImageIngestionService $contentImageIngestionService): void
    {
        try {
            $movie = Movie::query()->find($this->movieId);

            if (! $movie) {
                $this->pipelineSkip(['reason' => 'movie_not_found'], 'image_primary');
                $this->pipelineSkip(['reason' => 'movie_not_found']);

                return;
            }

            $this->pipelineStart('image_primary');

            try {
                $primary = $contentImageIngestionService->processMovieDeferredSecondaryImages($movie);
                $this->pipelineComplete($primary, 'image_primary');
            } catch (\Throwable $exception) {
                $this->pipelineFail($exception->getMessage(), 'image_primary');
            }

            $movie->refresh();
            $this->pipelineStart();

            $metadata = is_array($movie->metadata) ? $movie->metadata : [];
            $actors = data_get($metadata, 'tmdb.actors', []);
            $success = 0;
            $failed = 0;

            if (is_array($actors) && $actors !== []) {
                $processed = [];

                foreach ($actors as $actor) {
                    if (! is_array($actor)) {
                        continue;
                    }

                    try {
                        $batch = $contentImageIngestionService->processMetadataActorProfiles(
                            [$actor],
                            'movie',
                            $movie->id,
                        );

                        $processed[] = $batch[0] ?? $actor;
                        $success++;
                    } catch (\Throwable) {
                        $processed[] = $actor;
                        $failed++;
                    }

                    $metadata['tmdb']['actors'] = $processed;
                    $movie->update(['metadata' => $metadata]);
                }
            }

            $this->pipelineComplete([
                'actors_processed' => $success,
                'actors_failed' => $failed,
            ]);

            Log::info('ProcessMovieImagesJob completed', ['movie_id' => $this->movieId]);
        } catch (\Throwable $exception) {
            Log::warning('ProcessMovieImagesJob failed non-critically', [
                'movie_id' => $this->movieId,
                'error' => $exception->getMessage(),
            ]);

            $this->pipelineFail($exception->getMessage());
        }
    }
}
