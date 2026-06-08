<?php

namespace App\Jobs;

use App\Models\Movie;
use App\Services\Cinema\HlsPackageInsightService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class EnrichHlsPlaybackMetadataJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $movieId,
        public readonly ?string $subtitleSourcePath = null,
    ) {
        $this->onQueue('low');
    }

    public function handle(HlsPackageInsightService $hlsPackageInsightService): void
    {
        $movie = Movie::query()->with('mediaAsset')->find($this->movieId);

        if ($movie === null || $movie->processing_status !== 'ready') {
            return;
        }

        $canonicalHlsUrl = data_get($movie->metadata, 'playback.hls_url');

        if (! filled($canonicalHlsUrl)) {
            return;
        }

        $manifestProbe = $hlsPackageInsightService->loadHlsContent((string) $canonicalHlsUrl);
        $hlsInsights = $hlsPackageInsightService->extractPlaybackInsights(
            (string) $canonicalHlsUrl,
            $manifestProbe,
        );

        $resolvedSubtitleSourcePath = filled($this->subtitleSourcePath)
            ? trim($this->subtitleSourcePath)
            : (($hlsInsights['subtitle_source_path'] ?? null) ?: null);
        $resolvedAudioTracks = collect($hlsInsights['audio_tracks'] ?? [])
            ->map(function (array $track) {
                $sourcePath = trim((string) ($track['source_path'] ?? ''));

                if ($sourcePath === '') {
                    return null;
                }

                return [
                    'label' => trim((string) ($track['label'] ?? 'Audio track')) ?: 'Audio track',
                    'language' => trim((string) ($track['language'] ?? 'und')) ?: 'und',
                    'source_path' => $sourcePath,
                ];
            })
            ->filter()
            ->values()
            ->all();
        $resolvedHasDubbedAudio = (bool) ($hlsInsights['has_dubbed_audio'] ?? false);
        $resolvedDubbedSourcePath = $resolvedHasDubbedAudio
            ? (($hlsInsights['dubbed_source_path'] ?? null) ?: null)
            : null;

        $existingMetadata = is_array($movie->metadata) ? $movie->metadata : [];

        $movie->update([
            'has_dubbed_audio' => $resolvedHasDubbedAudio,
            'dubbed_source_path' => $resolvedDubbedSourcePath,
            'audio_tracks' => $resolvedAudioTracks,
            'has_subtitle' => filled($resolvedSubtitleSourcePath) || (bool) ($hlsInsights['has_subtitle'] ?? false),
            'subtitle_source_path' => $resolvedSubtitleSourcePath,
            'metadata' => $existingMetadata,
        ]);

        Log::info('EnrichHlsPlaybackMetadataJob: playback metadata enriched', [
            'movie_id' => $movie->id,
            'hls_url' => $canonicalHlsUrl,
        ]);
    }
}
