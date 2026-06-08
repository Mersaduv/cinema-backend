<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class QueueHealthCommand extends Command
{
    protected $signature = 'queue:health';

    protected $description = 'Check whether the queue worker is processing jobs';

    public function handle(): int
    {
        if (! Schema::hasTable('jobs')) {
            $this->warn('WARNING: jobs table not found. Run migrations first.');

            return self::FAILURE;
        }

        $connection = config('queue.default');
        $staleThreshold = now()->subSeconds(30);

        $staleJobs = DB::table('jobs')
            ->where('created_at', '<=', $staleThreshold->getTimestamp())
            ->count();

        if ($connection === 'sync') {
            $this->info('Queue connection is sync — jobs run inline (no worker required).');

            return self::SUCCESS;
        }

        if ($staleJobs === 0) {
            $this->info('Queue worker is running (no jobs older than 30s).');

            return self::SUCCESS;
        }

        $this->warn("WARNING: {$staleJobs} job(s) waiting longer than 30 seconds.");
        $this->line('Start a worker: php artisan queue:work database --tries=3 --timeout=60');

        return self::FAILURE;
    }
}
