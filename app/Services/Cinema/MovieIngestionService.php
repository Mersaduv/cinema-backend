<?php

namespace App\Services\Cinema;

use App\Models\Movie;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;

class MovieIngestionService
{
    public function __construct(
        private readonly MediaPathService $mediaPathService,
        private readonly PublishScheduleService $publishScheduleService,
    ) {
    }

    public function createMovie(array $payload, User $actor): Movie
    {
        $slug = $this->resolveUniqueSlug((string) ($payload['slug'] ?? $payload['title'] ?? $payload['original_title'] ?? ''));

        if ($slug === '') {
            throw new InvalidArgumentException('Movie slug could not be generated.');
        }

        $directories = $this->mediaPathService->ensureMovieDirectories($slug);
        $mediaFolder = $this->mediaPathService->movieRelativeDirectory($slug);
        $hlsFolder = $this->mediaPathService->hlsRelativeDirectory($slug);
        $masterPlaylist = $this->mediaPathService->masterPlaylistRelativePath($slug);
        $selectedProfiles = $this->resolveSelectedProfiles($payload['selected_hls_profiles'] ?? null);

        $sourcePath = $this->resolveSourcePath($payload, $directories['movie']);
        $posterPath = $this->resolvePosterPath($payload, $slug);
        $dubbedSourcePath = $this->resolveDubbedSourcePath($payload, $slug);
        $subtitleSourcePath = $this->resolveSubtitleSourcePath($payload, $slug);
        $audioTracks = $this->resolveAudioTracks($payload, $slug);

        $movie = DB::transaction(function () use (
            $payload,
            $actor,
            $slug,
            $sourcePath,
            $posterPath,
            $dubbedSourcePath,
            $subtitleSourcePath,
            $audioTracks,
            $selectedProfiles,
            $directories,
            $mediaFolder,
            $hlsFolder,
            $masterPlaylist
        ) {
            $publishStatus = (string) ($payload['publish_status'] ?? 'draft');
            $publishState = $this->publishScheduleService->resolvePublishState(
                $publishStatus,
                $payload['scheduled_for'] ?? null,
            );

            $movie = Movie::create([
                'title' => (string) ($payload['title'] ?? $payload['original_title']),
                'slug' => $slug,
                'original_title' => $payload['original_title'] ?? $payload['title'] ?? null,
                'synopsis' => $payload['synopsis'] ?? null,
                'release_year' => $payload['release_year'] ?? null,
                'duration_seconds' => $payload['duration_seconds'] ?? null,
                'provider_name' => $payload['provider_name'] ?? null,
                'poster_path' => $posterPath,
                'has_dubbed_audio' => (bool) ($payload['has_dubbed_audio'] ?? false),
                'dubbed_source_path' => (bool) ($payload['has_dubbed_audio'] ?? false) ? $dubbedSourcePath : null,
                'audio_tracks' => $audioTracks,
                'has_subtitle' => (bool) ($payload['has_subtitle'] ?? false),
                'subtitle_source_path' => (bool) ($payload['has_subtitle'] ?? false) ? $subtitleSourcePath : null,
                'media_folder' => $mediaFolder,
                'publish_status' => $publishState['publish_status'] !== '' ? $publishState['publish_status'] : 'draft',
                'processing_status' => 'pending',
                'scheduled_for' => $publishState['scheduled_for'],
                'published_at' => $publishState['published_at'],
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

            if ($sourcePath !== null) {
                $movie->mediaAsset()->create([
                    'kind' => 'video',
                    'source_path' => $sourcePath,
                    'source_is_external' => $this->isExternalSource($sourcePath, $directories['movie']),
                    'managed_relative_directory' => $mediaFolder,
                    'hls_output_relative_directory' => $hlsFolder,
                    'master_playlist_relative_path' => $masterPlaylist,
                    'selected_hls_profiles' => $selectedProfiles,
                    'processing_status' => 'pending',
                ]);
            }

            return $movie->load(['mediaAsset.variants', 'mediaAsset.processingRuns']);
        });

        $this->publishScheduleService->syncScheduledPublishRunner($movie);

        return $movie;
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

    private function resolveSourcePath(array $payload, string $movieDirectory): ?string
    {
        if (($payload['source_file'] ?? null) instanceof UploadedFile) {
            return $this->importUploadedFile(
                $payload['source_file'],
                $movieDirectory,
                'source'
            );
        }

        if (blank($payload['source_path'] ?? null)) {
            return null;
        }

        $sourcePath = trim((string) $payload['source_path']);

        if (preg_match('#^https?://#i', $sourcePath) === 1) {
            return $sourcePath;
        }

        return $this->mediaPathService->ensureReadableFile($sourcePath);
    }

    private function resolvePosterPath(array $payload, string $slug): ?string
    {
        if (($payload['poster_file'] ?? null) instanceof UploadedFile) {
            return $this->importUploadedFile(
                $payload['poster_file'],
                $this->mediaPathService->toAbsolutePath($this->mediaPathService->assetsRelativeDirectory($slug)),
                'poster'
            );
        }

        if (filled($payload['poster_path'] ?? null)) {
            return $this->mediaPathService->storeMoviePoster((string) $payload['poster_path'], $slug);
        }

        return null;
    }

    private function resolveDubbedSourcePath(array $payload, string $slug): ?string
    {
        if (!(bool) ($payload['has_dubbed_audio'] ?? false) || blank($payload['dubbed_source_path'] ?? null)) {
            return null;
        }

        return $this->mediaPathService->storeMovieDubbedSource((string) $payload['dubbed_source_path'], $slug);
    }

    private function resolveSubtitleSourcePath(array $payload, string $slug): ?string
    {
        if (!(bool) ($payload['has_subtitle'] ?? false) || blank($payload['subtitle_source_path'] ?? null)) {
            return null;
        }

        return $this->mediaPathService->storeMovieSubtitle((string) $payload['subtitle_source_path'], $slug);
    }

    /**
     * @return array<int, array{label: string, language: string, source_path: string}>
     */
    private function resolveAudioTracks(array $payload, string $slug): array
    {
        return collect($payload['audio_tracks'] ?? [])
            ->filter(fn (array $track) => filled($track['source_path'] ?? null))
            ->values()
            ->map(function (array $track, int $index) use ($slug) {
                return [
                    'label' => trim((string) ($track['label'] ?? ('صوت ' . ($index + 2)))) ?: ('صوت ' . ($index + 2)),
                    'language' => trim((string) ($track['language'] ?? 'und')) ?: 'und',
                    'source_path' => $this->mediaPathService->storeManagedMovieFile(
                        (string) $track['source_path'],
                        $slug,
                        'audio',
                        'track-' . ($index + 2)
                    ),
                ];
            })
            ->all();
    }

    private function importUploadedFile(UploadedFile $file, string $directory, string $baseName): string
    {
        if (!$file->isValid()) {
            throw new InvalidArgumentException("Uploaded {$baseName} file is invalid.");
        }

        File::ensureDirectoryExists($directory);

        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $destination = $directory . DIRECTORY_SEPARATOR . $baseName . '.' . $extension;
        $copied = File::copy($file->getRealPath(), $destination);

        if (!$copied || !is_file($destination)) {
            throw new InvalidArgumentException("Uploaded {$baseName} file could not be copied into the media library.");
        }

        return $this->mediaPathService->ensureReadableFile($destination);
    }

    private function isExternalSource(string $sourcePath, string $movieDirectory): bool
    {
        if (preg_match('#^https?://#i', $sourcePath) === 1) {
            return true;
        }

        return !$this->mediaPathService->isPathInside($sourcePath, $movieDirectory);
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
