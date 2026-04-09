<?php

namespace App\Services\Cinema;

use App\Models\Movie;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class MovieIngestionService
{
    public function __construct(
        private readonly MediaPathService $mediaPathService,
    ) {
    }

    public function createMovie(array $payload, User $actor): Movie
    {
        $slug = $this->resolveUniqueSlug((string) ($payload['slug'] ?? $payload['title']));

        if ($slug === '') {
            throw new InvalidArgumentException('Movie slug could not be generated.');
        }

        $sourcePath = $this->mediaPathService->ensureReadableFile((string) $payload['source_path']);
        $posterPath = filled($payload['poster_path'] ?? null)
            ? $this->mediaPathService->ensureReadableFile((string) $payload['poster_path'])
            : null;

        $directories = $this->mediaPathService->ensureMovieDirectories($slug);
        $mediaFolder = $this->mediaPathService->movieRelativeDirectory($slug);
        $hlsFolder = $this->mediaPathService->hlsRelativeDirectory($slug);
        $masterPlaylist = $this->mediaPathService->masterPlaylistRelativePath($slug);

        return DB::transaction(function () use (
            $payload,
            $actor,
            $slug,
            $sourcePath,
            $posterPath,
            $directories,
            $mediaFolder,
            $hlsFolder,
            $masterPlaylist
        ) {
            $publishStatus = (string) $payload['publish_status'];

            $movie = Movie::create([
                'title' => (string) $payload['title'],
                'slug' => $slug,
                'original_title' => $payload['original_title'] ?? null,
                'synopsis' => $payload['synopsis'] ?? null,
                'release_year' => $payload['release_year'] ?? null,
                'duration_seconds' => $payload['duration_seconds'] ?? null,
                'provider_name' => $payload['provider_name'] ?? null,
                'poster_path' => $posterPath,
                'media_folder' => $mediaFolder,
                'publish_status' => $publishStatus,
                'processing_status' => 'pending',
                'scheduled_for' => $payload['scheduled_for'] ?? null,
                'published_at' => $publishStatus === 'published' ? now() : null,
                'metadata' => [
                    'media_root' => $this->mediaPathService->mediaRoot(),
                    'managed_directories' => [
                        'movie' => $directories['movie'],
                        'hls' => $directories['hls'],
                        'assets' => $directories['assets'],
                    ],
                ],
                'created_by' => $actor->id,
            ]);

            $movie->mediaAsset()->create([
                'kind' => 'video',
                'source_path' => $sourcePath,
                'source_is_external' => !$this->mediaPathService->isPathInside($sourcePath, $directories['movie']),
                'managed_relative_directory' => $mediaFolder,
                'hls_output_relative_directory' => $hlsFolder,
                'master_playlist_relative_path' => $masterPlaylist,
                'processing_status' => 'pending',
            ]);

            return $movie->load(['mediaAsset.variants', 'mediaAsset.processingRuns']);
        });
    }

    private function resolveUniqueSlug(string $value): string
    {
        $baseSlug = Str::slug($value);

        if ($baseSlug === '') {
            return '';
        }

        $slug = $baseSlug;
        $counter = 2;

        while (Movie::query()->where('slug', $slug)->exists()) {
            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }

        return $slug;
    }
}
