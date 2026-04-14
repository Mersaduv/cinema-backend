<?php

namespace App\Services\Cinema;

use App\Models\MediaAsset;
use App\Models\MediaProcessingRun;
use App\Models\Movie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class HlsTranscodingService
{
    private const MAX_PROGRESS_BUFFER_BYTES = 32768;
    private const MAX_LOG_BUFFER_BYTES = 131072;

    public function __construct(
        private readonly MediaPathService $mediaPathService,
    ) {
    }

    public function process(MediaAsset $mediaAsset, ?int $actorId = null): MediaProcessingRun
    {
        $mediaAsset->loadMissing(['mediable', 'variants', 'processingRuns']);
        $sourceDurationSeconds = $this->probeSourceDurationSeconds($mediaAsset->source_path);

        $run = $mediaAsset->processingRuns()->create([
            'created_by' => $actorId,
            'status' => 'processing',
            'progress_percent' => 0,
            'eta_seconds' => null,
            'current_profile_key' => null,
            'source_duration_seconds' => $sourceDurationSeconds,
            'started_at' => now(),
        ]);

        $this->markAssetState($mediaAsset, 'processing', null, [
            'processing_progress_percent' => 0,
            'processing_eta_seconds' => null,
            'current_profile_key' => null,
            'processing_started_at' => now(),
            'stop_requested' => false,
            'processing_pid' => null,
        ]);

        $commands = [];
        $logs = [];

        try {
            $sourcePath = $this->mediaPathService->ensureReadableFile($mediaAsset->source_path);
            $configuredProfiles = (array) config('cinema.hls_profiles', []);
            $selectedProfileKeys = collect($mediaAsset->selected_hls_profiles ?? [])
                ->map(fn ($item) => (string) $item)
                ->filter(fn (string $item) => array_key_exists($item, $configuredProfiles))
                ->values()
                ->all();
            $profiles = $selectedProfileKeys !== []
                ? collect($configuredProfiles)->only($selectedProfileKeys)->all()
                : $configuredProfiles;

            if ($profiles === []) {
                throw new RuntimeException('No HLS profiles are configured.');
            }

            /** @var Movie|null $movie */
            $movie = $mediaAsset->mediable instanceof Movie ? $mediaAsset->mediable : null;

            if (!$movie) {
                throw new RuntimeException('Media asset is not attached to a movie.');
            }

            $this->mediaPathService->ensureMovieDirectories($movie->slug);
            $this->cleanupManagedOutputs($mediaAsset);

            $audioEntries = [];
            $subtitleEntries = [];
            $masterPlaylistEntries = [];
            $profileKeys = array_keys($profiles);
            $profileCount = max(count($profileKeys), 1);
            $processStartedAt = microtime(true);

            foreach ($profileKeys as $profileIndex => $profileKey) {
                $mediaAsset->refresh();

                if ($mediaAsset->stop_requested) {
                    throw new RuntimeException('Processing was stopped by admin.');
                }

                $profile = $profiles[$profileKey];
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
                $stderrBuffer = '';
                $stdoutBuffer = '';
                $lastProgressUpdateAt = 0.0;

                $process->start(function (string $type, string $buffer) use (
                    &$stderrBuffer,
                    &$stdoutBuffer,
                    &$lastProgressUpdateAt,
                    $run,
                    $mediaAsset,
                    $profileCount,
                    $profileIndex,
                    $profileKey,
                    $sourceDurationSeconds,
                    $processStartedAt
                ) {
                    if ($type === Process::ERR) {
                        $stderrBuffer = $this->appendLimitedBuffer(
                            $stderrBuffer,
                            $buffer,
                            self::MAX_LOG_BUFFER_BYTES
                        );

                        return;
                    }

                    $stdoutBuffer = $this->appendLimitedBuffer(
                        $stdoutBuffer,
                        $buffer,
                        self::MAX_PROGRESS_BUFFER_BYTES
                    );
                    $progressSnapshot = $this->extractLatestProgressSnapshot($stdoutBuffer);

                    if (!$progressSnapshot) {
                        return;
                    }

                    $now = microtime(true);

                    if (($now - $lastProgressUpdateAt) < 1.5 && ($progressSnapshot['progress'] ?? null) !== 'end') {
                        return;
                    }

                    $lastProgressUpdateAt = $now;

                    $outTimeSeconds = $this->progressOutTimeSeconds($progressSnapshot);
                    $profileRatio = $sourceDurationSeconds > 0
                        ? min(max($outTimeSeconds / $sourceDurationSeconds, 0), 1)
                        : 0;
                    $overallRatio = min((($profileIndex + $profileRatio) / $profileCount), 1);
                    $progressPercent = round($overallRatio * 100, 2);
                    $elapsedSeconds = max(1, (int) round($now - $processStartedAt));
                    $etaSeconds = $overallRatio > 0 && $overallRatio < 1
                        ? max(0, (int) round(($elapsedSeconds / $overallRatio) - $elapsedSeconds))
                        : 0;

                    $run->forceFill([
                        'progress_percent' => $progressPercent,
                        'eta_seconds' => $etaSeconds > 0 ? $etaSeconds : null,
                        'current_profile_key' => $profileKey,
                    ])->save();

                    $this->markAssetState($mediaAsset, 'processing', null, [
                        'processing_progress_percent' => $progressPercent,
                        'processing_eta_seconds' => $etaSeconds > 0 ? $etaSeconds : null,
                        'current_profile_key' => $profileKey,
                    ]);
                });

                $mediaAsset->forceFill([
                    'processing_pid' => $process->getPid(),
                ])->save();

                while ($process->isRunning()) {
                    usleep(500000);
                    $mediaAsset->refresh();

                    if ($mediaAsset->stop_requested) {
                        $this->terminateProcess($process, $mediaAsset->processing_pid);
                        throw new RuntimeException('Processing was stopped by admin.');
                    }
                }

                if (!$process->isSuccessful()) {
                    throw new RuntimeException(
                        $stderrBuffer !== ''
                            ? trim($stderrBuffer)
                            : 'FFmpeg exited with a non-zero status.'
                    );
                }

                $logs[] = trim($stderrBuffer !== '' ? $stderrBuffer : $stdoutBuffer);

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

            $audioEntries[] = $this->extractAudioTrack(
                $sourcePath,
                trim($mediaAsset->hls_output_relative_directory, '/'),
                'original',
                'صدای اصلی',
                true
            );

            foreach ($this->resolveExternalAudioTracks($movie) as $audioTrack) {
                try {
                    $externalAudioPath = $this->mediaPathService->ensureReadableFile($audioTrack['source_path']);
                    $audioEntries[] = $this->extractAudioTrack(
                        $externalAudioPath,
                        trim($mediaAsset->hls_output_relative_directory, '/'),
                        $audioTrack['key'],
                        $audioTrack['label'],
                        false,
                        $audioTrack['language']
                    );
                } catch (Throwable $audioException) {
                    $logs[] = 'External audio extraction skipped: ' . $audioException->getMessage();
                }
            }

            $subtitleStreams = $movie->has_subtitle
                ? $this->probeSubtitleStreams($sourcePath)
                : [];

            foreach ($subtitleStreams as $subtitleStream) {
                try {
                    $subtitleEntries[] = $this->extractSubtitleTrack(
                        $sourcePath,
                        trim($mediaAsset->hls_output_relative_directory, '/'),
                        $subtitleStream
                    );
                } catch (Throwable $subtitleException) {
                    $logs[] = 'Subtitle extraction skipped: ' . $subtitleException->getMessage();
                }
            }

            if ($movie->has_subtitle && filled($movie->subtitle_source_path)) {
                try {
                    $externalSubtitlePath = $this->mediaPathService->ensureReadableFile($movie->subtitle_source_path);
                    $subtitleEntries[] = $this->extractExternalSubtitleTrack(
                        $externalSubtitlePath,
                        trim($mediaAsset->hls_output_relative_directory, '/')
                    );
                } catch (Throwable $subtitleException) {
                    $logs[] = 'External subtitle extraction skipped: ' . $subtitleException->getMessage();
                }
            }

            $masterPlaylistAbsolutePath = $this->mediaPathService->toAbsolutePath($mediaAsset->master_playlist_relative_path);
            File::put($masterPlaylistAbsolutePath, $this->buildMasterPlaylist($masterPlaylistEntries, $subtitleEntries, $audioEntries));

            $mediaAsset->update([
                'processing_status' => 'ready',
                'processing_progress_percent' => 100,
                'processing_eta_seconds' => 0,
                'current_profile_key' => null,
                'processing_error' => null,
                'stop_requested' => false,
                'processing_pid' => null,
                'last_processed_at' => now(),
            ]);

            $movie->update([
                'processing_status' => 'ready',
                'processing_error' => null,
            ]);

            $run->update([
                'status' => 'completed',
                'progress_percent' => 100,
                'eta_seconds' => 0,
                'current_profile_key' => null,
                'ffmpeg_command' => implode(PHP_EOL . PHP_EOL, $commands),
                'logs' => trim(implode(PHP_EOL . PHP_EOL, array_filter($logs))),
                'finished_at' => now(),
            ]);

            return $run->refresh();
        } catch (Throwable $exception) {
            $mediaAsset->refresh();
            $wasStopped = $mediaAsset->stop_requested || $exception->getMessage() === 'Processing was stopped by admin.';

            if ($wasStopped) {
                $this->cleanupManagedOutputs($mediaAsset);
                $this->markAssetState($mediaAsset, 'pending', null, [
                    'processing_progress_percent' => 0,
                    'processing_eta_seconds' => null,
                    'current_profile_key' => null,
                    'processing_started_at' => null,
                    'stop_requested' => false,
                    'processing_pid' => null,
                ]);
            } else {
                $this->markAssetState($mediaAsset, 'failed', $exception->getMessage(), [
                    'processing_eta_seconds' => null,
                    'stop_requested' => false,
                    'processing_pid' => null,
                ]);
            }

            $run->update([
                'status' => $wasStopped ? 'cancelled' : 'failed',
                'ffmpeg_command' => implode(PHP_EOL . PHP_EOL, $commands),
                'logs' => trim(implode(PHP_EOL . PHP_EOL, array_filter($logs))),
                'error_message' => $wasStopped ? 'Processing was stopped by admin.' : $exception->getMessage(),
                'finished_at' => now(),
            ]);

            if (!$wasStopped) {
                throw $exception;
            }

            return $run->refresh();
        }
    }

    public function syncSupplementalTracks(MediaAsset $mediaAsset): void
    {
        $mediaAsset->loadMissing(['mediable', 'variants']);

        /** @var Movie|null $movie */
        $movie = $mediaAsset->mediable instanceof Movie ? $mediaAsset->mediable : null;

        if (!$movie) {
            throw new RuntimeException('Media asset is not attached to a movie.');
        }

        $hlsRelativeDirectory = trim($mediaAsset->hls_output_relative_directory, '/');
        $hlsAbsoluteDirectory = $this->mediaPathService->toAbsolutePath($hlsRelativeDirectory);

        if (!File::exists($hlsAbsoluteDirectory)) {
            throw new RuntimeException('HLS output directory does not exist for this movie.');
        }

        $audioDirectory = $hlsAbsoluteDirectory . DIRECTORY_SEPARATOR . 'audio';
        $subtitleDirectory = $hlsAbsoluteDirectory . DIRECTORY_SEPARATOR . 'subtitles';

        if (File::exists($audioDirectory)) {
            File::deleteDirectory($audioDirectory);
        }

        if (File::exists($subtitleDirectory)) {
            File::deleteDirectory($subtitleDirectory);
        }

        $sourcePath = $this->mediaPathService->ensureReadableFile($mediaAsset->source_path);
        $audioEntries = [];
        $subtitleEntries = [];
        $logs = [];

        $audioEntries[] = $this->extractAudioTrack(
            $sourcePath,
            $hlsRelativeDirectory,
            'original',
            'صدای اصلی',
            true
        );

        foreach ($this->resolveExternalAudioTracks($movie) as $audioTrack) {
            try {
                $externalAudioPath = $this->mediaPathService->ensureReadableFile($audioTrack['source_path']);
                $audioEntries[] = $this->extractAudioTrack(
                    $externalAudioPath,
                    $hlsRelativeDirectory,
                    $audioTrack['key'],
                    $audioTrack['label'],
                    false,
                    $audioTrack['language']
                );
            } catch (Throwable $audioException) {
                $logs[] = 'External audio extraction skipped: ' . $audioException->getMessage();
            }
        }

        if ($movie->has_subtitle) {
            foreach ($this->probeSubtitleStreams($sourcePath) as $subtitleStream) {
                try {
                    $subtitleEntries[] = $this->extractSubtitleTrack(
                        $sourcePath,
                        $hlsRelativeDirectory,
                        $subtitleStream
                    );
                } catch (Throwable $subtitleException) {
                    $logs[] = 'Subtitle extraction skipped: ' . $subtitleException->getMessage();
                }
            }

            if (filled($movie->subtitle_source_path)) {
                try {
                    $externalSubtitlePath = $this->mediaPathService->ensureReadableFile($movie->subtitle_source_path);
                    $subtitleEntries[] = $this->extractExternalSubtitleTrack(
                        $externalSubtitlePath,
                        $hlsRelativeDirectory
                    );
                } catch (Throwable $subtitleException) {
                    $logs[] = 'External subtitle extraction skipped: ' . $subtitleException->getMessage();
                }
            }
        }

        $masterPlaylistEntries = $mediaAsset->variants
            ->sortBy('bandwidth')
            ->values()
            ->map(function ($variant) use ($hlsRelativeDirectory) {
                $playlist = ltrim(str_replace('\\', '/', $variant->playlist_relative_path), '/');
                $baseDirectory = ltrim(str_replace('\\', '/', $hlsRelativeDirectory), '/');

                if (str_starts_with($playlist, $baseDirectory . '/')) {
                    $playlist = substr($playlist, strlen($baseDirectory) + 1);
                }

                return [
                    'bandwidth' => $variant->bandwidth,
                    'resolution' => $variant->resolution,
                    'playlist' => $playlist,
                    'codecs' => $variant->codecs,
                ];
            })
            ->all();

        $masterPlaylistAbsolutePath = $this->mediaPathService->toAbsolutePath($mediaAsset->master_playlist_relative_path);
        File::put($masterPlaylistAbsolutePath, $this->buildMasterPlaylist($masterPlaylistEntries, $subtitleEntries, $audioEntries));

        if ($logs !== []) {
            $mediaAsset->processingRuns()->create([
                'status' => 'completed',
                'progress_percent' => 100,
                'eta_seconds' => 0,
                'current_profile_key' => null,
                'ffmpeg_command' => 'supplemental-track-sync',
                'logs' => trim(implode(PHP_EOL, $logs)),
                'started_at' => now(),
                'finished_at' => now(),
            ]);
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
            '-loglevel',
            'error',
            '-i',
            $sourcePath,
            '-vf',
            $scaleFilter,
            '-c:v',
            'libx264',
            '-preset',
            'veryfast',
            '-profile:v',
            'high',
            '-pix_fmt',
            'yuv420p',
            '-crf',
            '21',
            '-g',
            '48',
            '-keyint_min',
            '48',
            '-sc_threshold',
            '0',
            '-an',
            '-b:v',
            (string) $profile['video_bitrate'],
            '-maxrate',
            (string) $profile['maxrate'],
            '-bufsize',
            (string) $profile['bufsize'],
            '-progress',
            'pipe:1',
            '-nostats',
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
     * @param  array<int, array<string, mixed>>  $subtitleEntries
     * @param  array<int, array<string, mixed>>  $audioEntries
     */
    private function buildMasterPlaylist(array $entries, array $subtitleEntries = [], array $audioEntries = []): string
    {
        $lines = ['#EXTM3U', '#EXT-X-VERSION:3'];

        foreach ($audioEntries as $audioEntry) {
            $line = '#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID="audio"';
            $line .= ',NAME="' . addslashes((string) $audioEntry['name']) . '"';
            $line .= ',LANGUAGE="' . addslashes((string) $audioEntry['language']) . '"';
            $line .= ',AUTOSELECT=YES';
            $line .= ',DEFAULT=' . (!empty($audioEntry['default']) ? 'YES' : 'NO');
            $line .= ',URI="' . addslashes((string) $audioEntry['uri']) . '"';
            $lines[] = $line;
        }

        foreach ($subtitleEntries as $subtitleEntry) {
            $line = '#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID="subs"';
            $line .= ',NAME="' . addslashes((string) $subtitleEntry['name']) . '"';
            $line .= ',LANGUAGE="' . addslashes((string) $subtitleEntry['language']) . '"';
            $line .= ',AUTOSELECT=YES';
            $line .= ',DEFAULT=' . (!empty($subtitleEntry['default']) ? 'YES' : 'NO');
            $line .= ',FORCED=NO';
            $line .= ',URI="' . addslashes((string) $subtitleEntry['uri']) . '"';
            $lines[] = $line;
        }

        foreach ($entries as $entry) {
            $streamInfo = "#EXT-X-STREAM-INF:BANDWIDTH={$entry['bandwidth']},RESOLUTION={$entry['resolution']}";

            if (!empty($entry['codecs'])) {
                $streamInfo .= ",CODECS=\"{$entry['codecs']}\"";
            }

            if ($audioEntries !== []) {
                $streamInfo .= ',AUDIO="audio"';
            }

            if ($subtitleEntries !== []) {
                $streamInfo .= ',SUBTITLES="subs"';
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

    private function markAssetState(MediaAsset $mediaAsset, string $status, ?string $error = null, array $extra = []): void
    {
        $mediaAsset->update(array_merge([
            'processing_status' => $status,
            'processing_error' => $error,
        ], $extra));

        if ($mediaAsset->mediable instanceof Movie) {
            $mediaAsset->mediable->update([
                'processing_status' => $status,
                'processing_error' => $error,
            ]);
        }
    }

    private function probeSourceDurationSeconds(string $sourcePath): int
    {
        $ffprobeBinary = $this->resolveSiblingBinary((string) config('cinema.ffmpeg_binary', 'ffmpeg'), 'ffprobe');
        $sourceAbsolutePath = $this->mediaPathService->ensureReadableFile($sourcePath);
        $process = new Process([
            $ffprobeBinary,
            '-v',
            'error',
            '-show_entries',
            'format=duration',
            '-of',
            'default=noprint_wrappers=1:nokey=1',
            $sourceAbsolutePath,
        ]);
        $process->setTimeout(60);
        $process->run();

        if (!$process->isSuccessful()) {
            return 0;
        }

        return max(0, (int) round((float) trim($process->getOutput())));
    }

    /**
     * @return array<int, array{subtitle_order: int, language: string, name: string, codec_name: string}>
     */
    private function probeSubtitleStreams(string $sourcePath): array
    {
        $ffprobeBinary = $this->resolveSiblingBinary((string) config('cinema.ffmpeg_binary', 'ffmpeg'), 'ffprobe');
        $sourceAbsolutePath = $this->mediaPathService->ensureReadableFile($sourcePath);
        $process = new Process([
            $ffprobeBinary,
            '-v',
            'error',
            '-select_streams',
            's',
            '-show_entries',
            'stream=index,codec_name:stream_tags=language,title',
            '-of',
            'json',
            $sourceAbsolutePath,
        ]);
        $process->setTimeout(60);
        $process->run();

        if (!$process->isSuccessful()) {
            return [];
        }

        /** @var array{streams?: array<int, array<string, mixed>>}|null $payload */
        $payload = json_decode($process->getOutput(), true);
        $streams = $payload['streams'] ?? [];
        $supportedCodecs = ['subrip', 'webvtt', 'mov_text', 'ass', 'ssa', 'text'];

        return collect($streams)
            ->values()
            ->map(function (array $stream, int $subtitleOrder) use ($supportedCodecs) {
                $codecName = strtolower((string) ($stream['codec_name'] ?? ''));

                if (!in_array($codecName, $supportedCodecs, true)) {
                    return null;
                }

                $tags = (array) ($stream['tags'] ?? []);
                $language = strtolower((string) ($tags['language'] ?? 'fa'));
                $name = (string) ($tags['title'] ?? '');

                if ($name === '') {
                    $name = match ($language) {
                        'fa', 'fas', 'per' => 'فارسی',
                        'en', 'eng' => 'English',
                        'ar', 'ara' => 'العربية',
                        default => strtoupper($language),
                    };
                }

                return [
                    'subtitle_order' => $subtitleOrder,
                    'language' => $language,
                    'name' => $name,
                    'codec_name' => $codecName,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array{subtitle_order: int, language: string, name: string, codec_name: string}  $subtitleStream
     * @return array{name: string, language: string, uri: string, default: bool}
     */
    private function extractSubtitleTrack(string $sourcePath, string $hlsRelativeDirectory, array $subtitleStream): array
    {
        $subtitleRelativeDirectory = $hlsRelativeDirectory . '/subtitles';
        $subtitleAbsoluteDirectory = $this->mediaPathService->toAbsolutePath($subtitleRelativeDirectory);
        File::ensureDirectoryExists($subtitleAbsoluteDirectory);

        $safeLanguage = preg_replace('/[^a-z0-9_-]+/i', '-', $subtitleStream['language']) ?: 'subtitle';
        $subtitleFileName = sprintf('%02d-%s.vtt', $subtitleStream['subtitle_order'], strtolower($safeLanguage));
        $subtitleAbsolutePath = $subtitleAbsoluteDirectory . DIRECTORY_SEPARATOR . $subtitleFileName;

        $command = [
            (string) config('cinema.ffmpeg_binary', 'ffmpeg'),
            '-y',
            '-loglevel',
            'error',
            '-i',
            $sourcePath,
            '-map',
            '0:s:' . $subtitleStream['subtitle_order'],
            '-c:s',
            'webvtt',
            $subtitleAbsolutePath,
        ];

        $process = new Process($command);
        $process->setTimeout(120);
        $process->run();

        if (!$process->isSuccessful() || !File::exists($subtitleAbsolutePath)) {
            throw new RuntimeException(
                trim($process->getErrorOutput()) !== ''
                    ? trim($process->getErrorOutput())
                    : 'Subtitle extraction failed.'
            );
        }

        return [
            'name' => $subtitleStream['name'],
            'language' => $subtitleStream['language'],
            'uri' => 'subtitles/' . $subtitleFileName,
            'default' => $subtitleStream['subtitle_order'] === 0,
        ];
    }

    /**
     * @return array{name: string, language: string, uri: string, default: bool}
     */
    private function extractExternalSubtitleTrack(string $subtitleSourcePath, string $hlsRelativeDirectory): array
    {
        $subtitleRelativeDirectory = $hlsRelativeDirectory . '/subtitles';
        $subtitleAbsoluteDirectory = $this->mediaPathService->toAbsolutePath($subtitleRelativeDirectory);
        File::ensureDirectoryExists($subtitleAbsoluteDirectory);

        $subtitleAbsolutePath = $subtitleAbsoluteDirectory . DIRECTORY_SEPARATOR . 'external.vtt';

        $command = [
            (string) config('cinema.ffmpeg_binary', 'ffmpeg'),
            '-y',
            '-loglevel',
            'error',
            '-i',
            $subtitleSourcePath,
            '-c:s',
            'webvtt',
            $subtitleAbsolutePath,
        ];

        $process = new Process($command);
        $process->setTimeout(120);
        $process->run();

        if (!$process->isSuccessful() || !File::exists($subtitleAbsolutePath)) {
            throw new RuntimeException(
                trim($process->getErrorOutput()) !== ''
                    ? trim($process->getErrorOutput())
                    : 'External subtitle extraction failed.'
            );
        }

        return [
            'name' => 'زیرنویس',
            'language' => 'fa',
            'uri' => 'subtitles/external.vtt',
            'default' => true,
        ];
    }

    /**
     * @return array{name: string, language: string, uri: string, default: bool}
     */
    private function extractAudioTrack(
        string $audioSourcePath,
        string $hlsRelativeDirectory,
        string $trackKey,
        string $trackName,
        bool $isDefault,
        string $language = 'und'
    ): array {
        $audioRelativeDirectory = $hlsRelativeDirectory . '/audio/' . $trackKey;
        $audioAbsoluteDirectory = $this->mediaPathService->toAbsolutePath($audioRelativeDirectory);
        File::ensureDirectoryExists($audioAbsoluteDirectory);

        $playlistAbsolutePath = $audioAbsoluteDirectory . DIRECTORY_SEPARATOR . 'index.m3u8';
        $segmentPattern = $audioAbsoluteDirectory . DIRECTORY_SEPARATOR . 'segment_%03d.aac';
        $audioCodec = $this->probePrimaryAudioCodec($audioSourcePath);
        $canCopyAudio = in_array($audioCodec, ['aac', 'mp4a'], true);

        $command = [
            (string) config('cinema.ffmpeg_binary', 'ffmpeg'),
            '-y',
            '-loglevel',
            'error',
            '-i',
            $audioSourcePath,
            '-map',
            '0:a:0',
            '-vn',
            '-c:a',
            $canCopyAudio ? 'copy' : 'aac',
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

        if (!$canCopyAudio) {
            array_splice($command, 9, 0, ['-ar', '48000', '-b:a', '128k']);
        }

        $process = new Process($command);
        $process->setTimeout(null);
        $process->run();

        if (!$process->isSuccessful() || !File::exists($playlistAbsolutePath)) {
            throw new RuntimeException(
                trim($process->getErrorOutput()) !== ''
                    ? trim($process->getErrorOutput())
                    : 'Audio extraction failed.'
            );
        }

        return [
            'name' => $trackName,
            'language' => $language,
            'uri' => 'audio/' . $trackKey . '/index.m3u8',
            'default' => $isDefault,
        ];
    }

    /**
     * @return array<int, array{key: string, label: string, language: string, source_path: string}>
     */
    private function resolveExternalAudioTracks(Movie $movie): array
    {
        $tracks = [];

        if ($movie->has_dubbed_audio && filled($movie->dubbed_source_path)) {
            $tracks[] = [
                'key' => 'dubbed',
                'label' => 'دوبله',
                'language' => 'fa',
                'source_path' => $movie->dubbed_source_path,
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
                'source_path' => (string) $track['source_path'],
            ];
        }

        return $tracks;
    }

    private function probePrimaryAudioCodec(string $sourcePath): ?string
    {
        $ffprobeBinary = $this->resolveSiblingBinary((string) config('cinema.ffmpeg_binary', 'ffmpeg'), 'ffprobe');
        $sourceAbsolutePath = $this->mediaPathService->ensureReadableFile($sourcePath);
        $process = new Process([
            $ffprobeBinary,
            '-v',
            'error',
            '-select_streams',
            'a:0',
            '-show_entries',
            'stream=codec_name',
            '-of',
            'default=noprint_wrappers=1:nokey=1',
            $sourceAbsolutePath,
        ]);
        $process->setTimeout(60);
        $process->run();

        if (!$process->isSuccessful()) {
            return null;
        }

        $codec = strtolower(trim($process->getOutput()));

        return $codec !== '' ? $codec : null;
    }

    private function resolveSiblingBinary(string $binaryPath, string $siblingName): string
    {
        if (!str_contains($binaryPath, '/') && !str_contains($binaryPath, '\\')) {
            return $siblingName;
        }

        $directory = dirname(str_replace('/', DIRECTORY_SEPARATOR, $binaryPath));

        return $directory . DIRECTORY_SEPARATOR . $siblingName . '.exe';
    }

    /**
     * @return array<string, string>|null
     */
    private function extractLatestProgressSnapshot(string $stdoutBuffer): ?array
    {
        $lines = preg_split('/\r\n|\r|\n/', $stdoutBuffer) ?: [];
        $snapshot = [];
        $latest = null;

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $snapshot[$key] = $value;

            if ($key === 'progress') {
                $latest = $snapshot;

                if ($value === 'end') {
                    $snapshot = [];
                }
            }
        }

        return $latest;
    }

    /**
     * @param  array<string, string>  $snapshot
     */
    private function progressOutTimeSeconds(array $snapshot): float
    {
        if (!empty($snapshot['out_time_ms'])) {
            return ((float) $snapshot['out_time_ms']) / 1_000_000;
        }

        if (!empty($snapshot['out_time_us'])) {
            return ((float) $snapshot['out_time_us']) / 1_000_000;
        }

        if (!empty($snapshot['out_time'])) {
            $parts = explode(':', $snapshot['out_time']);

            if (count($parts) === 3) {
                return ((int) $parts[0] * 3600) + ((int) $parts[1] * 60) + (float) $parts[2];
            }
        }

        return 0;
    }

    private function appendLimitedBuffer(string $existing, string $chunk, int $maxBytes): string
    {
        $combined = $existing . $chunk;

        if (strlen($combined) <= $maxBytes) {
            return $combined;
        }

        return substr($combined, -1 * $maxBytes);
    }

    private function cleanupManagedOutputs(MediaAsset $mediaAsset): void
    {
        $hlsDirectory = $this->mediaPathService->toAbsolutePath($mediaAsset->hls_output_relative_directory);

        if (File::exists($hlsDirectory)) {
            File::deleteDirectory($hlsDirectory);
        }

        File::ensureDirectoryExists($hlsDirectory);
        $mediaAsset->variants()->delete();
    }

    private function terminateProcess(Process $process, int|string|null $pid = null): void
    {
        if ($process->isRunning()) {
            try {
                $process->stop(1);
            } catch (Throwable) {
            }
        }

        $resolvedPid = $pid ?: $process->getPid();

        if (!$resolvedPid) {
            return;
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            try {
                $killer = new Process(['taskkill', '/PID', (string) $resolvedPid, '/T', '/F']);
                $killer->setTimeout(10);
                $killer->run();
            } catch (Throwable) {
            }

            return;
        }

        if (function_exists('posix_kill')) {
            try {
                posix_kill((int) $resolvedPid, SIGTERM);
            } catch (Throwable) {
            }
        }
    }

    public function stop(MediaAsset $mediaAsset): void
    {
        $mediaAsset->refresh();

        $this->clearQueuedJobs($mediaAsset);

        $mediaAsset->update([
            'stop_requested' => true,
        ]);

        if ($mediaAsset->processing_status === 'queued') {
            $this->cleanupManagedOutputs($mediaAsset);
            $this->markAssetState($mediaAsset, 'pending', null, [
                'processing_progress_percent' => 0,
                'processing_eta_seconds' => null,
                'current_profile_key' => null,
                'processing_started_at' => null,
                'stop_requested' => false,
                'processing_pid' => null,
            ]);

            return;
        }

        if ($mediaAsset->processing_status === 'processing') {
            $this->terminateProcess(new Process(['cmd', '/c', 'exit']), $mediaAsset->processing_pid);
        }
    }

    public function dispose(MediaAsset $mediaAsset): void
    {
        $mediaAsset->refresh();

        $this->clearQueuedJobs($mediaAsset);

        $mediaAsset->update([
            'stop_requested' => true,
        ]);

        if ($mediaAsset->processing_pid) {
            $this->terminateProcess(new Process(['cmd', '/c', 'exit']), $mediaAsset->processing_pid);
        }

        $this->cleanupManagedOutputs($mediaAsset);
    }

    public function clearQueuedJobs(MediaAsset $mediaAsset): void
    {
        DB::table('jobs')
            ->where('queue', 'media-processing')
            ->where('payload', 'like', '%ProcessMovieMediaAssetJob%')
            ->where('payload', 'like', '%mediaAssetId%')
            ->where('payload', 'like', '%' . $mediaAsset->id . '%')
            ->delete();
    }
}
