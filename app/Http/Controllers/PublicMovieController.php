<?php

namespace App\Http\Controllers;

use App\Models\Movie;
use App\Services\Cinema\MediaPathService;
use Illuminate\Http\JsonResponse;

class PublicMovieController extends Controller
{
    public function __construct(
        private readonly MediaPathService $mediaPathService,
    ) {
    }

    public function index(): JsonResponse
    {
        $movies = Movie::query()
            ->with('mediaAsset.variants')
            ->where('publish_status', 'published')
            ->latest('published_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'movies' => $movies->map(fn (Movie $movie) => $this->transformMovie($movie))->values(),
            ],
        ]);
    }

    public function show(Movie $movie): JsonResponse
    {
        if ($movie->publish_status !== 'published') {
            return response()->json([
                'success' => false,
                'message' => 'Movie is not published.',
            ], 404);
        }

        $movie->load('mediaAsset.variants');

        return response()->json([
            'success' => true,
            'data' => [
                'movie' => $this->transformMovie($movie),
            ],
        ]);
    }

    private function transformMovie(Movie $movie): array
    {
        $mediaAsset = $movie->mediaAsset;

        return [
            'id' => $movie->id,
            'title' => $movie->title,
            'slug' => $movie->slug,
            'original_title' => $movie->original_title,
            'synopsis' => $movie->synopsis,
            'release_year' => $movie->release_year,
            'duration_seconds' => $movie->duration_seconds,
            'provider_name' => $movie->provider_name,
            'poster_url' => $this->mediaPathService->inferPublicUrlFromAbsolutePath($movie->poster_path),
            'publish_status' => $movie->publish_status,
            'playback' => $mediaAsset && $mediaAsset->processing_status === 'ready' ? [
                'master_playlist_url' => $this->mediaPathService->toPublicUrl($mediaAsset->master_playlist_relative_path),
                'variants' => $mediaAsset->variants
                    ->sortBy('bandwidth')
                    ->values()
                    ->map(function ($variant) {
                        return [
                            'profile_key' => $variant->profile_key,
                            'resolution' => $variant->resolution,
                            'bandwidth' => $variant->bandwidth,
                        ];
                    })
                    ->all(),
            ] : null,
        ];
    }
}
