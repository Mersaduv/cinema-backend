<?php

namespace App\Jobs;

use App\Models\SeriesSeason;
use App\Services\Cinema\ContentImageIngestionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessSeasonImagesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $seasonId,
    ) {
    }

    public function handle(ContentImageIngestionService $contentImageIngestionService): void
    {
        $season = SeriesSeason::query()->find($this->seasonId);

        if (! $season || ! filled($season->poster_url)) {
            return;
        }

        $processed = $contentImageIngestionService->processSeasonPoster($season, $season->poster_url);

        if ($processed && $processed !== $season->poster_url) {
            $season->update(['poster_url' => $processed]);
        }
    }
}
