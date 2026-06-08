<?php

namespace App\Services\Cinema;

use App\Models\Movie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class MovieCleanupService
{
    public function __construct(
        private readonly MediaPathService $mediaPathService,
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

        DB::transaction(function () use ($movie, $mediaAsset) {
            if ($mediaAsset) {
                $mediaAsset->delete();
            }

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
