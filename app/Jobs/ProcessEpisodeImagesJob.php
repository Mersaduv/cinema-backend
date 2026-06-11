<?php

namespace App\Jobs;

use App\Models\SeriesEpisode;
use App\Services\Cinema\ContentImageIngestionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessEpisodeImagesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $episodeId,
    ) {
    }

    public function handle(ContentImageIngestionService $contentImageIngestionService): void
    {
        $episode = SeriesEpisode::query()->find($this->episodeId);

        if (! $episode || ! filled($episode->still_url)) {
            return;
        }

        $processed = $contentImageIngestionService->processEpisodeStill($episode, $episode->still_url);

        if ($processed && $processed !== $episode->still_url) {
            $episode->update(['still_url' => $processed]);
        }
    }
}
