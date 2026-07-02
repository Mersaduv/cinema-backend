<?php

namespace App\Services\Cinema;

use App\Models\Movie;
use Illuminate\Support\Facades\DB;

class MovieCleanupService
{
    public function deleteMovie(Movie $movie): void
    {
        $movie->loadMissing('mediaAsset');

        $mediaAsset = $movie->mediaAsset;

        DB::transaction(function () use ($movie, $mediaAsset) {
            if ($mediaAsset) {
                $mediaAsset->delete();
            }

            $movie->delete();
        });
    }
}
