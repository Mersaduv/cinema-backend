<?php

namespace App\Services\Cinema;

use App\Models\Movie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class MovieDraftUpdateService
{
    public function __construct(
        private readonly MediaPathService $mediaPathService,
    ) {
    }

    public function updateMovie(Movie $movie, array $payload): Movie
    {
        $desiredSlug = $this->resolveUniqueSlug(
            (string) ($payload['slug'] ?? $movie->slug ?? $payload['title'] ?? $payload['original_title'] ?? ''),
            $movie->id
        );

        if ($desiredSlug === '') {
            throw new InvalidArgumentException('Movie slug could not be generated.');
        }

        return DB::transaction(function () use ($movie, $payload, $desiredSlug) {
            $newMovieRelativeDirectory = $this->mediaPathService->movieRelativeDirectory($desiredSlug);
            $newHlsRelativeDirectory = $this->mediaPathService->hlsRelativeDirectory($desiredSlug);
            $newMasterPlaylistRelativePath = $this->mediaPathService->masterPlaylistRelativePath($desiredSlug);

            $resolvedSourcePath = array_key_exists('source_path', $payload)
                ? $this->resolveOptionalRemotePath($payload['source_path'])
                : $movie->mediaAsset?->source_path;

            if ($movie->mediaAsset && ! filled($resolvedSourcePath)) {
                throw new InvalidArgumentException('A source video URL is required.');
            }

            $resolvedPosterPath = array_key_exists('poster_path', $payload)
                ? $this->resolveOptionalRemotePath($payload['poster_path'])
                : $movie->poster_path;

            $resolvedTrailerPath = array_key_exists('trailer_path', $payload)
                ? $this->resolveOptionalRemotePath($payload['trailer_path'])
                : $movie->trailer_path;

            $resolvedDubbedSourcePath = array_key_exists('dubbed_source_path', $payload)
                ? $this->resolveOptionalRemotePath($payload['dubbed_source_path'])
                : $movie->dubbed_source_path;

            $resolvedSubtitleSourcePath = array_key_exists('subtitle_source_path', $payload)
                ? $this->resolveOptionalRemotePath($payload['subtitle_source_path'])
                : $movie->subtitle_source_path;

            $resolvedAudioTracks = array_key_exists('audio_tracks', $payload)
                ? collect($payload['audio_tracks'] ?? [])
                    ->filter(fn (array $track) => filled($track['source_path'] ?? null))
                    ->values()
                    ->map(function (array $track, int $index) {
                        return [
                            'label' => trim((string) ($track['label'] ?? ('صوت ' . ($index + 2)))),
                            'language' => trim((string) ($track['language'] ?? 'und')) ?: 'und',
                            'source_path' => $this->mediaPathService->assertRemoteMediaUrl(
                                (string) $track['source_path'],
                                'audio track source_path',
                            ),
                        ];
                    })
                    ->all()
                : collect($movie->audio_tracks ?? [])
                    ->filter(fn (array $track) => filled($track['source_path'] ?? null))
                    ->values()
                    ->all();

            $movie->update([
                'title' => (string) ($payload['title'] ?? $movie->title),
                'original_title' => $payload['original_title'] ?? $movie->original_title,
                'slug' => $desiredSlug,
                'synopsis' => array_key_exists('synopsis', $payload) ? ($payload['synopsis'] ?: null) : $movie->synopsis,
                'release_year' => $payload['release_year'] ?? $movie->release_year,
                'provider_name' => array_key_exists('provider_name', $payload) ? ($payload['provider_name'] ?: null) : $movie->provider_name,
                'poster_path' => $resolvedPosterPath,
                'trailer_path' => $resolvedTrailerPath,
                'has_dubbed_audio' => array_key_exists('has_dubbed_audio', $payload) ? (bool) $payload['has_dubbed_audio'] : $movie->has_dubbed_audio,
                'dubbed_source_path' => array_key_exists('has_dubbed_audio', $payload)
                    ? ((bool) $payload['has_dubbed_audio'] ? $resolvedDubbedSourcePath : null)
                    : $resolvedDubbedSourcePath,
                'audio_tracks' => $resolvedAudioTracks,
                'has_subtitle' => array_key_exists('has_subtitle', $payload) ? (bool) $payload['has_subtitle'] : $movie->has_subtitle,
                'subtitle_source_path' => array_key_exists('has_subtitle', $payload)
                    ? ((bool) $payload['has_subtitle'] ? $resolvedSubtitleSourcePath : null)
                    : $resolvedSubtitleSourcePath,
                'age_rating' => array_key_exists('age_rating', $payload) ? ($payload['age_rating'] ?: null) : $movie->age_rating,
                'cast_members' => $this->resolveCastMembers($payload, $movie),
                'media_folder' => $newMovieRelativeDirectory,
            ]);

            if ($movie->mediaAsset) {
                $selectedProfiles = $this->resolveSelectedProfiles(
                    $payload['selected_hls_profiles'] ?? $movie->mediaAsset->selected_hls_profiles
                );

                $movie->mediaAsset->update([
                    'source_path' => $resolvedSourcePath,
                    'source_is_external' => true,
                    'managed_relative_directory' => $newMovieRelativeDirectory,
                    'hls_output_relative_directory' => $newHlsRelativeDirectory,
                    'master_playlist_relative_path' => $newMasterPlaylistRelativePath,
                    'selected_hls_profiles' => $selectedProfiles,
                ]);
            }

            return $movie->fresh([
                'mediaAsset.variants',
                'mediaAsset.processingRuns',
                'creator',
            ]);
        });
    }

    private function resolveOptionalRemotePath(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $trimmed = trim((string) $value);

        if (str_starts_with($trimmed, '/images/')) {
            return $trimmed;
        }

        if (preg_match('#^https?://#i', $trimmed) === 1) {
            return $trimmed;
        }

        return $this->mediaPathService->assertRemoteMediaUrl($trimmed);
    }

    private function resolveCastMembers(array $payload, Movie $movie): array
    {
        if (array_key_exists('cast_members', $payload)) {
            return collect($payload['cast_members'] ?? [])
                ->map(fn ($item) => trim((string) $item))
                ->filter()
                ->values()
                ->all();
        }

        if (array_key_exists('actor_names', $payload)) {
            return collect($payload['actor_names'] ?? [])
                ->map(fn ($item) => trim((string) $item))
                ->filter()
                ->values()
                ->all();
        }

        return $movie->cast_members ?? [];
    }

    private function resolveUniqueSlug(string $value, int $ignoreMovieId): string
    {
        $baseSlug = Str::slug($value);

        if ($baseSlug === '') {
            return '';
        }

        $slug = $baseSlug;
        $counter = 2;

        while (
            Movie::query()
                ->whereKeyNot($ignoreMovieId)
                ->where('slug', $slug)
                ->exists()
        ) {
            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }

        return $slug;
    }

    /**
     * @param  array<int, mixed>|null  $requestedProfiles
     * @return array<int, string>
     */
    private function resolveSelectedProfiles(?array $requestedProfiles): array
    {
        $configuredProfiles = array_keys((array) config('cinema.hls_profiles', []));

        if ($requestedProfiles === null || $requestedProfiles === []) {
            return $configuredProfiles;
        }

        $selectedProfiles = collect($requestedProfiles)
            ->map(fn ($item) => (string) $item)
            ->filter(fn (string $item) => in_array($item, $configuredProfiles, true))
            ->values()
            ->all();

        return $selectedProfiles !== [] ? $selectedProfiles : $configuredProfiles;
    }
}
