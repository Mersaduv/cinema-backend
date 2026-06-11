<?php

namespace App\Jobs\Concerns;

use App\Services\Cinema\PipelineOrchestrator;

trait PipelineAwareJob
{
    protected function resolvePipelineId(): ?int
    {
        return property_exists($this, 'pipelineId') ? $this->pipelineId : null;
    }

    protected function resolveTaskKey(?string $taskKey = null): string
    {
        if ($taskKey !== null && $taskKey !== '') {
            return $taskKey;
        }

        return property_exists($this, 'taskKey') ? (string) $this->taskKey : '';
    }

    protected function pipelineStart(?string $taskKey = null): void
    {
        $key = $this->resolveTaskKey($taskKey);
        $pipelineId = $this->resolvePipelineId();

        if ($pipelineId && $key !== '') {
            app(PipelineOrchestrator::class)->taskStarted($pipelineId, $key);
        }
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    protected function pipelineComplete(array $summary = [], ?string $taskKey = null): void
    {
        $key = $this->resolveTaskKey($taskKey);

        $pipelineId = $this->resolvePipelineId();

        if ($pipelineId && $key !== '') {
            app(PipelineOrchestrator::class)->taskCompleted($pipelineId, $key, $summary);
        }
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    protected function pipelineSkip(array $summary = [], ?string $taskKey = null): void
    {
        $key = $this->resolveTaskKey($taskKey);

        $pipelineId = $this->resolvePipelineId();

        if ($pipelineId && $key !== '') {
            app(PipelineOrchestrator::class)->taskSkipped($pipelineId, $key, $summary);
        }
    }

    protected function pipelineFail(string $errorMessage, ?string $taskKey = null): void
    {
        $key = $this->resolveTaskKey($taskKey);

        $pipelineId = $this->resolvePipelineId();

        if ($pipelineId && $key !== '') {
            app(PipelineOrchestrator::class)->taskFailed($pipelineId, $key, $errorMessage);
        }
    }
}
