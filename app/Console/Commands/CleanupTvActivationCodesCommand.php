<?php

namespace App\Console\Commands;

use App\Services\Cinema\TvActivationService;
use Illuminate\Console\Command;

class CleanupTvActivationCodesCommand extends Command
{
    protected $signature = 'cinema:cleanup-tv-activation-codes';

    protected $description = 'Remove expired, unconfirmed TV activation codes older than one hour.';

    public function handle(TvActivationService $activationService): int
    {
        $removed = $activationService->purgeExpiredUnactivatedCodes();
        $this->info("Removed {$removed} expired TV activation code(s).");

        return self::SUCCESS;
    }
}
