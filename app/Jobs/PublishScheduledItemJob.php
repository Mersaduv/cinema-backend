<?php

namespace App\Jobs;

use App\Services\Cinema\PublishScheduleService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PublishScheduledItemJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 3600;

    public function __construct(
        public string $contentType,
        public int $contentId,
    ) {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'publish-scheduled-'.$this->contentType.'-'.$this->contentId;
    }

    public function handle(PublishScheduleService $publishScheduleService): void
    {
        $publishScheduleService->publishScheduledItem($this->contentType, $this->contentId);
    }
}
