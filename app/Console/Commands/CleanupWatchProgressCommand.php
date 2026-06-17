<?php

namespace App\Console\Commands;

use App\Services\Cinema\WatchProgressLifecycleService;
use Illuminate\Console\Command;

class CleanupWatchProgressCommand extends Command
{
    protected $signature = 'cinema:cleanup-watch-progress';

    protected $description = 'Purge invalid, orphaned, and unplayable watch_progress rows.';

    public function handle(WatchProgressLifecycleService $lifecycle): int
    {
        $invalid = $lifecycle->purgeInvalidMediaIds();
        $orphans = $lifecycle->purgeOrphans();
        $unplayable = $lifecycle->purgeUnplayable();

        $this->info("Removed {$invalid} invalid media_id row(s).");
        $this->info("Removed {$orphans} orphaned row(s).");
        $this->info("Removed {$unplayable} unplayable row(s).");

        return self::SUCCESS;
    }
}
