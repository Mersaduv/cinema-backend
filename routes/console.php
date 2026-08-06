<?php

use App\Jobs\AggregateInternalPopularityJob;
use App\Jobs\RefreshTrendingJob;
use App\Jobs\RefreshTmdbPopularityJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('cinema:publish-scheduled')->everyMinute();
Schedule::command('cinema:recover-stuck-pipelines')->everyFiveMinutes();
Schedule::command('cinema:cleanup-watch-progress')->daily();
Schedule::command('cinema:cleanup-tv-activation-codes')->hourly();
Schedule::command('cinema:sync-tmdb-reference-data')->weeklyOn(0, '00:00');
Schedule::job(new RefreshTrendingJob('day'))->daily();
Schedule::job(new RefreshTmdbPopularityJob())->daily();
Schedule::job(new AggregateInternalPopularityJob())->daily();
