<?php

namespace App\Services\Cinema;

use Illuminate\Support\Facades\Http;

class HlsPackageInsightService
{
    private bool $verifyRemoteDownloadTargets = true;

    private bool $resolveProgressiveDownloads = false;

    private bool $compactMetadataFilenameCandidates = false;

    private ?int $remoteHeadChecksBudget = null;

    private int $remoteHeadChecksUsed = 0;

    public function __construct(
        private readonly MediaPathService $mediaPathService,
    ) {
    }

    /**
     * Aligns with hls-worker output: package folder contains master.m3u8, metadata.json, audio/, subtitles/, video/.
     *
     * @return array{
     *   has_subtitle: bool,
     *   subtitle_source_path: ?string,
     *   has_dubbed_audio: bool,
     *   dubbed_source_path: ?string,
     *   audio_tracks: array<int, array{label: string, language: string, source_path: string}>,
     *   downloads: array<string, string>
     * }
     */
    /**
     * @param  bool  $verifyRemoteDownloadTargets  When false, progressive download URLs are built without HTTP HEAD checks (only used when resolveProgressiveDownloads is true).
     * @param  bool  $resolveProgressiveDownloads  When false (default), never infer MP4 download URLs from HLS metadata/manifest (admin sets download links manually).
     */
    public function extractPlaybackInsights(
        ?string $canonicalMasterUrl,
        ?string $manifestBody = null,
        bool $verifyRemoteDownloadTargets = true,
        bool $resolveProgressiveDownloads = false,
    ): array {
        $empty = $this->emptyPlaybackInsights();

        if (!filled($canonicalMasterUrl)) {
            return $empty;
        }

        $previousVerify = $this->verifyRemoteDownloadTargets;
        $previousResolve = $this->resolveProgressiveDownloads;
        $this->verifyRemoteDownloadTargets = $verifyRemoteDownloadTargets;
        $this->resolveProgressiveDownloads = $resolveProgressiveDownloads;

        try {
            $fromMeta = $empty;
            $metaPayload = $this->loadHlsWorkerMetadataPayload((string) $canonicalMasterUrl);

            if ($metaPayload !== null) {
                $fromMeta = $this->insightsFromHlsWorkerMetadata($metaPayload, (string) $canonicalMasterUrl);
            }

            $manifest = $manifestBody;
            if (!filled($manifest)) {
                try {
                    $manifest = $this->loadHlsContent((string) $canonicalMasterUrl);
                } catch (\Throwable) {
                    $manifest = null;
                }
            }

            $fromManifest = filled($manifest)
                ? $this->insightsFromMasterManifest((string) $canonicalMasterUrl, (string) $manifest)
                : $empty;

            return $this->mergePlaybackInsights($fromMeta, $fromManifest);
        } finally {
            $this->verifyRemoteDownloadTargets = $previousVerify;
            $this->resolveProgressiveDownloads = $previousResolve;
        }
    }

    /**
     * @return array{
     *   has_subtitle: bool,
     *   subtitle_source_path: ?string,
     *   has_dubbed_audio: bool,
     *   dubbed_source_path: ?string,
     *   audio_tracks: array<int, array{label: string, language: string, source_path: string}>,
     *   downloads: array<string, string>
     * }
     */
    public function emptyPlaybackInsights(): array
    {
        return [
            'has_subtitle' => false,
            'subtitle_source_path' => null,
            'has_dubbed_audio' => false,
            'dubbed_source_path' => null,
            'audio_tracks' => [],
            'downloads' => [],
            'dash_url' => null,
        ];
    }

    /**
     * Confirms a DASH manifest is reachable before exposing it to playback clients.
     */
    public function verifyDashManifestUrl(?string $url): bool
    {
        if (! filled($url)) {
            return false;
        }

        $trimmed = trim((string) $url);

        if ($this->mediaPathService->isRemoteMediaReference($trimmed)) {
            $remoteUrl = preg_match('#^https?://#i', $trimmed) === 1
                ? $trimmed
                : $this->mediaPathService->coerceRemoteMediaUrl($trimmed);

            try {
                $response = Http::connectTimeout(2)
                    ->timeout(3)
                    ->withOptions([
                        'verify' => (bool) config('cinema.remote_media_ssl_verify', true),
                        'allow_redirects' => ['max' => 3, 'protocols' => ['http', 'https']],
                    ])
                    ->withHeaders([
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                    ])
                    ->head($remoteUrl);

                return $response->successful();
            } catch (\Throwable) {
                return false;
            }
        }

        try {
            $this->mediaPathService->ensureReadableFile($trimmed);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $meta
     *
     * @return array{
     *   has_subtitle: bool,
     *   subtitle_source_path: ?string,
     *   has_dubbed_audio: bool,
     *   dubbed_source_path: ?string,
     *   audio_tracks: array<int, array{label: string, language: string, source_path: string}>,
     *   downloads: array<string, string>
     * }
     */
    private function insightsFromHlsWorkerMetadata(array $meta, string $masterPlaylistUrl): array
    {
        $audioTracks = [];
        $hasDubbed = (bool) ($meta['has_dubbed'] ?? false);
        $hasSubtitle = (bool) ($meta['has_subtitle'] ?? false);
        $dubbedSourcePath = null;

        foreach ($meta['audio_tracks'] ?? [] as $track) {
            if (!is_array($track)) {
                continue;
            }

            $relative = trim((string) ($track['uri'] ?? ''));
            $resolved = $relative !== '' ? $this->resolveHlsUri($masterPlaylistUrl, $relative) : null;

            if ($resolved === null || $resolved === '') {
                continue;
            }

            $audioTracks[] = [
                'label' => trim((string) ($track['name'] ?? 'Audio track')) ?: 'Audio track',
                'language' => strtolower(trim((string) ($track['language'] ?? 'und'))) ?: 'und',
                'source_path' => $resolved,
            ];

            if (($track['type'] ?? '') === 'dubbed') {
                $hasDubbed = true;
                $dubbedSourcePath ??= $resolved;
            }
        }

        $subtitleSourcePath = null;

        foreach ($meta['subtitles'] ?? [] as $subtitle) {
            if (!is_array($subtitle)) {
                continue;
            }

            $hasSubtitle = true;
            $relative = trim((string) ($subtitle['uri'] ?? ''));
            $resolved = $relative !== '' ? $this->resolveHlsUri($masterPlaylistUrl, $relative) : null;

            if ($resolved !== null && $resolved !== '' && $subtitleSourcePath === null) {
                $subtitleSourcePath = $resolved;
            }
        }

        $downloads = $this->resolveProgressiveDownloads
            ? $this->buildProgressiveDownloadMap($masterPlaylistUrl, $meta)
            : [];

        $dashRelative = trim((string) ($meta['dash_url'] ?? $meta['dash_manifest'] ?? $meta['mpd_url'] ?? ''));
        $dashUrl = $dashRelative !== ''
            ? $this->resolveHlsUri($masterPlaylistUrl, $dashRelative)
            : null;

        if ($dashUrl === null || $dashUrl === '') {
            $base = preg_replace('#/master\.m3u8(\?.*)?$#i', '', $masterPlaylistUrl);
            $candidate = $base !== '' ? rtrim($base, '/').'/manifest.mpd' : null;
            $dashUrl = filled($candidate) ? $candidate : null;
        }

        return [
            'has_subtitle' => $hasSubtitle,
            'subtitle_source_path' => $subtitleSourcePath,
            'has_dubbed_audio' => $hasDubbed,
            'dubbed_source_path' => $dubbedSourcePath,
            'audio_tracks' => $audioTracks,
            'downloads' => $downloads,
            'dash_url' => $dashUrl,
        ];
    }

    /**
     * @param array{
     *   has_subtitle: bool,
     *   subtitle_source_path: ?string,
     *   has_dubbed_audio: bool,
     *   dubbed_source_path: ?string,
     *   audio_tracks: array<int, array{label: string, language: string, source_path: string}>,
     *   downloads: array<string, string>
     * } $primary
     * @param array{
     *   has_subtitle: bool,
     *   subtitle_source_path: ?string,
     *   has_dubbed_audio: bool,
     *   dubbed_source_path: ?string,
     *   audio_tracks: array<int, array{label: string, language: string, source_path: string}>,
     *   downloads: array<string, string>
     * } $secondary
     *
     * @return array{
     *   has_subtitle: bool,
     *   subtitle_source_path: ?string,
     *   has_dubbed_audio: bool,
     *   dubbed_source_path: ?string,
     *   audio_tracks: array<int, array{label: string, language: string, source_path: string}>,
     *   downloads: array<string, string>
     * }
     */
    private function mergePlaybackInsights(array $primary, array $secondary): array
    {
        return [
            'has_subtitle' => ($primary['has_subtitle'] ?? false) || ($secondary['has_subtitle'] ?? false),
            'subtitle_source_path' => $primary['subtitle_source_path'] ?? $secondary['subtitle_source_path'] ?? null,
            'has_dubbed_audio' => ($primary['has_dubbed_audio'] ?? false) || ($secondary['has_dubbed_audio'] ?? false),
            'dubbed_source_path' => $primary['dubbed_source_path'] ?? $secondary['dubbed_source_path'] ?? null,
            'audio_tracks' => ($primary['audio_tracks'] ?? []) !== [] ? $primary['audio_tracks'] : ($secondary['audio_tracks'] ?? []),
            'downloads' => array_merge($secondary['downloads'] ?? [], $primary['downloads'] ?? []),
            'dash_url' => $primary['dash_url'] ?? $secondary['dash_url'] ?? null,
        ];
    }

    /**
     * @return array{
     *   has_subtitle: bool,
     *   subtitle_source_path: ?string,
     *   has_dubbed_audio: bool,
     *   dubbed_source_path: ?string,
     *   audio_tracks: array<int, array{label: string, language: string, source_path: string}>,
     *   downloads: array<string, string>
     * }
     */
    private function insightsFromMasterManifest(string $masterPlaylistUrl, string $manifest): array
    {
        $manifest = str_replace("\r", '', $manifest);
        $lines = preg_split('/\n+/', $manifest) ?: [];
        $audioTracks = [];
        $subtitleSourcePath = null;
        $hasSubtitle = false;
        $hasDubbedAudio = false;
        $dubbedSourcePath = null;
        $downloads = [];

        foreach ($lines as $index => $line) {
            $line = trim((string) $line);

            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, '#EXT-X-MEDIA:')) {
                $attributes = $this->parseHlsAttributes(substr($line, strlen('#EXT-X-MEDIA:')));
                $type = strtoupper((string) ($attributes['TYPE'] ?? ''));
                $uri = trim((string) ($attributes['URI'] ?? ''), "\"'");
                $resolvedUri = $this->resolveHlsUri($masterPlaylistUrl, $uri);

                if ($type === 'SUBTITLES') {
                    $hasSubtitle = true;

                    if ($subtitleSourcePath === null && $resolvedUri !== null && $resolvedUri !== '') {
                        $subtitleSourcePath = $resolvedUri;
                    }
                }

                if ($type === 'AUDIO' && $resolvedUri !== null) {
                    $name = trim((string) ($attributes['NAME'] ?? 'Audio track'), "\"'");
                    $language = strtolower(trim((string) ($attributes['LANGUAGE'] ?? 'und'), "\"'"));
                    $audioTracks[] = [
                        'label' => $name !== '' ? $name : 'Audio track',
                        'language' => $language !== '' ? $language : 'und',
                        'source_path' => $resolvedUri,
                    ];

                    $labelHint = mb_strtolower($name);
                    $isDubbed = in_array($language, ['fa', 'fas', 'per'], true)
                        || str_contains($labelHint, 'dub')
                        || str_contains($labelHint, 'دوب');

                    if ($isDubbed) {
                        $hasDubbedAudio = true;
                        $dubbedSourcePath ??= $resolvedUri;
                    }
                }
            }

            if ($this->resolveProgressiveDownloads && str_starts_with($line, '#EXT-X-STREAM-INF:')) {
                $attributes = $this->parseHlsAttributes(substr($line, strlen('#EXT-X-STREAM-INF:')));
                $resolution = (string) ($attributes['RESOLUTION'] ?? '');
                $quality = null;

                if (preg_match('/x(\d+)/i', $resolution, $matches) === 1) {
                    $quality = $matches[1] . 'p';
                }

                if ($quality && preg_match('/^(\d+)p$/', $quality, $qm) === 1) {
                    $probed = $this->probeSourceMediaUrl($masterPlaylistUrl, $qm[1]);

                    if ($probed !== null) {
                        $downloads[$quality] = $probed;
                    }
                }
            }
        }

        if (!$hasDubbedAudio && count($audioTracks) > 1) {
            $hasDubbedAudio = true;
            $dubbedSourcePath = $audioTracks[1]['source_path'] ?? null;
        }

        return [
            'has_subtitle' => $hasSubtitle,
            'subtitle_source_path' => $subtitleSourcePath,
            'has_dubbed_audio' => $hasDubbedAudio,
            'dubbed_source_path' => $dubbedSourcePath,
            'audio_tracks' => $audioTracks,
            'downloads' => $downloads,
        ];
    }

    /**
     * @return ?array<string, mixed>
     */
    private function loadHlsWorkerMetadataPayload(string $masterPlaylistUrl): ?array
    {
        if (!filled($masterPlaylistUrl)) {
            return null;
        }

        if (preg_match('#^https?://#i', $masterPlaylistUrl) === 1) {
            $base = rtrim(str_replace('\\', '/', $this->packageRootBaseUrl($masterPlaylistUrl)), '/');
            $metaUrl = $base . '/metadata.json';

            try {
                $encoded = $this->encodeHttpUrlPathSegments($metaUrl);
                $body = $this->httpGetRemote($encoded);

                if ($body === null) {
                    return null;
                }

                $decoded = json_decode($body, true);

                return is_array($decoded) ? $decoded : null;
            } catch (\Throwable) {
                return null;
            }
        }

        $path = dirname($masterPlaylistUrl) . DIRECTORY_SEPARATOR . 'metadata.json';

        if (!is_readable($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function packageRootBaseUrl(string $masterPlaylistUrl): string
    {
        if (preg_match('#^https?://#i', $masterPlaylistUrl) === 1) {
            $normalized = str_replace('\\', '/', $masterPlaylistUrl);
            $pos = strrpos($normalized, '/');

            if ($pos === false) {
                return $masterPlaylistUrl;
            }

            return substr($masterPlaylistUrl, 0, $pos);
        }

        return dirname($masterPlaylistUrl);
    }

    public function normalizeHlsPackageMasterUrl(?string $input): ?string
    {
        if (!filled($input)) {
            return null;
        }

        $trimmed = trim((string) $input);

        if ($trimmed === '') {
            return null;
        }

        $trimmed = $this->mediaPathService->coerceRemoteMediaUrl($trimmed);

        if (preg_match('#^https?://#i', $trimmed) === 1) {
            $trimmed = $this->normalizeSpacesInHttpUrl($trimmed);
            $encoded = $this->encodeHttpUrlPathSegments($trimmed);
            $path = parse_url($encoded, PHP_URL_PATH) ?: '';

            if (preg_match('#\.m3u8$#i', (string) $path) === 1) {
                return $encoded;
            }

            return rtrim($encoded, '/') . '/master.m3u8';
        }

        $local = $trimmed;

        if (!preg_match('#\.m3u8$#i', $local)) {
            $sep = str_contains($local, '\\') ? '\\' : DIRECTORY_SEPARATOR;
            $local = rtrim($local, '/\\') . $sep . 'master.m3u8';
        }

        return $local;
    }

    private function encodeHttpUrlPathSegments(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        $scheme = $parts['scheme'] . '://';
        $auth = '';

        if (isset($parts['user'])) {
            $auth = $parts['user'];

            if (isset($parts['pass'])) {
                $auth .= ':' . $parts['pass'];
            }

            $auth .= '@';
        }

        $host = $parts['host'];
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '';
        $segments = explode('/', $path);
        $encodedSegments = [];

        foreach ($segments as $segment) {
            if ($segment === '') {
                $encodedSegments[] = '';
                continue;
            }

            $encodedSegments[] = rawurlencode(rawurldecode($segment));
        }

        $encodedPath = implode('/', $encodedSegments);
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        return $scheme . $auth . $host . $port . $encodedPath . $query . $fragment;
    }

    /**
     * @return array<string, string>
     */
    private function parseHlsAttributes(string $attributeString): array
    {
        $attributes = [];
        preg_match_all('/([A-Z0-9-]+)=((?:"[^"]*")|[^,]*)/', $attributeString, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $key = trim((string) ($match[1] ?? ''));
            $value = trim((string) ($match[2] ?? ''));
            if ($key === '') {
                continue;
            }
            $attributes[$key] = $value;
        }

        return $attributes;
    }

    public function normalizeDirectoryUrl(?string $input): ?string
    {
        if (!filled($input)) {
            return null;
        }

        $trimmed = trim((string) $input);
        $trimmed = $this->mediaPathService->coerceRemoteMediaUrl($trimmed);
        $trimmed = preg_replace('#/master\.m3u8$#i', '', $trimmed) ?? $trimmed;
        $trimmed = preg_replace('#/episode-\d+(/?)$#i', '', $trimmed) ?? $trimmed;
        $trimmed = preg_replace('#/season-\d+(/?)$#i', '', $trimmed) ?? $trimmed;

        return rtrim($trimmed, '/') ?: null;
    }

    /**
     * @param  array<int, array{season: int, episode: int}>  $episodes
     * @return array<string, array{exists: bool, hls_url: ?string}>  Keys like "s01e01"
     */
    public function checkEpisodesExistParallel(string $baseUrl, array $episodes): array
    {
        if (preg_match('#^https?://#i', $baseUrl) === 1) {
            return $this->checkRemoteEpisodesParallel($baseUrl, $episodes);
        }

        return $this->checkLocalEpisodes($baseUrl, $episodes);
    }

    /**
     * @return array<int, string>
     */
    private function episodeHlsPathCandidates(string $baseUrl, int $season, int $episode): array
    {
        $base = rtrim($baseUrl, '/');
        $candidates = [
            sprintf('%s/episode-%02d/master.m3u8', $base, $episode),
            sprintf('%s/season-%02d/episode-%02d/master.m3u8', $base, $season, $episode),
        ];

        return array_values(array_unique($candidates));
    }

    /**
     * @param  array<int, array{season: int, episode: int}>  $episodes
     * @return array<string, array{exists: bool, hls_url: ?string}>
     */
    private function checkRemoteEpisodesParallel(string $baseUrl, array $episodes): array
    {
        $existing = [];
        $baseUrl = rtrim($baseUrl, '/');
        $requestPlan = [];

        foreach ($episodes as $epIndex => $ep) {
            foreach ($this->episodeHlsPathCandidates($baseUrl, $ep['season'], $ep['episode']) as $candidateIndex => $url) {
                $requestPlan[] = [
                    'key' => sprintf('s%02de%02d', $ep['season'], $ep['episode']),
                    'pool_key' => "ep{$epIndex}c{$candidateIndex}",
                    'url' => $url,
                ];
            }
        }

        $responses = Http::pool(function ($pool) use ($requestPlan) {
            foreach ($requestPlan as $request) {
                $pool->as($request['pool_key'])
                    ->connectTimeout(2)
                    ->timeout(5)
                    ->withOptions([
                        'verify' => (bool) config('cinema.remote_media_ssl_verify', true),
                        'allow_redirects' => ['max' => 3, 'protocols' => ['http', 'https']],
                    ])
                    ->withHeaders([
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                    ])
                    ->head($request['url']);
            }
        });

        foreach ($requestPlan as $request) {
            $key = $request['key'];

            if (($existing[$key]['exists'] ?? false) === true) {
                continue;
            }

            $response = $responses[$request['pool_key']] ?? null;

            if (
                $response instanceof \Illuminate\Http\Client\Response
                && $response->successful()
            ) {
                $existing[$key] = [
                    'exists' => true,
                    'hls_url' => $request['url'],
                ];
            }
        }

        foreach ($episodes as $ep) {
            $key = sprintf('s%02de%02d', $ep['season'], $ep['episode']);
            $existing[$key] ??= [
                'exists' => false,
                'hls_url' => null,
            ];
        }

        return $existing;
    }

    /**
     * @param  array<int, array{season: int, episode: int}>  $episodes
     * @return array<string, array{exists: bool, hls_url: ?string}>
     */
    /**
     * @return array<int, string>
     */
    private function localEpisodeHlsPathCandidates(string $basePath, int $season, int $episode): array
    {
        $sep = DIRECTORY_SEPARATOR;
        $basePath = rtrim($basePath, '/\\');

        return [
            $basePath . $sep . sprintf('episode-%02d%smaster.m3u8', $episode, $sep),
            $basePath . $sep . sprintf('season-%02d%sepisode-%02d%smaster.m3u8', $season, $sep, $episode, $sep),
        ];
    }

    /**
     * @param  array<int, array{season: int, episode: int}>  $episodes
     * @return array<string, array{exists: bool, hls_url: ?string}>
     */
    private function checkLocalEpisodes(string $basePath, array $episodes): array
    {
        $existing = [];

        foreach ($episodes as $ep) {
            $key = sprintf('s%02de%02d', $ep['season'], $ep['episode']);
            $resolvedUrl = null;

            foreach ($this->localEpisodeHlsPathCandidates($basePath, $ep['season'], $ep['episode']) as $localPath) {
                if (file_exists($localPath)) {
                    $resolvedUrl = str_replace(DIRECTORY_SEPARATOR, '/', $localPath);
                    break;
                }
            }

            $existing[$key] = [
                'exists' => $resolvedUrl !== null,
                'hls_url' => $resolvedUrl,
            ];
        }

        return $existing;
    }

    public function masterPlaylistIsAvailable(?string $input): bool
    {
        $canonical = $this->normalizeHlsPackageMasterUrl($input);

        if (!filled($canonical)) {
            return false;
        }

        $manifest = $this->loadHlsContent((string) $canonical);

        return filled($manifest) && stripos((string) $manifest, '#EXTM3U') !== false;
    }

    /**
     * Progressive MP4/MKV files under video/sources/{height}/ relative to the HLS package root.
     *
     * @return array<string, string> e.g. ["1080p" => "http://.../video/sources/1080/source.mp4"]
     */
    /**
     * Admin HLS preview: bounded remote HEAD checks so requests finish in seconds, not minutes.
     *
     * @return array<string, string>
     */
    public function resolveProgressiveDownloadLinksForPreview(?string $masterUrl): array
    {
        return $this->resolveProgressiveDownloadLinksInternal($masterUrl, headChecksBudget: 16, compactCandidates: true);
    }

    /**
     * @return array<string, string>
     */
    public function resolveProgressiveDownloadLinks(?string $masterUrl): array
    {
        return $this->resolveProgressiveDownloadLinksInternal($masterUrl, headChecksBudget: 48, compactCandidates: true);
    }

    /**
     * @return array<string, string>
     */
    private function resolveProgressiveDownloadLinksInternal(
        ?string $masterUrl,
        int $headChecksBudget,
        bool $compactCandidates,
    ): array {
        if (!filled($masterUrl)) {
            return [];
        }

        $canonical = $this->normalizeHlsPackageMasterUrl($masterUrl);

        if (!filled($canonical)) {
            return [];
        }

        $previousResolve = $this->resolveProgressiveDownloads;
        $previousVerify = $this->verifyRemoteDownloadTargets;
        $previousBudget = $this->remoteHeadChecksBudget;
        $previousUsed = $this->remoteHeadChecksUsed;
        $previousCompact = $this->compactMetadataFilenameCandidates;
        $this->resolveProgressiveDownloads = true;
        $this->verifyRemoteDownloadTargets = true;
        $this->remoteHeadChecksBudget = max(0, $headChecksBudget);
        $this->remoteHeadChecksUsed = 0;
        $this->compactMetadataFilenameCandidates = $compactCandidates;

        try {
            $meta = $this->loadHlsWorkerMetadataPayload((string) $canonical) ?? [];
            $manifest = $this->loadHlsContent((string) $canonical);
            $downloads = [];

            if ($meta !== []) {
                $downloads = $this->buildProgressiveDownloadMap((string) $canonical, $meta);
            }

            $heights = $this->extractManifestHeights($manifest);

            foreach ($meta['qualities'] ?? [] as $quality) {
                $height = preg_replace('/[^0-9]/', '', (string) $quality);

                if ($height !== '') {
                    $heights[] = (int) $height;
                }
            }

            $heights = array_values(array_unique(array_filter($heights, fn (int $h) => $h > 0)));
            rsort($heights);

            foreach ($heights as $height) {
                if ($this->remoteHeadChecksBudgetReached()) {
                    break;
                }

                $label = $height . 'p';

                if (isset($downloads[$label])) {
                    continue;
                }

                $probed = $this->probeSourceMediaUrl((string) $canonical, (string) $height);

                if ($probed !== null) {
                    $downloads[$label] = $probed;
                }
            }

            uksort(
                $downloads,
                fn (string $left, string $right) => (int) preg_replace('/\D/', '', $right)
                    <=> (int) preg_replace('/\D/', '', $left),
            );

            return $downloads;
        } finally {
            $this->resolveProgressiveDownloads = $previousResolve;
            $this->verifyRemoteDownloadTargets = $previousVerify;
            $this->remoteHeadChecksBudget = $previousBudget;
            $this->remoteHeadChecksUsed = $previousUsed;
            $this->compactMetadataFilenameCandidates = $previousCompact;
        }
    }

    private function remoteHeadChecksBudgetReached(): bool
    {
        return $this->remoteHeadChecksBudget !== null
            && $this->remoteHeadChecksUsed >= $this->remoteHeadChecksBudget;
    }

    private function consumeRemoteHeadCheckBudget(): bool
    {
        if ($this->remoteHeadChecksBudget === null) {
            return true;
        }

        if ($this->remoteHeadChecksUsed >= $this->remoteHeadChecksBudget) {
            return false;
        }

        $this->remoteHeadChecksUsed++;

        return true;
    }

    /**
     * @return array<int, int>
     */
    private function extractManifestHeights(?string $manifest): array
    {
        if (!filled($manifest)) {
            return [];
        }

        $heights = [];
        $lines = preg_split('/\n+/', str_replace("\r", '', $manifest)) ?: [];

        foreach ($lines as $line) {
            $line = trim((string) $line);

            if (!str_starts_with($line, '#EXT-X-STREAM-INF:')) {
                continue;
            }

            $attributes = $this->parseHlsAttributes(substr($line, strlen('#EXT-X-STREAM-INF:')));
            $resolution = (string) ($attributes['RESOLUTION'] ?? '');

            if (preg_match('/x(\d+)/i', $resolution, $matches) === 1) {
                $heights[] = (int) $matches[1];
            }
        }

        return $heights;
    }

    /**
     * Fast accessibility probe for an HLS master URL (HEAD with 3s timeout).
     *
     * @return array{ok: bool, status: int|null, error: string|null}
     */
    public function probeHlsMasterUrl(string $hlsUrlRaw): array
    {
        $canonical = $this->normalizeHlsPackageMasterUrl(trim($hlsUrlRaw));

        if (! filled($canonical)) {
            return [
                'ok' => false,
                'status' => null,
                'error' => 'Invalid HLS URL.',
            ];
        }

        if (preg_match('#^https?://#i', $canonical) !== 1) {
            $manifest = $this->loadHlsContent($canonical);

            if (filled($manifest) && stripos((string) $manifest, '#EXTM3U') !== false) {
                return [
                    'ok' => true,
                    'status' => 200,
                    'error' => null,
                ];
            }

            return [
                'ok' => false,
                'status' => null,
                'error' => 'Local manifest is not readable or invalid.',
            ];
        }

        $candidates = [$canonical];

        if (preg_match('#^http://#i', $canonical) === 1) {
            $candidates[] = preg_replace('#^http://#i', 'https://', $canonical);
        }

        $candidates = array_values(array_unique(array_filter($candidates)));

        foreach ($candidates as $candidate) {
            $url = $this->encodeHttpUrlPathSegments($candidate);

            try {
                $response = Http::timeout(3)
                    ->withOptions([
                        'verify' => (bool) config('cinema.remote_media_ssl_verify', true),
                        'allow_redirects' => [
                            'max' => 5,
                            'strict' => false,
                            'referer' => true,
                            'protocols' => ['http', 'https'],
                        ],
                    ])
                    ->withHeaders([
                        'User-Agent' => 'Mozilla/5.0 (compatible; CinemaAdmin/1.0)',
                        'Accept' => '*/*',
                    ])
                    ->head($url);

                if (in_array($response->status(), [200, 206], true)) {
                    return [
                        'ok' => true,
                        'status' => $response->status(),
                        'error' => null,
                    ];
                }

                if (in_array($response->status(), [405, 501], true)) {
                    $getResponse = Http::timeout(3)
                        ->withOptions([
                            'verify' => (bool) config('cinema.remote_media_ssl_verify', true),
                        ])
                        ->withHeaders([
                            'User-Agent' => 'Mozilla/5.0 (compatible; CinemaAdmin/1.0)',
                            'Accept' => '*/*',
                        ])
                        ->get($url);

                    if ($getResponse->successful() && stripos($getResponse->body(), '#EXTM3U') !== false) {
                        return [
                            'ok' => true,
                            'status' => $getResponse->status(),
                            'error' => null,
                        ];
                    }
                }

                return [
                    'ok' => false,
                    'status' => $response->status(),
                    'error' => 'HLS HEAD request returned HTTP '.$response->status().'.',
                ];
            } catch (\Throwable $exception) {
                continue;
            }
        }

        return [
            'ok' => false,
            'status' => null,
            'error' => 'HLS URL probe timed out or failed.',
        ];
    }

    public function loadHlsContent(string $hlsUrl): ?string
    {
        $trimmed = trim($hlsUrl);
        if ($trimmed === '') {
            return null;
        }

        $trimmed = $this->mediaPathService->coerceRemoteMediaUrl($trimmed);

        if (preg_match('#^https?://#i', $trimmed) === 1) {
            return $this->httpGetRemote($trimmed);
        }

        $path = $this->mediaPathService->ensureReadableFile($trimmed);
        $contents = @file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    /**
     * Fetch remote text (m3u8, json). Uses browser-like headers; retries with HTTPS if HTTP fails.
     */
    private function httpGetRemote(string $url): ?string
    {
        $attempts = [$url];

        if (preg_match('#^http://#i', $url) === 1) {
            $attempts[] = preg_replace('#^http://#i', 'https://', $url);
        }

        $attempts = array_values(array_unique(array_filter($attempts)));

        foreach ($attempts as $candidate) {
            try {
                $response = Http::timeout(25)
                    ->withOptions([
                        'verify' => (bool) config('cinema.remote_media_ssl_verify', true),
                        'allow_redirects' => [
                            'max' => 10,
                            'strict' => false,
                            'referer' => true,
                            'protocols' => ['http', 'https'],
                        ],
                    ])
                    ->withHeaders([
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
                        'Accept' => '*/*',
                        'Accept-Language' => 'en-US,en;q=0.9',
                    ])
                    ->get($candidate);

                if ($response->successful()) {
                    return $response->body();
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    /**
     * Raw spaces in URL paths break parse_url / some HTTP stacks; encode before segment encoding.
     */
    private function normalizeSpacesInHttpUrl(string $url): string
    {
        $queryPos = strpos($url, '?');
        $hashPos = strpos($url, '#');

        if ($queryPos === false && $hashPos === false) {
            return (string) preg_replace('/\s+/', '%20', $url);
        }

        $cut = min(
            $queryPos !== false ? $queryPos : PHP_INT_MAX,
            $hashPos !== false ? $hashPos : PHP_INT_MAX
        );

        $base = substr($url, 0, $cut);
        $rest = substr($url, $cut);

        return (string) preg_replace('/\s+/', '%20', $base) . $rest;
    }

    private function resolveHlsUri(string $hlsUrl, string $relativeOrAbsoluteUri): ?string
    {
        $uri = trim($relativeOrAbsoluteUri, "\"'");
        if ($uri === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $uri) === 1) {
            return $uri;
        }

        if (preg_match('#^https?://#i', $hlsUrl) === 1) {
            $baseDirectory = rtrim(str_replace('\\', '/', $this->packageRootBaseUrl($hlsUrl)), '/');

            return $baseDirectory . '/' . ltrim(str_replace('\\', '/', $uri), '/');
        }

        $basePath = $this->mediaPathService->normalizeInputPath(
            $this->packageRootBaseUrl($hlsUrl) . DIRECTORY_SEPARATOR . $uri
        );

        return $basePath;
    }

    /**
     * Admin "download links" must point at progressive files (e.g. MP4 under video/sources/{height}/),
     * not at HLS variant playlists (video/{height}/index.m3u8).
     *
     * @param array<string, mixed> $meta
     *
     * @return array<string, string>
     */
    private function buildProgressiveDownloadMap(string $masterPlaylistUrl, array $meta): array
    {
        $downloads = [];

        $qualities = $meta['qualities'] ?? [];
        if (!is_array($qualities)) {
            $qualities = [];
        }

        $explicit = $meta['downloads'] ?? $meta['download_links'] ?? null;

        if (is_array($explicit)) {
            foreach ($explicit as $qualityKey => $relativePath) {
                if (!is_string($relativePath) || $relativePath === '') {
                    continue;
                }

                $label = $this->normalizeQualityDownloadKey((string) $qualityKey);
                $resolved = preg_match('#^https?://#i', $relativePath) === 1
                    ? $relativePath
                    : $this->resolveHlsUri($masterPlaylistUrl, $relativePath);

                if ($resolved === null || $resolved === '') {
                    continue;
                }

                if ($this->isHlsPlaylistUrl($resolved)) {
                    continue;
                }

                $out = preg_match('#^https?://#i', $resolved) === 1
                    ? $this->encodeHttpUrlPathSegments($resolved)
                    : $resolved;

                if ($this->downloadTargetExists($out)) {
                    $downloads[$label] = $out;
                }
            }
        }

        foreach ($qualities as $q) {
            $height = preg_replace('/[^0-9]/', '', (string) $q);

            if ($height === '') {
                continue;
            }

            $label = $height . 'p';

            if (isset($downloads[$label])) {
                continue;
            }

            $url = $this->probeSourceMediaUrl($masterPlaylistUrl, $height);

            if ($url !== null) {
                $downloads[$label] = $url;
            }
        }

        return $downloads;
    }

    private function normalizeQualityDownloadKey(string $key): string
    {
        $k = trim($key);

        if (preg_match('/^(\d+)p$/', $k) === 1) {
            return $k;
        }

        if (preg_match('/^(\d+)$/', $k, $m) === 1) {
            return $m[1] . 'p';
        }

        return $k;
    }

    private function isHlsPlaylistUrl(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?? $url;

        return str_ends_with(strtolower((string) $path), '.m3u8');
    }

    private function probeSourceMediaUrl(string $masterPlaylistUrl, string $heightDigits): ?string
    {
        $discovered = $this->discoverSourceMediaInQualityFolder($masterPlaylistUrl, $heightDigits);

        if ($discovered !== null) {
            return $discovered;
        }

        $meta = $this->loadHlsWorkerMetadataPayload($masterPlaylistUrl);

        if (is_array($meta) && $meta !== []) {
            $fromMetadata = $this->probeSourceMediaFromMetadata($masterPlaylistUrl, $heightDigits, $meta);

            if ($fromMetadata !== null) {
                return $fromMetadata;
            }
        }

        $dir = trim((string) config('cinema.hls_package_source_video_dir', 'video/sources'), '/');
        $names = config('cinema.hls_package_source_probe_filenames', []);

        if (!is_array($names) || $names === []) {
            $names = ['source.mp4'];
        }

        foreach ($names as $name) {
            if ($this->remoteHeadChecksBudgetReached()) {
                break;
            }

            $name = trim((string) $name);

            if ($name === '') {
                continue;
            }

            $rel = $dir . '/' . $heightDigits . '/' . $name;
            $resolved = $this->resolveHlsUri($masterPlaylistUrl, $rel);

            if ($resolved === null || $resolved === '') {
                continue;
            }

            $out = preg_match('#^https?://#i', $resolved) === 1
                ? $this->encodeHttpUrlPathSegments($resolved)
                : $resolved;

            if ($this->downloadTargetExists($out)) {
                return $out;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function probeSourceMediaFromMetadata(string $masterPlaylistUrl, string $heightDigits, array $meta): ?string
    {
        $dir = trim((string) config('cinema.hls_package_source_video_dir', 'video/sources'), '/');

        foreach ($this->buildMetadataSourceFilenameCandidates($meta, $heightDigits) as $filename) {
            if ($this->remoteHeadChecksBudgetReached()) {
                break;
            }

            $rel = $dir . '/' . $heightDigits . '/' . $filename;
            $resolved = $this->resolveHlsUri($masterPlaylistUrl, $rel);

            if ($resolved === null || $resolved === '') {
                continue;
            }

            $out = preg_match('#^https?://#i', $resolved) === 1
                ? $this->encodeHttpUrlPathSegments($resolved)
                : $resolved;

            if ($this->downloadTargetExists($out)) {
                return $out;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<int, string>
     */
    private function buildMetadataSourceFilenameCandidates(array $meta, string $heightDigits): array
    {
        $candidates = [];
        $title = trim((string) data_get($meta, 'series.title', ''));
        $season = (int) data_get($meta, 'series.season', 0);
        $episode = (int) data_get($meta, 'series.episode', 0);
        $height = preg_replace('/\D/', '', $heightDigits) ?: $heightDigits;

        if ($title !== '' && $season > 0 && $episode > 0) {
            $seasonCode = str_pad((string) $season, 2, '0', STR_PAD_LEFT);
            $episodeCode = str_pad((string) $episode, 2, '0', STR_PAD_LEFT);
            $episodeTag = 'S' . $seasonCode . 'E' . $episodeCode;

            $patterns = $this->compactMetadataFilenameCandidates
                ? ['mkv', 'mp4']
                : ['mkv', 'mp4', 'webm', 'm4v'];

            foreach ($patterns as $extension) {
                if ($this->compactMetadataFilenameCandidates) {
                    $candidates[] = "{$title}.{$episodeTag}.{$height}p.10bit.WEBRip.x265.PSA.{$extension}";
                    $candidates[] = "{$title}.{$episodeTag}.{$height}p.{$extension}";
                    $candidates[] = "{$title}.{$episodeTag}.{$extension}";
                    continue;
                }

                $candidates[] = "{$title}.{$episodeTag}.{$extension}";
                $candidates[] = "{$title}.{$episodeTag}.{$height}p.{$extension}";
                $candidates[] = "{$title}.{$episodeTag}.{$height}.{$extension}";
                $candidates[] = "{$title}.{$episodeTag}.{$height}p.10bit.WEBRip.x265.PSA.{$extension}";
                $candidates[] = "{$title}.{$episodeTag}.{$height}p.WEBRip.{$extension}";
                $candidates[] = "{$title}.{$episodeTag}.{$height}p.WEB-DL.{$extension}";
            }
        }

        if ($this->compactMetadataFilenameCandidates) {
            return array_values(array_unique(array_filter($candidates)));
        }

        foreach ($meta['subtitles'] ?? [] as $subtitle) {
            if (!is_array($subtitle)) {
                continue;
            }

            $name = trim((string) ($subtitle['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $stem = pathinfo($name, PATHINFO_FILENAME);

            if ($stem === '') {
                continue;
            }

            foreach (['mkv', 'mp4', 'webm'] as $extension) {
                $candidates[] = "{$stem}.{$extension}";
                $candidates[] = "{$stem}.{$height}p.{$extension}";
            }
        }

        return array_values(array_unique(array_filter($candidates)));
    }

    /**
     * @param  array<string, string>  $links
     * @return array<string, string>
     */
    public function filterExistingDownloadLinks(array $links): array
    {
        if ($links === []) {
            return [];
        }

        $previousVerify = $this->verifyRemoteDownloadTargets;
        $this->verifyRemoteDownloadTargets = true;

        $verified = [];

        try {
        foreach ($links as $quality => $url) {
            $qualityKey = trim((string) $quality);
            $urlValue = trim((string) $url);

            if ($qualityKey === '' || $urlValue === '') {
                continue;
            }

            if ($this->downloadTargetExists($urlValue)) {
                $verified[$qualityKey] = $urlValue;
            }
        }
        } finally {
            $this->verifyRemoteDownloadTargets = $previousVerify;
        }

        uksort(
            $verified,
            fn (string $left, string $right) => (int) preg_replace('/\D/', '', $right)
                <=> (int) preg_replace('/\D/', '', $left),
        );

        return $verified;
    }

    private function discoverSourceMediaInQualityFolder(string $masterPlaylistUrl, string $heightDigits): ?string
    {
        $dir = trim((string) config('cinema.hls_package_source_video_dir', 'video/sources'), '/');
        $folderRel = $dir . '/' . $heightDigits . '/';
        $folderPath = $this->resolveHlsUri($masterPlaylistUrl, $folderRel);

        if ($folderPath === null || $folderPath === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $folderPath) === 1) {
            return $this->discoverRemoteSourceMediaInFolder($folderPath);
        }

        return $this->discoverLocalSourceMediaInFolder($folderPath);
    }

    private function discoverLocalSourceMediaInFolder(string $folderPath): ?string
    {
        $normalized = rtrim(str_replace('\\', '/', $folderPath), '/');

        if (!is_dir($normalized)) {
            return null;
        }

        $candidates = [];

        foreach (scandir($normalized) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $fullPath = $normalized . DIRECTORY_SEPARATOR . $entry;

            if (!is_file($fullPath) || !$this->isProgressiveVideoFilename($entry)) {
                continue;
            }

            $candidates[] = [
                'path' => $fullPath,
                'size' => filesize($fullPath) ?: 0,
            ];
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, fn (array $left, array $right) => $right['size'] <=> $left['size']);

        return (string) $candidates[0]['path'];
    }

    private function discoverRemoteSourceMediaInFolder(string $folderUrl): ?string
    {
        $listingUrl = rtrim($this->encodeHttpUrlPathSegments($folderUrl), '/') . '/';
        $body = $this->httpGetRemote($listingUrl);

        if (!filled($body)) {
            return null;
        }

        $candidates = $this->parseDirectoryListingForVideoFiles($body);

        if ($candidates === []) {
            return null;
        }

        $base = rtrim($listingUrl, '/');

        foreach ($candidates as $filename) {
            $fileUrl = $base . '/' . ltrim(str_replace('\\', '/', $filename), '/');
            $encoded = $this->encodeHttpUrlPathSegments($fileUrl);

            if ($this->downloadTargetExists($encoded)) {
                return $encoded;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function parseDirectoryListingForVideoFiles(string $html): array
    {
        $matches = [];
        $patterns = [
            '/href=["\']([^"\']+\.(?:mkv|mp4|m4v|avi|webm|mov|ts))(?:\?[^"\']*)?["\']/i',
            '/>([^<]+\.(?:mkv|mp4|m4v|avi|webm|mov|ts))</i',
        ];

        $files = [];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $html, $matches, PREG_SET_ORDER) === false) {
                continue;
            }

            foreach ($matches as $match) {
                $href = rawurldecode(basename((string) ($match[1] ?? '')));

                if ($href === '' || $href === '..' || $href === '.' || !$this->isProgressiveVideoFilename($href)) {
                    continue;
                }

                $files[] = $href;
            }
        }

        return array_values(array_unique($files));
    }

    private function isProgressiveVideoFilename(string $filename): bool
    {
        return preg_match('/\.(?:mkv|mp4|m4v|avi|webm|mov|ts)$/i', $filename) === 1;
    }

    private function downloadTargetExists(string $pathOrUrl): bool
    {
        if (preg_match('#^https?://#i', $pathOrUrl) === 1) {
            if (!$this->verifyRemoteDownloadTargets) {
                return true;
            }

            if (!$this->consumeRemoteHeadCheckBudget()) {
                return false;
            }

            return $this->remoteMediaFileExists($pathOrUrl);
        }

        return is_file($pathOrUrl);
    }

    private function remoteMediaFileExists(string $url): bool
    {
        $attempts = [$url];

        if (preg_match('#^http://#i', $url) === 1) {
            $attempts[] = preg_replace('#^http://#i', 'https://', $url);
        }

        $attempts = array_values(array_unique(array_filter($attempts)));

        foreach ($attempts as $candidate) {
            $encoded = $this->encodeHttpUrlPathSegments($candidate);

            try {
                $head = Http::connectTimeout(2)
                    ->timeout(4)
                    ->withOptions([
                        'verify' => (bool) config('cinema.remote_media_ssl_verify', true),
                        'allow_redirects' => [
                            'max' => 10,
                            'strict' => false,
                            'referer' => true,
                            'protocols' => ['http', 'https'],
                        ],
                    ])
                    ->withHeaders([
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
                        'Accept' => '*/*',
                        'Accept-Language' => 'en-US,en;q=0.9',
                    ])
                    ->head($encoded);

                if ($head->successful() && $this->remoteResponseLooksLikeMediaFile($head->header('Content-Type'), $head->header('Content-Length'))) {
                    return true;
                }

                if (in_array($head->status(), [405, 501], true)) {
                    $get = Http::connectTimeout(2)
                        ->timeout(4)
                        ->withOptions([
                            'verify' => (bool) config('cinema.remote_media_ssl_verify', true),
                            'allow_redirects' => [
                                'max' => 10,
                                'strict' => false,
                                'referer' => true,
                                'protocols' => ['http', 'https'],
                            ],
                        ])
                        ->withHeaders([
                            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
                            'Accept' => '*/*',
                            'Range' => 'bytes=0-0',
                        ])
                        ->get($encoded);

                    if (
                        ($get->successful() || $get->status() === 206)
                        && $this->remoteResponseLooksLikeMediaFile($get->header('Content-Type'), $get->header('Content-Length'))
                    ) {
                        return true;
                    }
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return false;
    }

    private function remoteResponseLooksLikeMediaFile(mixed $contentType, mixed $contentLength): bool
    {
        $type = strtolower(trim((string) $contentType));
        $length = is_numeric($contentLength) ? (int) $contentLength : null;

        if ($type !== '' && (
            str_contains($type, 'text/html')
            || str_contains($type, 'application/json')
            || str_contains($type, 'text/plain')
        )) {
            return false;
        }

        if ($length !== null && $length > 0 && $length < 4096) {
            return false;
        }

        if ($type !== '' && !preg_match('#^(video/|application/octet-stream)#', $type)) {
            return false;
        }

        if ($type === '' && $length !== null && $length < 4096) {
            return false;
        }

        return true;
    }

    /**
     * TV browsers require CODECS, FRAME-RATE, and RESOLUTION on variant lines.
     *
     * @return array<int, string>
     */
    public function validateVariantAttributes(string $manifestContent): array
    {
        $warnings = [];
        $lines = preg_split('/\R/', $manifestContent) ?: [];

        foreach ($lines as $index => $line) {
            $trimmed = trim($line);
            if (! str_starts_with($trimmed, '#EXT-X-STREAM-INF')) {
                continue;
            }

            $lineNumber = $index + 1;

            if (! str_contains($line, 'CODECS=')) {
                $warnings[] = "Line {$lineNumber}: Missing CODECS= attribute — TV browsers cannot determine codec support without this. Add CODECS=\"avc1.640020,mp4a.40.2\" (or correct codec string).";
            }

            if (! str_contains($line, 'FRAME-RATE=')) {
                $warnings[] = "Line {$lineNumber}: Missing FRAME-RATE= attribute — Android TV and Fire TV filter variants without frame rate. Add FRAME-RATE=25 or FRAME-RATE=30 as appropriate.";
            }

            if (! str_contains($line, 'RESOLUTION=')) {
                $warnings[] = "Line {$lineNumber}: Missing RESOLUTION= — Roku filters variants without resolution declaration.";
            }
        }

        return $warnings;
    }
}
