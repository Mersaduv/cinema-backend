<?php

namespace App\Jobs;

use App\Jobs\Concerns\PipelineAwareJob;
use App\Models\Movie;
use App\Services\Cinema\HlsPackageInsightService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ValidateHlsManifestJob implements ShouldQueue
{
    use PipelineAwareJob;
    use Queueable;

    public int $tries = 3;

    protected string $taskKey = 'hls_validate';

    public function __construct(
        public readonly int $movieId,
        public readonly ?string $hlsUrlRaw = null,
        public readonly ?string $subtitleSourcePath = null,
        public readonly ?int $pipelineId = null,
    ) {
    }

    public function handle(HlsPackageInsightService $hlsPackageInsightService): void
    {
        $this->pipelineStart();

        try {
            $movie = Movie::query()->findOrFail($this->movieId);
            $raw = filled($this->hlsUrlRaw)
                ? trim((string) $this->hlsUrlRaw)
                : (string) data_get($movie->metadata, 'playback.hls_url', '');
            $canonical = $hlsPackageInsightService->normalizeHlsPackageMasterUrl($raw);

            if (! filled($canonical)) {
                $this->markFailed($movie, 'Invalid HLS URL.');

                return;
            }

            $manifest = $hlsPackageInsightService->loadHlsContent($canonical);

            if (! filled($manifest) || stripos((string) $manifest, '#EXTM3U') === false) {
                $this->markFailed($movie, 'Could not read master.m3u8 from this URL or path.');

                return;
            }

            $movie->update([
                'processing_status' => 'ready',
                'processing_error' => null,
            ]);

            if ($movie->mediaAsset) {
                $movie->mediaAsset->update([
                    'processing_status' => 'ready',
                    'processing_progress_percent' => 100,
                    'processing_error' => null,
                    'last_processed_at' => now(),
                ]);
            }

            $insights = $hlsPackageInsightService->extractPlaybackInsights(
                $canonical,
                (string) $manifest,
                verifyRemoteDownloadTargets: true,
            );

            $manifestWarnings = $hlsPackageInsightService->validateVariantAttributes((string) $manifest);
            if ($manifestWarnings !== []) {
                $metadata = $movie->metadata ?? [];
                data_set($metadata, 'hls_warnings', $manifestWarnings);
                data_set($metadata, 'hls_validated_at', now()->toIso8601String());
                $movie->update(['metadata' => $metadata]);

                if ($movie->mediaAsset) {
                    $assetMetadata = $movie->mediaAsset->metadata ?? [];
                    data_set($assetMetadata, 'hls_warnings', $manifestWarnings);
                    data_set($assetMetadata, 'hls_validated_at', now()->toIso8601String());
                    $movie->mediaAsset->update(['metadata' => $assetMetadata]);
                }
            }

            $candidateDash = $insights['dash_url'] ?? null;
            if (filled($candidateDash) && ! $hlsPackageInsightService->verifyDashManifestUrl((string) $candidateDash)) {
                $insights['dash_url'] = null;
            }

            $this->applyPlaybackInsights($movie, $insights);

            $this->pipelineComplete(['manifest_url' => $canonical]);
        } catch (\Throwable $exception) {
            $this->pipelineFail($exception->getMessage());
            throw $exception;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $movie = Movie::query()->find($this->movieId);

        if ($movie) {
            $movie->update([
                'processing_status' => 'failed',
                'processing_error' => $exception->getMessage(),
            ]);
        }

        $this->pipelineFail('HLS validation permanently failed: ' . $exception->getMessage());
    }

    /**
     * @param  array<string, mixed>  $insights
     */
    private function applyPlaybackInsights(Movie $movie, array $insights): void
    {
        $updates = [];

        $subtitlePath = filled($this->subtitleSourcePath)
            ? trim((string) $this->subtitleSourcePath)
            : ($insights['subtitle_source_path'] ?? null);

        if (filled($subtitlePath)) {
            $updates['subtitle_source_path'] = $subtitlePath;
            $updates['has_subtitle'] = true;
        } elseif ($insights['has_subtitle'] ?? false) {
            $updates['has_subtitle'] = true;
            $updates['subtitle_source_path'] = $insights['subtitle_source_path'] ?? null;
        }

        if ($insights['has_dubbed_audio'] ?? false) {
            $updates['has_dubbed_audio'] = true;
            $updates['dubbed_source_path'] = $insights['dubbed_source_path'] ?? null;
        }

        if (! empty($insights['audio_tracks'])) {
            $updates['audio_tracks'] = $insights['audio_tracks'];
        }

        $metadata = $movie->metadata ?? [];
        if (filled($insights['dash_url'] ?? null)) {
            data_set($metadata, 'playback.dash_url', $insights['dash_url']);
            data_set($metadata, 'playback.dash_verified', true);
        } else {
            data_set($metadata, 'playback.dash_url', null);
            data_set($metadata, 'playback.dash_verified', false);
        }

        if ($metadata !== ($movie->metadata ?? [])) {
            $updates['metadata'] = $metadata;
        }

        if ($updates !== []) {
            $movie->update($updates);
        }
    }

    private function markFailed(Movie $movie, string $message): void
    {
        $movie->update([
            'processing_status' => 'failed',
            'processing_error' => $message,
        ]);

        $this->pipelineFail('HLS manifest validation failed: ' . $message);
    }
}
