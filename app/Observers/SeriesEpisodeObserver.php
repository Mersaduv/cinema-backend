<?php

namespace App\Observers;

use App\Models\SeriesEpisode;
use App\Services\Cinema\WatchProgressLifecycleService;

class SeriesEpisodeObserver
{
    public function __construct(
        private readonly WatchProgressLifecycleService $watchProgressLifecycle,
    ) {
    }

    public function updated(SeriesEpisode $episode): void
    {
        $this->watchProgressLifecycle->handleEpisodeUpdated($episode);
    }

    public function deleted(SeriesEpisode $episode): void
    {
        $this->watchProgressLifecycle->purgeForEpisode(
            (int) $episode->series_id,
            (int) $episode->id,
        );
    }
}
