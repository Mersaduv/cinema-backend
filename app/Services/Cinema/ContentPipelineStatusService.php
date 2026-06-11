<?php

namespace App\Services\Cinema;

use App\Models\ContentPipeline;

class ContentPipelineStatusService
{
    /**
     * @return array<string, mixed>
     */
    public function buildResponse(
        string $entityType,
        int $entityId,
        string $entityPipelineStatus,
        string $publishStatus,
    ): array {
        $pipeline = ContentPipeline::query()
            ->with('tasks')
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->whereIn('status', ['pending', 'running', 'completed', 'failed'])
            ->latest('id')
            ->first();

        if (! $pipeline) {
            return [
                'pipeline_status' => $entityPipelineStatus !== 'idle' ? $entityPipelineStatus : 'idle',
                'publish_status' => $publishStatus,
            ];
        }

        $total = (int) $pipeline->total_tasks;
        $completed = (int) $pipeline->completed_tasks;

        return [
            'pipeline_id' => $pipeline->id,
            'pipeline_status' => $pipeline->status,
            'progress' => [
                'total' => $total,
                'completed' => $completed,
                'failed' => (int) $pipeline->failed_tasks,
                'percent' => $total > 0 ? (int) round(($completed / $total) * 100) : 0,
            ],
            'publish_status' => $publishStatus,
            'tasks' => $pipeline->tasks
                ->sortBy('priority')
                ->values()
                ->map(fn ($task) => [
                    'task_key' => $task->task_key,
                    'task_label' => $task->task_label,
                    'status' => $task->status,
                    'is_critical' => (bool) $task->is_critical,
                    'started_at' => $task->started_at?->toISOString(),
                    'completed_at' => $task->completed_at?->toISOString(),
                    'error' => $task->error_message,
                ])
                ->all(),
            'error_summary' => $pipeline->error_summary,
            'started_at' => $pipeline->started_at?->toISOString(),
            'completed_at' => $pipeline->completed_at?->toISOString(),
        ];
    }
}
