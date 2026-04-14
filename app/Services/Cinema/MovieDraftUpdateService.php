<?php

namespace App\Services\Cinema;

use App\Models\Movie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
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
        $currentSlug = $movie->slug;
        $desiredSlug = $this->resolveUniqueSlug(
            (string) ($payload['slug'] ?? $movie->slug ?? $payload['title'] ?? $payload['original_title'] ?? ''),
            $movie->id
        );

        if ($desiredSlug === '') {
            throw new InvalidArgumentException('Movie slug could not be generated.');
        }

        return DB::transaction(function () use ($movie, $payload, $currentSlug, $desiredSlug) {
            $currentMovieDirectory = $this->mediaPathService->toAbsolutePath($movie->media_folder);
            $newMovieRelativeDirectory = $this->mediaPathService->movieRelativeDirectory($desiredSlug);
            $newMovieDirectory = $this->mediaPathService->toAbsolutePath($newMovieRelativeDirectory);

            if ($currentSlug !== $desiredSlug) {
                $this->migrateManagedDirectory($currentMovieDirectory, $newMovieDirectory);
            }

            $this->mediaPathService->ensureMovieDirectories($desiredSlug);

            $newHlsRelativeDirectory = $this->mediaPathService->hlsRelativeDirectory($desiredSlug);
            $newMasterPlaylistRelativePath = $this->mediaPathService->masterPlaylistRelativePath($desiredSlug);
            $newAssetsDirectory = $this->mediaPathService->toAbsolutePath(
                $this->mediaPathService->assetsRelativeDirectory($desiredSlug)
            );

            $resolvedSourcePath = $this->resolveUpdatedPath(
                $payload['source_path'] ?? null,
                $movie->mediaAsset?->source_path,
                $currentMovieDirectory,
                $newMovieDirectory
            );

            $resolvedPosterPath = array_key_exists('poster_path', $payload)
                ? $this->resolveManagedMoviePath(
                    $payload['poster_path'],
                    $movie->poster_path,
                    $currentMovieDirectory,
                    $newMovieDirectory,
                    $desiredSlug,
                    'poster'
                )
                : $this->shiftInternalPath($movie->poster_path, $currentMovieDirectory, $newMovieDirectory);

            $resolvedTrailerPath = array_key_exists('trailer_path', $payload)
                ? $this->resolveManagedMoviePath(
                    $payload['trailer_path'],
                    $movie->trailer_path,
                    $currentMovieDirectory,
                    $newMovieDirectory,
                    $desiredSlug,
                    'trailer'
                )
                : $this->shiftInternalPath($movie->trailer_path, $currentMovieDirectory, $newMovieDirectory);

            $resolvedDubbedSourcePath = array_key_exists('dubbed_source_path', $payload)
                ? $this->resolveManagedMoviePath(
                    $payload['dubbed_source_path'],
                    $movie->dubbed_source_path,
                    $currentMovieDirectory,
                    $newMovieDirectory,
                    $desiredSlug,
                    'dubbed'
                )
                : $this->shiftInternalPath($movie->dubbed_source_path, $currentMovieDirectory, $newMovieDirectory);

            $resolvedSubtitleSourcePath = array_key_exists('subtitle_source_path', $payload)
                ? $this->resolveManagedMoviePath(
                    $payload['subtitle_source_path'],
                    $movie->subtitle_source_path,
                    $currentMovieDirectory,
                    $newMovieDirectory,
                    $desiredSlug,
                    'subtitle'
                )
                : $this->shiftInternalPath($movie->subtitle_source_path, $currentMovieDirectory, $newMovieDirectory);

            $resolvedAudioTracks = array_key_exists('audio_tracks', $payload)
                ? collect($payload['audio_tracks'] ?? [])
                    ->filter(fn (array $track) => filled($track['source_path'] ?? null))
                    ->values()
                    ->map(function (array $track, int $index) use ($currentMovieDirectory, $newMovieDirectory, $desiredSlug) {
                        $candidatePath = $this->shiftInternalPath(
                            (string) $track['source_path'],
                            $currentMovieDirectory,
                            $newMovieDirectory
                        ) ?? (string) $track['source_path'];

                        return [
                            'label' => trim((string) ($track['label'] ?? ('صوت ' . ($index + 2)))),
                            'language' => trim((string) ($track['language'] ?? 'und')) ?: 'und',
                            'source_path' => $this->mediaPathService->storeManagedMovieFile(
                                $candidatePath,
                                $desiredSlug,
                                'audio',
                                'track-' . ($index + 2)
                            ),
                        ];
                    })
                    ->all()
                : collect($movie->audio_tracks ?? [])
                    ->map(function (array $track) use ($currentMovieDirectory, $newMovieDirectory) {
                        return [
                            'label' => $track['label'] ?? 'صوت اضافه',
                            'language' => $track['language'] ?? 'und',
                            'source_path' => $this->shiftInternalPath(
                                $track['source_path'] ?? null,
                                $currentMovieDirectory,
                                $newMovieDirectory
                            ),
                        ];
                    })
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
                    'source_is_external' => !$this->mediaPathService->isPathInside($resolvedSourcePath, $newMovieDirectory),
                    'managed_relative_directory' => $newMovieRelativeDirectory,
                    'hls_output_relative_directory' => $newHlsRelativeDirectory,
                    'master_playlist_relative_path' => $newMasterPlaylistRelativePath,
                    'selected_hls_profiles' => $selectedProfiles,
                ]);

                $oldHlsRelativeDirectory = $this->mediaPathService->hlsRelativeDirectory($currentSlug);
                $movie->mediaAsset->variants()->get()->each(function ($variant) use ($oldHlsRelativeDirectory, $newHlsRelativeDirectory) {
                    $variant->update([
                        'playlist_relative_path' => Str::replaceFirst(
                            $oldHlsRelativeDirectory,
                            $newHlsRelativeDirectory,
                            $variant->playlist_relative_path
                        ),
                    ]);
                });
            }

            $movie->genres()->sync($payload['genre_ids'] ?? $movie->genres()->pluck('genres.id')->all());
            $movie->countries()->sync($payload['country_ids'] ?? $movie->countries()->pluck('countries.id')->all());
            $movie->languages()->sync($payload['language_ids'] ?? $movie->languages()->pluck('languages.id')->all());
            $movie->tags()->sync($payload['tag_ids'] ?? $movie->tags()->pluck('tags.id')->all());
            $movie->actors()->sync($payload['actor_ids'] ?? $movie->actors()->pluck('actors.id')->all());

            File::ensureDirectoryExists($newAssetsDirectory);

            return $movie->fresh([
                'mediaAsset.variants',
                'mediaAsset.processingRuns',
                'creator',
                'genres',
                'countries',
                'languages',
                'tags',
                'actors',
            ]);
        });
    }

    private function migrateManagedDirectory(string $from, string $to): void
    {
        if (strtolower($from) === strtolower($to)) {
            return;
        }

        if (!File::exists($from)) {
            return;
        }

        if (File::exists($to)) {
            File::deleteDirectory($to);
        }

        File::ensureDirectoryExists(dirname($to));

        if (!File::moveDirectory($from, $to, false)) {
            throw new InvalidArgumentException('Managed media folder could not be moved to the new slug.');
        }
    }

    private function resolveUpdatedPath(
        mixed $requestedPath,
        ?string $currentPath,
        string $oldMovieDirectory,
        string $newMovieDirectory
    ): string {
        if (filled($requestedPath)) {
            return $this->mediaPathService->ensureReadableFile(
                $this->shiftInternalPath((string) $requestedPath, $oldMovieDirectory, $newMovieDirectory) ?? (string) $requestedPath
            );
        }

        $shiftedPath = $this->shiftInternalPath($currentPath, $oldMovieDirectory, $newMovieDirectory);

        if (!$shiftedPath) {
            throw new InvalidArgumentException('A source video file is required.');
        }

        return $shiftedPath;
    }

    private function resolveNullableUpdatedPath(
        mixed $requestedPath,
        ?string $currentPath,
        string $oldMovieDirectory,
        string $newMovieDirectory
    ): ?string {
        if ($requestedPath === null || $requestedPath === '') {
            return null;
        }

        if (is_string($requestedPath)) {
            return $this->mediaPathService->ensureReadableFile(
                $this->shiftInternalPath($requestedPath, $oldMovieDirectory, $newMovieDirectory) ?? $requestedPath
            );
        }

        return $this->shiftInternalPath($currentPath, $oldMovieDirectory, $newMovieDirectory);
    }

    private function resolveManagedMoviePath(
        mixed $requestedPath,
        ?string $currentPath,
        string $oldMovieDirectory,
        string $newMovieDirectory,
        string $slug,
        string $kind
    ): ?string {
        if ($requestedPath === null || $requestedPath === '') {
            return null;
        }

        $candidatePath = is_string($requestedPath)
            ? ($this->shiftInternalPath($requestedPath, $oldMovieDirectory, $newMovieDirectory) ?? $requestedPath)
            : $this->shiftInternalPath($currentPath, $oldMovieDirectory, $newMovieDirectory);

        if (!$candidatePath) {
            return null;
        }

        return match ($kind) {
            'poster' => $this->mediaPathService->storeMoviePoster($candidatePath, $slug),
            'trailer' => $this->mediaPathService->storeMovieTrailer($candidatePath, $slug),
            'dubbed' => $this->mediaPathService->storeMovieDubbedSource($candidatePath, $slug),
            'subtitle' => $this->mediaPathService->storeMovieSubtitle($candidatePath, $slug),
            default => $this->mediaPathService->ensureReadableFile($candidatePath),
        };
    }

    private function shiftInternalPath(?string $path, string $oldMovieDirectory, string $newMovieDirectory): ?string
    {
        if (!$path) {
            return null;
        }

        $normalizedPath = $this->mediaPathService->normalizeInputPath($path);

        if (!$this->mediaPathService->isPathInside($normalizedPath, $oldMovieDirectory)) {
            return $normalizedPath;
        }

        $suffix = ltrim(Str::after($normalizedPath, $oldMovieDirectory), DIRECTORY_SEPARATOR);
        $candidate = rtrim($newMovieDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $suffix;

        if (File::exists($candidate)) {
            return $candidate;
        }

        return $normalizedPath;
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

        if (array_key_exists('actor_ids', $payload)) {
            return \App\Models\Actor::query()
                ->whereIn('id', $payload['actor_ids'] ?? [])
                ->orderBy('name')
                ->pluck('name')
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
