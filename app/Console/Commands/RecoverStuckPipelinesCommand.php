<?php

namespace App\Console\Commands;

use App\Jobs\ProcessMovieImagesJob;
use App\Jobs\ProcessSeriesImagesJob;
use App\Jobs\SyncMoviePersonCreditsJob;
use App\Jobs\SyncSeriesPersonCreditsJob;
use App\Models\ContentPipeline;
use App\Models\Movie;
use App\Models\Series;
use App\Services\Cinema\PipelineOrchestrator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Recover pipelines left "running" when the queue worker was down or a job vanished.
 */
class RecoverStuckPipelinesCommand extends Command
{
    protected $signature = 'cinema:recover-stuck-pipelines
                            {--minutes=10 : Consider pipelines stuck after this many minutes}
                            {--fail-after=60 : Fail critical stuck tasks after this many minutes}';

    protected $description = 'Re-dispatch or fail content pipelines stuck in running/pending state';

    public function handle(PipelineOrchestrator $orchestrator): int
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $failAfter = max($minutes, (int) $this->option('fail-after'));
        $cutoff = now()->subMinutes($minutes);
        $failCutoff = now()->subMinutes($failAfter);

        $pipelines = ContentPipeline::query()
            ->whereIn('status', ['pending', 'running'])
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('id')
            ->get();

        if ($pipelines->isEmpty()) {
            $this->info('No stuck pipelines found.');

            return self::SUCCESS;
        }

        $recovered = 0;
        $failed = 0;

        foreach ($pipelines as $pipeline) {
            $tasks = DB::table('content_pipeline_tasks')
                ->where('pipeline_id', $pipeline->id)
                ->orderBy('id')
                ->get();

            $hasQueuedWork = $this->queueHasPendingWork();

            if ($hasQueuedWork) {
                $this->line("Pipeline #{$pipeline->id}: queue has pending jobs — waiting for worker.");

                continue;
            }

            foreach ($tasks as $task) {
                if (! in_array($task->status, ['pending', 'running'], true)) {
                    continue;
                }

                $taskAgeAnchor = $task->started_at ?? $task->updated_at ?? $pipeline->updated_at;
                $taskTooOld = $taskAgeAnchor && $taskAgeAnchor <= $failCutoff;

                if ($task->is_critical && $taskTooOld) {
                    $orchestrator->taskFailed(
                        (int) $pipeline->id,
                        (string) $task->task_key,
                        'Pipeline task timed out (queue worker idle / job missing).',
                    );
                    $failed++;
                    $this->warn("Failed stuck critical task {$task->task_key} on pipeline #{$pipeline->id}");

                    continue;
                }

                if ($this->redispatchTask($pipeline, (string) $task->task_key)) {
                    if ($task->status === 'running') {
                        DB::table('content_pipeline_tasks')
                            ->where('id', $task->id)
                            ->update([
                                'status' => 'pending',
                                'started_at' => null,
                                'updated_at' => now(),
                            ]);
                    }
                    $recovered++;
                    $this->info("Re-dispatched {$task->task_key} for {$pipeline->entity_type}#{$pipeline->entity_id}");
                }
            }
        }

        Log::warning('RecoverStuckPipelinesCommand finished', [
            'checked' => $pipelines->count(),
            'redispatched' => $recovered,
            'failed' => $failed,
        ]);

        $this->info("Checked {$pipelines->count()} pipelines; redispatched={$recovered}, failed={$failed}");

        return self::SUCCESS;
    }

    private function queueHasPendingWork(): bool
    {
        return DB::table('jobs')->exists();
    }

    private function redispatchTask(ContentPipeline $pipeline, string $taskKey): bool
    {
        if ($pipeline->entity_type === 'movie') {
            $movie = Movie::query()->find($pipeline->entity_id);
            if (! $movie) {
                return false;
            }

            return match ($taskKey) {
                'image_primary', 'image_actors' => tap(true, fn () => ProcessMovieImagesJob::dispatch(
                    $movie->id,
                    [],
                    $pipeline->id,
                )),
                'credits_sync', 'bio_enrich' => tap(true, fn () => SyncMoviePersonCreditsJob::dispatch(
                    $movie->id,
                    data_get($movie->metadata, 'tmdb.cast', data_get($movie->metadata, 'tmdb.actors', [])) ?: [],
                    data_get($movie->metadata, 'tmdb.crew', []) ?: [],
                    (int) (data_get($movie->metadata, 'tmdb.tmdb_id') ?: 0) ?: null,
                    $pipeline->id,
                )),
                default => false,
            };
        }

        if ($pipeline->entity_type === 'series') {
            $series = Series::query()->find($pipeline->entity_id);
            if (! $series) {
                return false;
            }

            return match ($taskKey) {
                'image_primary', 'image_actors', 'image_seasons', 'image_episodes' => tap(true, fn () => ProcessSeriesImagesJob::dispatch(
                    $series->id,
                    [],
                    $pipeline->id,
                )),
                'credits_sync', 'bio_enrich' => tap(true, fn () => SyncSeriesPersonCreditsJob::dispatch(
                    $series->id,
                    data_get($series->metadata, 'tmdb.cast', data_get($series->metadata, 'tmdb.actors', [])) ?: [],
                    data_get($series->metadata, 'tmdb.crew', []) ?: [],
                    (int) (data_get($series->metadata, 'tmdb.tmdb_id') ?: 0) ?: null,
                    $pipeline->id,
                )),
                default => false,
            };
        }

        return false;
    }
}
