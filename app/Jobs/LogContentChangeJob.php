<?php

namespace App\Jobs;

use App\Models\ContentChangeLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class LogContentChangeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /**
     * @param  list<string>|null  $changedFields
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function __construct(
        public readonly string $entityType,
        public readonly int $entityId,
        public readonly string $action,
        public readonly ?int $userId = null,
        public readonly ?array $changedFields = null,
        public readonly ?array $oldValues = null,
        public readonly ?array $newValues = null,
        public readonly ?string $ipAddress = null,
    ) {
        $this->onQueue('low');
    }

    public function handle(): void
    {
        ContentChangeLog::query()->create([
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'user_id' => $this->userId,
            'action' => $this->action,
            'changed_fields' => $this->changedFields,
            'old_values' => $this->oldValues,
            'new_values' => $this->newValues,
            'ip_address' => $this->ipAddress,
            'created_at' => now(),
        ]);
    }
}
