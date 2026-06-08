<?php

namespace App\Console\Commands;

use App\Services\Cinema\PublishScheduleService;
use Illuminate\Console\Command;

class PublishScheduledContent extends Command
{
    protected $signature = 'cinema:publish-scheduled';

    protected $description = 'Publish movies and series when their scheduled publish time arrives';

    public function handle(PublishScheduleService $publishScheduleService): int
    {
        $counts = $publishScheduleService->publishDueContent();

        $pendingSeries = \App\Models\Series::query()
            ->where('publish_status', 'scheduled')
            ->whereNotNull('scheduled_for')
            ->count();

        $this->info("Published {$counts['movies']} movie(s) and {$counts['series']} series.");

        if ($counts['series'] === 0 && $pendingSeries > 0) {
            $next = \App\Models\Series::query()
                ->where('publish_status', 'scheduled')
                ->whereNotNull('scheduled_for')
                ->orderBy('scheduled_for')
                ->first(['title', 'scheduled_for']);

            if ($next !== null) {
                $this->line("Next scheduled series: {$next->title} at {$next->scheduled_for}");
            }
        }

        return self::SUCCESS;
    }
}
