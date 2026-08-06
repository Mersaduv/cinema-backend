<?php

namespace App\Services\Cinema;

use App\Models\ContentPipeline;
use App\Models\Movie;
use App\Models\Series;
use Illuminate\Support\Facades\DB;

class PipelineOrchestrator
{
    /**
     * @param  array<int, string>  $taskKeys
     */
    public function open(
        string $entityType,
        int $entityId,
        array $taskKeys,
        int $triggeredBy,
    ): ContentPipeline {
        $this->cancelRunningPipeline($entityType, $entityId);

        $pipeline = ContentPipeline::create([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'status' => 'pending',
            'triggered_by' => $triggeredBy,
            'triggered_at' => now(),
            'total_tasks' => count($taskKeys),
        ]);

        $taskDefinitions = $this->resolveTaskDefinitions($entityType);
        $taskRows = [];

        foreach ($taskKeys as $key) {
            $def = $taskDefinitions[$key] ?? null;

            if ($def === null) {
                continue;
            }

            $taskRows[] = [
                'pipeline_id' => $pipeline->id,
                'task_key' => $key,
                'task_label' => $def['label'],
                'priority' => $def['priority'],
                'is_critical' => $def['critical'] ? 1 : 0,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($taskRows !== []) {
            DB::table('content_pipeline_tasks')->insert($taskRows);
        }

        $this->updateEntityPipelineStatus($entityType, $entityId, 'pending');

        return $pipeline;
    }

    public function taskStarted(int $pipelineId, string $taskKey): void
    {
        if (! $this->pipelineAcceptsUpdates($pipelineId)) {
            return;
        }

        DB::table('content_pipeline_tasks')
            ->where('pipeline_id', $pipelineId)
            ->where('task_key', $taskKey)
            ->update([
                'status' => 'running',
                'started_at' => now(),
                'attempt_count' => DB::raw('attempt_count + 1'),
                'updated_at' => now(),
            ]);

        ContentPipeline::query()
            ->where('id', $pipelineId)
            ->where('status', 'pending')
            ->update(['status' => 'running', 'started_at' => now()]);

        $pipeline = ContentPipeline::query()->find($pipelineId);

        if ($pipeline) {
            $this->updateEntityPipelineStatus($pipeline->entity_type, $pipeline->entity_id, 'running');
        }
    }

    /**
     * @param  array<string, mixed>  $resultSummary
     */
    public function taskCompleted(int $pipelineId, string $taskKey, array $resultSummary = []): void
    {
        if (! $this->pipelineAcceptsUpdates($pipelineId)) {
            return;
        }

        DB::table('content_pipeline_tasks')
            ->where('pipeline_id', $pipelineId)
            ->where('task_key', $taskKey)
            ->whereNotIn('status', ['completed', 'failed', 'skipped'])
            ->update([
                'status' => 'completed',
                'completed_at' => now(),
                'result_summary' => json_encode($resultSummary),
                'updated_at' => now(),
            ]);

        ContentPipeline::query()
            ->where('id', $pipelineId)
            ->increment('completed_tasks');

        $this->checkPipelineCompletion($pipelineId);
    }

    public function taskSkipped(int $pipelineId, string $taskKey, array $resultSummary = []): void
    {
        if (! $this->pipelineAcceptsUpdates($pipelineId)) {
            return;
        }

        DB::table('content_pipeline_tasks')
            ->where('pipeline_id', $pipelineId)
            ->where('task_key', $taskKey)
            ->update([
                'status' => 'skipped',
                'completed_at' => now(),
                'result_summary' => json_encode($resultSummary),
                'updated_at' => now(),
            ]);

        ContentPipeline::query()
            ->where('id', $pipelineId)
            ->increment('completed_tasks');

        $this->checkPipelineCompletion($pipelineId);
    }

    public function taskFailed(int $pipelineId, string $taskKey, string $errorMessage): void
    {
        if (! $this->pipelineAcceptsUpdates($pipelineId)) {
            return;
        }

        $task = DB::table('content_pipeline_tasks')
            ->where('pipeline_id', $pipelineId)
            ->where('task_key', $taskKey)
            ->first();

        if (! $task) {
            return;
        }

        DB::table('content_pipeline_tasks')
            ->where('pipeline_id', $pipelineId)
            ->where('task_key', $taskKey)
            ->update([
                'status' => 'failed',
                'failed_at' => now(),
                'error_message' => $errorMessage,
                'updated_at' => now(),
            ]);

        ContentPipeline::query()
            ->where('id', $pipelineId)
            ->increment('failed_tasks');

        if ((bool) $task->is_critical) {
            $this->failPipeline($pipelineId, $errorMessage);
        } else {
            ContentPipeline::query()
                ->where('id', $pipelineId)
                ->increment('completed_tasks');

            $this->checkPipelineCompletion($pipelineId);
        }
    }

    private function checkPipelineCompletion(int $pipelineId): void
    {
        DB::transaction(function () use ($pipelineId): void {
            $pipeline = ContentPipeline::query()->lockForUpdate()->find($pipelineId);

            if (! $pipeline || $pipeline->status !== 'running') {
                return;
            }

            if ($pipeline->completed_tasks < $pipeline->total_tasks) {
                return;
            }

            $pipeline->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            $this->updateEntityPipelineStatus($pipeline->entity_type, $pipeline->entity_id, 'completed');
            $this->autoPublishEntity($pipeline->entity_type, $pipeline->entity_id);
        });
    }

    private function autoPublishEntity(string $entityType, int $entityId): void
    {
        $model = $entityType === 'movie'
            ? Movie::query()->find($entityId)
            : Series::query()->find($entityId);

        if (! $model) {
            return;
        }

        $metadata = is_array($model->metadata) ? $model->metadata : [];
        $intendedStatus = data_get($metadata, 'pipeline.intended_publish_status');

        if ($intendedStatus === 'published') {
            $model->update([
                'publish_status' => 'published',
                'published_at' => now(),
            ]);
        } elseif ($intendedStatus === 'scheduled') {
            $model->update([
                'publish_status' => 'scheduled',
            ]);
        }

        app(CatalogCacheService::class)->scheduleRebuildAfterContentChange(
            $entityType === 'movie' ? ['/movies'] : ['/series'],
        );
    }

    private function failPipeline(int $pipelineId, string $errorSummary): void
    {
        $pipeline = ContentPipeline::query()->find($pipelineId);

        if (! $pipeline || in_array($pipeline->status, ['completed', 'failed', 'cancelled'], true)) {
            return;
        }

        $pipeline->update([
            'status' => 'failed',
            'failed_at' => now(),
            'error_summary' => $errorSummary,
        ]);

        $this->updateEntityPipelineStatus($pipeline->entity_type, $pipeline->entity_id, 'failed');
    }

    private function updateEntityPipelineStatus(string $entityType, int $entityId, string $status): void
    {
        $table = $entityType === 'movie' ? 'movies' : 'series';

        DB::table($table)
            ->where('id', $entityId)
            ->update(['pipeline_status' => $status]);
    }

    private function cancelRunningPipeline(string $entityType, int $entityId): void
    {
        $runningIds = ContentPipeline::query()
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->whereIn('status', ['pending', 'running'])
            ->pluck('id')
            ->all();

        if ($runningIds === []) {
            return;
        }

        ContentPipeline::query()
            ->whereIn('id', $runningIds)
            ->update([
                'status' => 'cancelled',
                'updated_at' => now(),
            ]);

        DB::table('content_pipeline_tasks')
            ->whereIn('pipeline_id', $runningIds)
            ->whereIn('status', ['pending', 'running'])
            ->update([
                'status' => 'cancelled',
                'updated_at' => now(),
            ]);
    }

    private function pipelineAcceptsUpdates(int $pipelineId): bool
    {
        return ContentPipeline::query()
            ->where('id', $pipelineId)
            ->whereIn('status', ['pending', 'running'])
            ->exists();
    }

    /**
     * @return array<string, array{label: string, priority: int, critical: bool}>
     */
    public function resolveTaskDefinitions(string $entityType): array
    {
        if ($entityType === 'series') {
            return [
                'image_primary' => ['label' => 'پردازش تصاویر اصلی (پوستر، بک‌دراپ)', 'priority' => 1, 'critical' => true],
                'image_actors' => ['label' => 'پردازش تصاویر بازیگران', 'priority' => 2, 'critical' => false],
                'image_seasons' => ['label' => 'پردازش تصاویر فصل‌ها', 'priority' => 3, 'critical' => false],
                'image_episodes' => ['label' => 'پردازش تصاویر قسمت‌ها', 'priority' => 4, 'critical' => false],
                'credits_sync' => ['label' => 'همگام‌سازی کارنامه بازیگران', 'priority' => 5, 'critical' => false],
                'bio_enrich' => ['label' => 'تکمیل بیوگرافی بازیگران', 'priority' => 6, 'critical' => false],
            ];
        }

        return [
            'image_primary' => ['label' => 'پردازش تصویر اصلی (پوستر، بک‌دراپ)', 'priority' => 1, 'critical' => true],
            'hls_validate' => ['label' => 'اعتبارسنجی HLS', 'priority' => 2, 'critical' => true],
            'image_actors' => ['label' => 'پردازش تصاویر بازیگران', 'priority' => 3, 'critical' => false],
            'credits_sync' => ['label' => 'همگام‌سازی کارنامه بازیگران', 'priority' => 4, 'critical' => false],
            'bio_enrich' => ['label' => 'تکمیل بیوگرافی بازیگران', 'priority' => 5, 'critical' => false],
        ];
    }
}
