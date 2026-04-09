<?php

namespace App\Services\Cinema;

use App\Models\MediaAsset;
use App\Models\MediaProcessingRun;
use App\Models\Movie;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class HlsTranscodingService
{
    public function __construct(
        private readonly MediaPathService $mediaPathService,
    ) {
    }

    public function process(MediaAsset $mediaAsset, ?int $actorId = null): MediaProcessingRun
    {
        $mediaAsset->loadMissing(['mediable', 'variants', 'processingRuns']);

        $run = $mediaAsset->processingRuns()->create([
            'created_by' => $actorId,
            'status' => 'processing',
            'started_at' => now(),
        ]);

        $this->markAssetState($mediaAsset, 'processing');

        $commands = [];
        $logs = [];

        try {
            $sourcePath = $this->mediaPathService->ensureReadableFile($mediaAsset->source_path);
            $profiles = (array) config('cinema.hls_profiles', []);

            if ($profiles === []) {
                throw new RuntimeException('No HLS profiles are configured.');
            }

            /** @var Movie|null $movie */
            $movie = $mediaAsset->mediable instanceof Movie ? $mediaAsset->mediable : null;

            if (!$movie) {
                throw new RuntimeException('Media asset is not attached to a movie.');
            }

            $this->mediaPathService->ensureMovieDirectories($movie->slug);
            $mediaAsset->variants()->delete();

            $masterPlaylistEntries = [];

            foreach ($profiles as $profileKey => $profile) {
                $variantRelativeDirectory = trim($mediaAsset->hls_output_relative_directory, '/') . '/' . $profileKey;
                $variantAbsoluteDirectory = $this->mediaPathService->toAbsolutePath($variantRelativeDirectory);

                File::ensureDirectoryExists($variantAbsoluteDirectory);

                $playlistAbsolutePath = $variantAbsoluteDirectory . DIRECTORY_SEPARATOR . 'index.m3u8';
                $segmentPattern = $variantAbsoluteDirectory . DIRECTORY_SEPARATOR . 'segment_%03d.ts';

                $command = $this->buildVariantCommand(
                    $sourcePath,
                    $profile,
                    $playlistAbsolutePath,
                    $segmentPattern
                );

                $commands[] = $this->formatCommand($command);

                $process = new Process($command);
                $process->setTimeout(null);
                $process->run();

                $logs[] = trim($process->getOutput() . PHP_EOL . $process->getErrorOutput());

                if (!$process->isSuccessful()) {
                    throw new RuntimeException(
                        $process->getErrorOutput() !== ''
                            ? trim($process->getErrorOutput())
                            : 'FFmpeg exited with a non-zero status.'
                    );
                }

                $relativePlaylistPath = $variantRelativeDirectory . '/index.m3u8';

                $mediaAsset->variants()->create([
                    'profile_key' => $profileKey,
                    'playlist_relative_path' => $relativePlaylistPath,
                    'resolution' => "{$profile['width']}x{$profile['height']}",
                    'bandwidth' => (int) $profile['bandwidth'],
                    'codecs' => $profile['codecs'] ?? null,
                    'is_active' => true,
                ]);

                $masterPlaylistEntries[] = [
                    'bandwidth' => (int) $profile['bandwidth'],
                    'resolution' => "{$profile['width']}x{$profile['height']}",
                    'playlist' => "{$profileKey}/index.m3u8",
                    'codecs' => $profile['codecs'] ?? null,
                ];
            }

            $masterPlaylistAbsolutePath = $this->mediaPathService->toAbsolutePath($mediaAsset->master_playlist_relative_path);
            File::put($masterPlaylistAbsolutePath, $this->buildMasterPlaylist($masterPlaylistEntries));

            $mediaAsset->update([
                'processing_status' => 'ready',
                'processing_error' => null,
                'last_processed_at' => now(),
            ]);

            $movie->update([
                'processing_status' => 'ready',
                'processing_error' => null,
            ]);

            $run->update([
                'status' => 'completed',
                'ffmpeg_command' => implode(PHP_EOL . PHP_EOL, $commands),
                'logs' => trim(implode(PHP_EOL . PHP_EOL, array_filter($logs))),
                'finished_at' => now(),
            ]);

            return $run->refresh();
        } catch (Throwable $exception) {
            $this->markAssetState($mediaAsset, 'failed', $exception->getMessage());

            $run->update([
                'status' => 'failed',
                'ffmpeg_command' => implode(PHP_EOL . PHP_EOL, $commands),
                'logs' => trim(implode(PHP_EOL . PHP_EOL, array_filter($logs))),
                'error_message' => $exception->getMessage(),
                'finished_at' => now(),
            ]);

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array<int, string>
     */
    private function buildVariantCommand(
        string $sourcePath,
        array $profile,
        string $playlistAbsolutePath,
        string $segmentPattern
    ): array {
        $width = (int) $profile['width'];
        $height = (int) $profile['height'];
        $scaleFilter = "scale=w={$width}:h={$height}:force_original_aspect_ratio=decrease,pad={$width}:{$height}:(ow-iw)/2:(oh-ih)/2";

        return [
            (string) config('cinema.ffmpeg_binary', 'ffmpeg'),
            '-y',
            '-i',
            $sourcePath,
            '-vf',
            $scaleFilter,
            '-c:v',
            'libx264',
            '-preset',
            'veryfast',
            '-profile:v',
            'main',
            '-crf',
            '21',
            '-g',
            '48',
            '-keyint_min',
            '48',
            '-sc_threshold',
            '0',
            '-c:a',
            'aac',
            '-ar',
            '48000',
            '-b:a',
            (string) $profile['audio_bitrate'],
            '-b:v',
            (string) $profile['video_bitrate'],
            '-maxrate',
            (string) $profile['maxrate'],
            '-bufsize',
            (string) $profile['bufsize'],
            '-f',
            'hls',
            '-hls_time',
            (string) config('cinema.hls_segment_duration', 6),
            '-hls_playlist_type',
            'vod',
            '-hls_segment_filename',
            $segmentPattern,
            $playlistAbsolutePath,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     */
    private function buildMasterPlaylist(array $entries): string
    {
        $lines = ['#EXTM3U', '#EXT-X-VERSION:3'];

        foreach ($entries as $entry) {
            $streamInfo = "#EXT-X-STREAM-INF:BANDWIDTH={$entry['bandwidth']},RESOLUTION={$entry['resolution']}";

            if (!empty($entry['codecs'])) {
                $streamInfo .= ",CODECS=\"{$entry['codecs']}\"";
            }

            $lines[] = $streamInfo;
            $lines[] = $entry['playlist'];
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * @param  array<int, string>  $command
     */
    private function formatCommand(array $command): string
    {
        return collect($command)
            ->map(fn (string $part) => str_contains($part, ' ') ? "\"{$part}\"" : $part)
            ->implode(' ');
    }

    private function markAssetState(MediaAsset $mediaAsset, string $status, ?string $error = null): void
    {
        $mediaAsset->update([
            'processing_status' => $status,
            'processing_error' => $error,
        ]);

        if ($mediaAsset->mediable instanceof Movie) {
            $mediaAsset->mediable->update([
                'processing_status' => $status,
                'processing_error' => $error,
            ]);
        }
    }
}
