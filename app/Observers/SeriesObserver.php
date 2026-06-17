<?php

namespace App\Observers;

use App\Models\Series;
use App\Services\Cinema\WatchProgressLifecycleService;

class SeriesObserver
{
    public function __construct(
        private readonly WatchProgressLifecycleService $watchProgressLifecycle,
    ) {
    }

    public function updated(Series $series): void
    {
        $this->watchProgressLifecycle->handleSeriesUpdated($series);
    }

    public function deleted(Series $series): void
    {
        $this->watchProgressLifecycle->purgeForSeries($series->id);
    }
}
