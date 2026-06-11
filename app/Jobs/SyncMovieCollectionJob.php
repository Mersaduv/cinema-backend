<?php

namespace App\Jobs;

use App\Models\Movie;
use App\Services\Cinema\CatalogCacheService;
use App\Services\Cinema\CollectionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncMovieCollectionJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public int $movieId,
        public array $metadata,
        public ?int $tmdbId = null,
    ) {
    }

    public function handle(CollectionService $collectionService, CatalogCacheService $catalogCacheService): void
    {
        $movie = Movie::query()->find($this->movieId);
        if (! $movie) {
            return;
        }

        $collectionService->syncFromMoviePublication($movie, $this->metadata, $this->tmdbId);
        $catalogCacheService->forgetPublicCollectionsList();
    }
}
