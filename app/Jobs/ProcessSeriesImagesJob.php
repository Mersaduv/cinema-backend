<?php

namespace App\Jobs;

use App\Jobs\Concerns\PipelineAwareJob;
use App\Models\Series;
use App\Services\Cinema\ContentImageIngestionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessSeriesImagesJob implements ShouldQueue
{
    use PipelineAwareJob;
    use Queueable;

    public int $tries = 3;

    protected string $taskKey = 'image_actors';

    public function __construct(
        public readonly int $seriesId,
        /** @var array<string, mixed> */
        public readonly array $payload = [],
        public readonly ?int $pipelineId = null,
    ) {
    }

    public function handle(ContentImageIngestionService $contentImageIngestionService): void
    {
        $series = Series::query()
            ->with($this->pipelineId ? ['seasons', 'episodes'] : ['seasons.episodes'])
            ->find($this->seriesId);

        if (! $series) {
            if ($this->pipelineId) {
                $this->pipelineSkip(['reason' => 'series_not_found'], 'image_actors');
                $this->pipelineSkip(['reason' => 'series_not_found'], 'image_seasons');
                $this->pipelineSkip(['reason' => 'series_not_found'], 'image_episodes');
            }

            return;
        }

        try {
            $this->pipelineStart('image_primary');
            $primary = $contentImageIngestionService->processSeriesDeferredSecondaryImages($series);
            $this->pipelineComplete($primary, 'image_primary');
            $series->refresh();
        } catch (\Throwable $exception) {
            $this->pipelineFail($exception->getMessage(), 'image_primary');
        }

        $this->pipelineStart('image_actors');

        try {
            $metadata = is_array($series->metadata) ? $series->metadata : [];
            $actors = data_get($metadata, 'tmdb.actors', []);
            $actorSuccess = 0;
            $actorFailed = 0;

            if (is_array($actors) && $actors !== []) {
                $processed = [];

                foreach ($actors as $actor) {
                    if (! is_array($actor)) {
                        continue;
                    }

                    try {
                        $batch = $contentImageIngestionService->processMetadataActorProfiles(
                            [$actor],
                            'series',
                            $series->id,
                        );

                        $processed[] = $batch[0] ?? $actor;
                        $actorSuccess++;
                    } catch (\Throwable) {
                        $processed[] = $actor;
                        $actorFailed++;
                    }

                    $metadata['tmdb']['actors'] = $processed;
                    $series->update(['metadata' => $metadata]);
                }
            }

            $this->pipelineComplete([
                'actors_processed' => $actorSuccess,
                'actors_failed' => $actorFailed,
            ], 'image_actors');
        } catch (\Throwable $exception) {
            $this->pipelineFail($exception->getMessage(), 'image_actors');
        }

        if ($this->pipelineId) {
            $this->processSeasonAndEpisodeImagesInline($series, $contentImageIngestionService);

            return;
        }

        foreach ($series->seasons as $season) {
            ProcessSeasonImagesJob::dispatch($season->id);
        }

        foreach ($series->episodes as $episode) {
            ProcessEpisodeImagesJob::dispatch($episode->id);
        }
    }

    private function processSeasonAndEpisodeImagesInline(
        Series $series,
        ContentImageIngestionService $contentImageIngestionService,
    ): void {
        $this->pipelineStart('image_seasons');

        $seasonsProcessed = 0;

        try {
            foreach ($series->seasons as $season) {
                if (! filled($season->poster_url)) {
                    continue;
                }

                $processed = $contentImageIngestionService->processSeasonPoster($season, $season->poster_url);

                if ($processed && $processed !== $season->poster_url) {
                    $season->update(['poster_url' => $processed]);
                }

                $seasonsProcessed++;
            }

            $this->pipelineComplete(['seasons_processed' => $seasonsProcessed], 'image_seasons');
        } catch (\Throwable $exception) {
            $this->pipelineFail($exception->getMessage(), 'image_seasons');
        }

        $this->pipelineStart('image_episodes');

        $episodesProcessed = 0;

        try {
            foreach ($series->episodes as $episode) {
                if (! filled($episode->still_url)) {
                    continue;
                }

                $processed = $contentImageIngestionService->processEpisodeStill($episode, $episode->still_url);

                if ($processed && $processed !== $episode->still_url) {
                    $episode->update(['still_url' => $processed]);
                }

                $episodesProcessed++;
            }

            $this->pipelineComplete(['episodes_processed' => $episodesProcessed], 'image_episodes');
        } catch (\Throwable $exception) {
            $this->pipelineFail($exception->getMessage(), 'image_episodes');
        }
    }
}
