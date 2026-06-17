<?php

namespace App\Observers;

use App\Models\Movie;
use App\Services\Cinema\WatchProgressLifecycleService;

class MovieObserver
{
    public function __construct(
        private readonly WatchProgressLifecycleService $watchProgressLifecycle,
    ) {
    }

    public function updated(Movie $movie): void
    {
        $this->watchProgressLifecycle->handleMovieUpdated($movie);
    }

    public function deleted(Movie $movie): void
    {
        $this->watchProgressLifecycle->purgeForMovie($movie->id);
    }
}
