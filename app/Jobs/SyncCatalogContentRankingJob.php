<?php

namespace App\Jobs;

use App\Models\Movie;
use App\Models\Series;
use App\Services\Cinema\CatalogContentRankingSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncCatalogContentRankingJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 300;

    public function __construct(
        public string $contentType,
        public int $contentId,
    ) {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'catalog-ranking-'.$this->contentType.'-'.$this->contentId;
    }

    public function handle(CatalogContentRankingSyncService $rankingSyncService): void
    {
        $row = match ($this->contentType) {
            'movie' => Movie::query()->find($this->contentId),
            'series' => Series::query()->find($this->contentId),
            default => null,
        };

        if ($row === null) {
            return;
        }

        $refreshed = $rankingSyncService->refreshFromTmdb($row);

        Log::info('SyncCatalogContentRankingJob completed', [
            'content_type' => $this->contentType,
            'content_id' => $this->contentId,
            'refreshed' => $refreshed,
            'publish_status' => $row->publish_status,
        ]);
    }
}
