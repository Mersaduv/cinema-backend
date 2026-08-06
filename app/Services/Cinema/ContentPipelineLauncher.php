<?php

namespace App\Services\Cinema;

use App\Jobs\ProcessMovieImagesJob;
use App\Jobs\ProcessSeriesImagesJob;
use App\Jobs\SyncMoviePersonCreditsJob;
use App\Jobs\SyncSeriesPersonCreditsJob;
use App\Jobs\ValidateHlsManifestJob;
use App\Models\Movie;
use App\Models\Series;

class ContentPipelineLauncher
{
    public function __construct(
        private readonly PipelineOrchestrator $pipelineOrchestrator,
        private readonly PublishScheduleService $publishScheduleService,
    ) {
    }

    public function shouldOpenForIntent(string $publishStatus): bool
    {
        return in_array($publishStatus, ['published', 'scheduled'], true);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>  $imageResult
     */
    public function launchForMovie(
        Movie $movie,
        array $validated,
        array $imageResult,
        int $triggeredBy,
        bool $shouldValidateHlsAsync,
        bool $hlsUrlUnchanged,
        ?string $canonicalHlsUrl,
        string $manualHlsUrlRaw,
        ?string $subtitleSourcePath,
    ): string {
        $intendedPublishStatus = (string) ($validated['publish_status'] ?? 'draft');
        $willPublish = $this->shouldOpenForIntent($intendedPublishStatus);

        $scheduledInput = $intendedPublishStatus === 'scheduled'
            ? ($validated['scheduled_for'] ?? $movie->scheduled_for)
            : null;

        $metadata = is_array($movie->metadata) ? $movie->metadata : [];
        $metadata['pipeline']['intended_publish_status'] = $intendedPublishStatus;

        // Always open a pipeline so admin list shows «در حال پردازش» while jobs run.
        $movieUpdates = ['metadata' => $metadata];

        if ($willPublish) {
            $movieUpdates['publish_status'] = 'draft';
            $movieUpdates['published_at'] = null;
            $movieUpdates['scheduled_for'] = $intendedPublishStatus === 'scheduled'
                ? $this->publishScheduleService->resolvePublishState('scheduled', $scheduledInput)['scheduled_for']
                : $movie->scheduled_for;
        }

        $movie->update($movieUpdates);

        $taskKeys = $this->movieTaskKeys($validated);
        $pipeline = $this->pipelineOrchestrator->open(
            'movie',
            $movie->id,
            $taskKeys,
            $triggeredBy,
        );

        // Do not mark image_primary running here — ProcessMovieImagesJob calls taskStarted
        // when the worker actually picks the job up. Premature "running" leaves UI stuck
        // if the queue worker is down.

        if (! $shouldValidateHlsAsync && filled($canonicalHlsUrl)) {
            $this->pipelineOrchestrator->taskStarted($pipeline->id, 'hls_validate');
            $this->pipelineOrchestrator->taskCompleted($pipeline->id, 'hls_validate', [
                'skipped' => $hlsUrlUnchanged ? 'already_ready' : 'no_async_validation_needed',
            ]);
        } elseif ($shouldValidateHlsAsync && filled($canonicalHlsUrl)) {
            ValidateHlsManifestJob::dispatch(
                $movie->id,
                $manualHlsUrlRaw,
                $subtitleSourcePath,
                $pipeline->id,
            );
        } elseif ($willPublish) {
            $this->pipelineOrchestrator->taskStarted($pipeline->id, 'hls_validate');
            $this->pipelineOrchestrator->taskFailed(
                $pipeline->id,
                'hls_validate',
                'HLS URL is required before publishing.',
            );
        } else {
            $this->pipelineOrchestrator->taskSkipped($pipeline->id, 'hls_validate', [
                'reason' => 'no_hls_url',
            ]);
        }

        ProcessMovieImagesJob::dispatch($movie->id, [], $pipeline->id);
        $this->dispatchMovieCreditsJob($movie->id, $validated, $pipeline->id);

        return 'pending';
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function launchForSeries(Series $series, array $validated, int $triggeredBy): string
    {
        $intendedPublishStatus = (string) ($validated['publish_status'] ?? 'draft');

        if (! $this->shouldOpenForIntent($intendedPublishStatus)) {
            $this->dispatchSeriesJobsWithoutPipeline($series->id, $validated);

            return 'idle';
        }

        $scheduledInput = $intendedPublishStatus === 'scheduled'
            ? ($validated['scheduled_for'] ?? $series->scheduled_for)
            : null;

        $metadata = is_array($series->metadata) ? $series->metadata : [];
        $metadata['pipeline']['intended_publish_status'] = $intendedPublishStatus;

        $series->update([
            'metadata' => $metadata,
            'publish_status' => 'draft',
            'scheduled_for' => $intendedPublishStatus === 'scheduled'
                ? $this->publishScheduleService->resolvePublishState('scheduled', $scheduledInput)['scheduled_for']
                : $series->scheduled_for,
            'published_at' => null,
        ]);

        $taskKeys = $this->seriesTaskKeys($validated);
        $pipeline = $this->pipelineOrchestrator->open(
            'series',
            $series->id,
            $taskKeys,
            $triggeredBy,
        );

        // image_primary is marked running by ProcessSeriesImagesJob when the worker starts it.

        ProcessSeriesImagesJob::dispatch($series->id, [], $pipeline->id);
        $this->dispatchSeriesCreditsJob($series->id, $validated, $pipeline->id);

        return 'pending';
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<int, string>
     */
    private function movieTaskKeys(array $validated): array
    {
        $keys = ['image_primary', 'hls_validate', 'image_actors'];

        if ($this->movieCreditsNeeded($validated)) {
            $keys[] = 'credits_sync';
            $keys[] = 'bio_enrich';
        }

        return $keys;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<int, string>
     */
    private function seriesTaskKeys(array $validated): array
    {
        $keys = ['image_primary', 'image_actors', 'image_seasons', 'image_episodes'];

        if ($this->seriesCreditsNeeded($validated)) {
            $keys[] = 'credits_sync';
            $keys[] = 'bio_enrich';
        }

        return $keys;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function movieCreditsNeeded(array $validated): bool
    {
        $cast = collect($validated['cast'] ?? [])
            ->filter(fn ($row) => is_array($row) && (filled($row['name'] ?? null) || filled($row['tmdb_person_id'] ?? null)))
            ->values();

        $tmdbId = isset($validated['tmdb_id']) ? (int) $validated['tmdb_id'] : null;

        return $cast->isNotEmpty() || ($tmdbId !== null && $tmdbId > 0);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function seriesCreditsNeeded(array $validated): bool
    {
        $cast = collect($validated['cast'] ?? [])
            ->filter(fn ($row) => is_array($row) && (filled($row['name'] ?? null) || filled($row['tmdb_person_id'] ?? null)))
            ->values();

        if ($cast->isEmpty()) {
            $cast = collect($validated['actors'] ?? [])
                ->filter(fn ($row) => is_array($row) && filled($row['name'] ?? null))
                ->values();
        }

        $tmdbId = isset($validated['tmdb_id']) ? (int) $validated['tmdb_id'] : null;

        return $cast->isNotEmpty() || ($tmdbId !== null && $tmdbId > 0);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function dispatchSeriesJobsWithoutPipeline(int $seriesId, array $validated): void
    {
        ProcessSeriesImagesJob::dispatch($seriesId);
        $this->dispatchSeriesCreditsJob($seriesId, $validated);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function dispatchMovieCreditsJob(int $movieId, array $validated, ?int $pipelineId = null): void
    {
        $cast = collect($validated['cast'] ?? [])
            ->filter(fn ($row) => is_array($row) && (filled($row['name'] ?? null) || filled($row['tmdb_person_id'] ?? null)))
            ->values()
            ->all();

        $crew = collect($validated['crew'] ?? [])
            ->filter(fn ($row) => is_array($row))
            ->values()
            ->all();

        $tmdbId = isset($validated['tmdb_id']) ? (int) $validated['tmdb_id'] : null;

        if ($cast === [] && ($tmdbId === null || $tmdbId <= 0)) {
            if ($pipelineId) {
                $this->pipelineOrchestrator->taskSkipped($pipelineId, 'credits_sync', ['reason' => 'no_cast_or_tmdb']);
                $this->pipelineOrchestrator->taskSkipped($pipelineId, 'bio_enrich', ['reason' => 'no_cast_or_tmdb']);
            }

            return;
        }

        SyncMoviePersonCreditsJob::dispatch($movieId, $cast, $crew, $tmdbId, $pipelineId);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function dispatchSeriesCreditsJob(int $seriesId, array $validated, ?int $pipelineId = null): void
    {
        $cast = collect($validated['cast'] ?? [])
            ->filter(fn ($row) => is_array($row) && (filled($row['name'] ?? null) || filled($row['tmdb_person_id'] ?? null)))
            ->values()
            ->all();

        if ($cast === []) {
            $cast = collect($validated['actors'] ?? [])
                ->filter(fn ($row) => is_array($row) && filled($row['name'] ?? null))
                ->values()
                ->all();
        }

        $crew = collect($validated['crew'] ?? [])
            ->filter(fn ($row) => is_array($row))
            ->values()
            ->all();

        $tmdbId = isset($validated['tmdb_id']) ? (int) $validated['tmdb_id'] : null;

        if ($cast === [] && ($tmdbId === null || $tmdbId <= 0)) {
            if ($pipelineId) {
                $this->pipelineOrchestrator->taskSkipped($pipelineId, 'credits_sync', ['reason' => 'no_cast_or_tmdb']);
                $this->pipelineOrchestrator->taskSkipped($pipelineId, 'bio_enrich', ['reason' => 'no_cast_or_tmdb']);
            }

            return;
        }

        SyncSeriesPersonCreditsJob::dispatch($seriesId, $cast, $crew, $tmdbId, $pipelineId);
    }
}
