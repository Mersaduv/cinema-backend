<?php

namespace App\Jobs;

use App\Models\Movie;
use App\Services\Cinema\HlsPackageInsightService;
use App\Services\Cinema\MediaPathService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ValidateHlsManifestJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $movieId,
        public readonly string $hlsUrlRaw,
        public readonly ?string $subtitleSourcePath = null,
    ) {
        $this->onQueue('default');
    }

    public function handle(
        HlsPackageInsightService $hlsPackageInsightService,
        MediaPathService $mediaPathService,
    ): void {
        $movie = Movie::query()->with('mediaAsset')->find($this->movieId);

        if ($movie === null) {
            return;
        }

        $canonicalHlsUrl = $hlsPackageInsightService->normalizeHlsPackageMasterUrl($this->hlsUrlRaw);
        $probe = $hlsPackageInsightService->probeHlsMasterUrl($this->hlsUrlRaw);

        if (! filled($canonicalHlsUrl) || ! ($probe['ok'] ?? false)) {
            $error = (string) ($probe['error'] ?? 'The HLS master playlist could not be reached.');

            $movie->update([
                'processing_status' => 'failed',
                'processing_error' => $error,
            ]);

            if ($movie->mediaAsset) {
                $movie->mediaAsset->update([
                    'processing_status' => 'failed',
                    'processing_error' => $error,
                ]);
            }

            return;
        }

        $existingMetadata = is_array($movie->metadata) ? $movie->metadata : [];
        $metadataWithHls = array_replace_recursive($existingMetadata, [
            'playback' => array_replace(
                is_array($existingMetadata['playback'] ?? null) ? $existingMetadata['playback'] : [],
                [
                    'hls_url' => $canonicalHlsUrl,
                ],
            ),
        ]);

        $movie->update([
            'processing_status' => 'ready',
            'processing_error' => null,
            'metadata' => $metadataWithHls,
        ]);

        $resolvedSourcePath = $movie->mediaAsset?->source_path;
        $masterPath = (string) $canonicalHlsUrl;

        if ($movie->mediaAsset) {
            $movie->mediaAsset->update([
                'source_path' => $resolvedSourcePath ?: $movie->mediaAsset->source_path,
                'source_is_external' => $this->isPublicationSourceExternal($resolvedSourcePath ?: $movie->mediaAsset->source_path),
                'master_playlist_relative_path' => $masterPath,
                'processing_status' => 'ready',
                'processing_progress_percent' => 100,
                'processing_eta_seconds' => null,
                'current_profile_key' => null,
                'processing_error' => null,
                'last_processed_at' => now(),
                'selected_hls_profiles' => [],
            ]);
        } elseif ($resolvedSourcePath) {
            $movie->mediaAsset()->create([
                'kind' => 'video',
                'source_path' => $resolvedSourcePath,
                'source_is_external' => $this->isPublicationSourceExternal($resolvedSourcePath),
                'managed_relative_directory' => $movie->media_folder,
                'hls_output_relative_directory' => $mediaPathService->hlsRelativeDirectory($movie->slug),
                'master_playlist_relative_path' => $masterPath,
                'selected_hls_profiles' => [],
                'processing_status' => 'ready',
                'processing_progress_percent' => 100,
                'processing_eta_seconds' => null,
                'current_profile_key' => null,
                'processing_error' => null,
                'last_processed_at' => now(),
            ]);
        }

        EnrichHlsPlaybackMetadataJob::dispatch($movie->id, $this->subtitleSourcePath)->onQueue('low');

        Log::info('ValidateHlsManifestJob: HLS URL verified via quick probe', [
            'movie_id' => $movie->id,
            'hls_url' => $canonicalHlsUrl,
            'http_status' => $probe['status'] ?? null,
        ]);
    }

    private function isPublicationSourceExternal(?string $path): bool
    {
        if (! filled($path)) {
            return false;
        }

        return preg_match('#^https?://#i', (string) $path) === 1;
    }
}
