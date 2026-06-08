<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessMovieMediaAssetJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $mediaAssetId,
        public readonly ?int $actorId = null,
    ) {
        $this->onQueue('media-processing');
    }

    public function handle(): void
    {
    }
}
