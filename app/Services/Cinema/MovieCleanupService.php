<?php

namespace App\Services\Cinema;

use App\Models\Movie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class MovieCleanupService
{
    public function __construct(
        private readonly MediaPathService $mediaPathService,
        private readonly HlsTranscodingService $hlsTranscodingService,
    ) {
    }

    public function deleteMovie(Movie $movie): void
    {
        $movie->loadMissing('mediaAsset');

        $mediaAsset = $movie->mediaAsset;
        $managedRelativeDirectory = $movie->media_folder ?: $mediaAsset?->managed_relative_directory;
        $managedAbsoluteDirectory = $managedRelativeDirectory
            ? $this->mediaPathService->toAbsolutePath($managedRelativeDirectory)
            : null;

        if ($mediaAsset) {
            $this->hlsTranscodingService->dispose($mediaAsset);
        }

        DB::transaction(function () use ($movie, $mediaAsset) {
            if ($mediaAsset) {
                $mediaAsset->delete();
            }

            $movie->genres()->detach();
            $movie->countries()->detach();
            $movie->languages()->detach();
            $movie->tags()->detach();
            $movie->actors()->detach();

            $movie->delete();
        });

        if (
            $managedAbsoluteDirectory
            && $this->mediaPathService->isPathInside($managedAbsoluteDirectory, $this->mediaPathService->mediaRoot())
            && is_dir($managedAbsoluteDirectory)
        ) {
            File::deleteDirectory($managedAbsoluteDirectory);
        }
    }
}
