<?php

namespace App\Services\Cinema;

use App\Models\Movie;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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

        $mediaFolder = $this->mediaPathService->movieRelativeDirectory($slug);
        $hlsFolder = $this->mediaPathService->hlsRelativeDirectory($slug);
        $masterPlaylist = $this->mediaPathService->masterPlaylistRelativePath($slug);
        $sourcePath = $this->resolveSourcePath($payload);
        $selectedProfiles = $this->resolveSelectedProfiles($payload['selected_hls_profiles'] ?? null);

        $movie = DB::transaction(function () use ($payload, $actor, $slug, $sourcePath, $selectedProfiles, $mediaFolder, $hlsFolder, $masterPlaylist) {
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
                'poster_path' => null,
                'has_dubbed_audio' => (bool) ($payload['has_dubbed_audio'] ?? false),
                'dubbed_source_path' => $this->resolveOptionalRemotePath($payload['dubbed_source_path'] ?? null),
                'audio_tracks' => $this->resolveAudioTracks($payload),
                'has_subtitle' => (bool) ($payload['has_subtitle'] ?? false),
                'subtitle_source_path' => $this->resolveOptionalRemotePath($payload['subtitle_source_path'] ?? null),
                'media_folder' => $mediaFolder,
                'publish_status' => $publishState['publish_status'] !== '' ? $publishState['publish_status'] : 'draft',
                'processing_status' => 'pending',
                'scheduled_for' => $publishState['scheduled_for'],
                'published_at' => $publishState['published_at'],
                'metadata' => [],
                'created_by' => $actor->id,
            ]);

            if ($sourcePath !== null) {
                $movie->mediaAsset()->create([
                    'kind' => 'video',
                    'source_path' => $sourcePath,
                    'source_is_external' => true,
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

    private function resolveSourcePath(array $payload): ?string
    {
        if (($payload['source_file'] ?? null) instanceof UploadedFile) {
            throw new InvalidArgumentException(
                'Local source file upload is not supported. Provide an HTTP(S) source URL instead.',
            );
        }

        if (blank($payload['source_path'] ?? null)) {
            return null;
        }

        return $this->mediaPathService->assertRemoteMediaUrl((string) $payload['source_path'], 'source_path');
    }

    private function resolveOptionalRemotePath(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        return $this->mediaPathService->assertRemoteMediaUrl((string) $value, 'source_path');
    }

    /**
     * @return array<int, array{label: string, language: string, source_path: string}>
     */
    private function resolveAudioTracks(array $payload): array
    {
        return collect($payload['audio_tracks'] ?? [])
            ->filter(fn (array $track) => filled($track['source_path'] ?? null))
            ->values()
            ->map(function (array $track, int $index) {
                return [
                    'label' => trim((string) ($track['label'] ?? ('صوت ' . ($index + 2)))) ?: ('صوت ' . ($index + 2)),
                    'language' => trim((string) ($track['language'] ?? 'und')) ?: 'und',
                    'source_path' => $this->mediaPathService->assertRemoteMediaUrl(
                        (string) $track['source_path'],
                        'audio track source_path',
                    ),
                ];
            })
            ->all();
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
