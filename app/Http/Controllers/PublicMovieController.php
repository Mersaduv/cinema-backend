<?php

namespace App\Http\Controllers;

use App\Models\Movie;
use App\Services\Cinema\MediaPathService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class PublicMovieController extends Controller
{
    public function __construct(
        private readonly MediaPathService $mediaPathService,
    ) {
    }

    public function index(): JsonResponse
    {
        $movies = Movie::query()
            ->with(['mediaAsset.variants', 'genres', 'countries', 'languages', 'tags', 'actors'])
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

        $movie->load(['mediaAsset.variants', 'genres', 'countries', 'languages', 'tags', 'actors']);

        if (config('app.debug')) {
            Log::info('Cinema public movie playback payload', [
                'movie_id' => $movie->id,
                'slug' => $movie->slug,
                'master_playlist' => $movie->mediaAsset?->master_playlist_relative_path,
                'variant_count' => $movie->mediaAsset?->variants?->count() ?? 0,
                'variants' => $movie->mediaAsset?->variants?->map(fn ($variant) => [
                    'profile_key' => $variant->profile_key,
                    'resolution' => $variant->resolution,
                    'bandwidth' => $variant->bandwidth,
                ])->values()->all(),
            ]);
        }

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
            'trailer_url' => $this->mediaPathService->inferPublicUrlFromAbsolutePath($movie->trailer_path),
            'has_dubbed_audio' => $movie->has_dubbed_audio,
            'audio_tracks' => collect($movie->audio_tracks ?? [])
                ->map(fn (array $track) => [
                    'label' => $track['label'] ?? 'صوت اضافه',
                    'language' => $track['language'] ?? 'und',
                ])
                ->values()
                ->all(),
            'has_subtitle' => $movie->has_subtitle,
            'age_rating' => $movie->age_rating,
            'cast_members' => $movie->cast_members ?? [],
            'publish_status' => $movie->publish_status,
            'taxonomies' => [
                'genres' => $movie->genres->map(fn ($item) => ['id' => $item->id, 'name' => $item->name, 'slug' => $item->slug])->values()->all(),
                'countries' => $movie->countries->map(fn ($item) => ['id' => $item->id, 'name' => $item->name, 'slug' => $item->slug])->values()->all(),
                'languages' => $movie->languages->map(fn ($item) => ['id' => $item->id, 'name' => $item->name, 'slug' => $item->slug])->values()->all(),
                'tags' => $movie->tags->map(fn ($item) => ['id' => $item->id, 'name' => $item->name, 'slug' => $item->slug])->values()->all(),
                'actors' => $movie->actors->map(fn ($item) => ['id' => $item->id, 'name' => $item->name, 'slug' => $item->slug])->values()->all(),
            ],
            'playback' => $mediaAsset && $mediaAsset->processing_status === 'ready' ? [
                'master_playlist_url' => $this->mediaPathService->toPublicUrl($mediaAsset->master_playlist_relative_path),
                'audio_tracks' => $this->resolvePlaybackAudioTracks($movie),
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

    private function resolvePlaybackAudioTracks(Movie $movie): array
    {
        $tracks = [[
            'key' => 'original',
            'label' => 'صدای اصلی',
            'language' => 'und',
            'is_default' => true,
        ]];

        if ($movie->has_dubbed_audio && filled($movie->dubbed_source_path)) {
            $tracks[] = [
                'key' => 'dubbed',
                'label' => 'دوبله',
                'language' => 'fa',
                'is_default' => false,
            ];
        }

        foreach (collect($movie->audio_tracks ?? [])->values() as $index => $track) {
            if (!filled($track['source_path'] ?? null)) {
                continue;
            }

            $tracks[] = [
                'key' => 'track-' . ($index + 2),
                'label' => trim((string) ($track['label'] ?? ('صوت ' . ($index + 2)))) ?: ('صوت ' . ($index + 2)),
                'language' => trim((string) ($track['language'] ?? 'und')) ?: 'und',
                'is_default' => false,
            ];
        }

        return $tracks;
    }
}
