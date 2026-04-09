<?php

namespace App\Jobs;

use App\Models\MediaAsset;
use App\Services\Cinema\HlsTranscodingService;
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

    public function handle(HlsTranscodingService $transcodingService): void
    {
        $mediaAsset = MediaAsset::query()->with('mediable')->find($this->mediaAssetId);

        if (!$mediaAsset) {
            return;
        }

        $transcodingService->process($mediaAsset, $this->actorId);
    }
}
