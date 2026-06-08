<?php

namespace App\Services\Cinema;

use App\Models\MediaAsset;
use RuntimeException;

class HlsTranscodingService
{
    private const MESSAGE = 'Internal HLS transcoding has been extracted from this project. Use the external worker in D:\\production\\Cinema-hls-worker.';

    public function process(MediaAsset $mediaAsset, ?int $actorId = null): void
    {
        throw new RuntimeException(self::MESSAGE);
    }

    public function stop(MediaAsset $mediaAsset): void
    {
        throw new RuntimeException(self::MESSAGE);
    }

    public function dispose(MediaAsset $mediaAsset): void
    {
    }
}
