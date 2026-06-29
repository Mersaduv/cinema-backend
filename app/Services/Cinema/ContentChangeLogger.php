<?php

namespace App\Services\Cinema;

use App\Jobs\LogContentChangeJob;
use Illuminate\Http\Request;

final class ContentChangeLogger
{
    /** @var list<string> */
    private const SENSITIVE_FIELDS = [
        'hls_url',
        'dash_url',
        'playback',
        'source_path',
        'master_playlist',
    ];

    public function log(
        string $entityType,
        int $entityId,
        string $action,
        ?Request $request = null,
        ?array $changedFields = null,
        ?array $oldValues = null,
        ?array $newValues = null,
    ): void {
        LogContentChangeJob::dispatch(
            $entityType,
            $entityId,
            $action,
            $request?->user()?->id,
            $changedFields ? array_values($changedFields) : null,
            $this->sanitizeValues($oldValues),
            $this->sanitizeValues($newValues),
            $request?->ip(),
        );
    }

    /**
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null
     */
    private function sanitizeValues(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        return collect($values)
            ->reject(function ($value, $key) {
                $normalized = strtolower((string) $key);

                foreach (self::SENSITIVE_FIELDS as $needle) {
                    if (str_contains($normalized, $needle)) {
                        return true;
                    }
                }

                return false;
            })
            ->all();
    }
}
