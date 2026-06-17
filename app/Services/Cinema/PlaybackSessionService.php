<?php

namespace App\Services\Cinema;

use App\Models\Movie;
use App\Models\Series;
use App\Models\SeriesEpisode;
use Illuminate\Support\Carbon;

class PlaybackSessionService
{
    public function __construct(
        private readonly HlsPackageInsightService $hlsPackageInsightService,
    ) {
    }

    /**
     * @return array{
     *   hls_url: ?string,
     *   dash_url: ?string,
     *   progressive_url: ?string,
     *   drm: ?array{scheme: string, license_url: string},
     *   expires_at: string
     * }
     */
    public function issueForMovie(Movie $movie): array
    {
        $rawHls = (string) data_get($movie->metadata, 'playback.hls_url', '');
        $canonical = $this->hlsPackageInsightService->normalizeHlsPackageMasterUrl($rawHls);
        $dashUrl = $this->resolveDashUrl($movie->metadata ?? [], $canonical);
        $progressive = $this->resolveProgressiveUrl($movie->metadata ?? []);

        return $this->buildSession([
            'hls' => $canonical,
            'dash' => $dashUrl,
            'progressive' => $progressive,
            'drm' => $this->resolveDrmConfig($movie->drm_scheme ?? null, $movie->drm_license_url ?? null)
                ?? data_get($movie->metadata, 'playback.drm'),
        ]);
    }

    /**
     * @return array{
     *   hls_url: ?string,
     *   dash_url: ?string,
     *   progressive_url: ?string,
     *   drm: ?array{scheme: string, license_url: string},
     *   expires_at: string
     * }
     */
    public function issueForEpisode(SeriesEpisode $episode, Series $series): array
    {
        $hls = trim((string) ($episode->hls_url ?: ''));
        $metadata = array_merge($series->metadata ?? [], $episode->metadata ?? []);
        $dash = filled($episode->dash_url) && (bool) ($episode->dash_verified ?? false)
            ? trim((string) $episode->dash_url)
            : $this->resolveDashUrl($metadata, $hls);

        return $this->buildSession([
            'hls' => $hls,
            'dash' => $dash,
            'progressive' => $this->resolveProgressiveUrl($metadata),
            'drm' => $this->resolveDrmConfig($series->drm_scheme ?? null, $series->drm_license_url ?? null)
                ?? data_get($metadata, 'playback.drm'),
        ]);
    }

    public function verifyToken(?string $url): bool
    {
        $trimmed = trim((string) $url);

        if ($trimmed === '') {
            return false;
        }

        $secret = (string) config('cinema.playback_signing_secret', '');

        if ($secret === '') {
            return true;
        }

        $parts = parse_url($trimmed);

        if (! is_array($parts)) {
            return false;
        }

        parse_str((string) ($parts['query'] ?? ''), $query);

        $expires = isset($query['cinema_exp']) ? (int) $query['cinema_exp'] : null;
        $signature = isset($query['cinema_sig']) ? (string) $query['cinema_sig'] : null;

        if ($expires === null || $signature === null) {
            return false;
        }

        if ($expires < time()) {
            return false;
        }

        $baseUrl = $this->stripSigningParams($trimmed);
        $expected = hash_hmac('sha256', $baseUrl.'|'.$expires, $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * @param  array{hls?: ?string, dash?: ?string, progressive?: ?string, drm?: mixed}  $urls
     * @return array{
     *   hls_url: ?string,
     *   dash_url: ?string,
     *   progressive_url: ?string,
     *   drm: ?array{scheme: string, license_url: string},
     *   expires_at: string
     * }
     */
    private function buildSession(array $urls): array
    {
        $ttl = max(60, (int) config('cinema.playback_session_ttl_seconds', 3600));
        $expires = now()->addSeconds($ttl);

        $drm = null;
        $rawDrm = $urls['drm'] ?? null;
        if (is_array($rawDrm) && filled($rawDrm['license_url'] ?? null)) {
            $drm = [
                'scheme' => strtolower((string) ($rawDrm['scheme'] ?? 'widevine')),
                'license_url' => (string) $rawDrm['license_url'],
            ];
        }

        return [
            'hls_url' => $this->signUrl($urls['hls'] ?? null, $expires),
            'dash_url' => $this->signUrl($urls['dash'] ?? null, $expires),
            'progressive_url' => $this->signUrl($urls['progressive'] ?? null, $expires),
            'drm' => $drm,
            'expires_at' => $expires->toIso8601String(),
        ];
    }

    /**
     * @return array{scheme: string, license_url: string}|null
     */
    private function resolveDrmConfig(?string $scheme, ?string $licenseUrl): ?array
    {
        $scheme = strtolower(trim((string) ($scheme ?? 'none')));

        if ($scheme === 'none' || ! filled($licenseUrl)) {
            return null;
        }

        return [
            'scheme' => $scheme,
            'license_url' => trim((string) $licenseUrl),
        ];
    }

    private function stripSigningParams(string $url): string
    {
        $parts = parse_url($url);

        if (! is_array($parts)) {
            return $url;
        }

        parse_str((string) ($parts['query'] ?? ''), $query);
        unset($query['cinema_exp'], $query['cinema_sig']);

        $scheme = isset($parts['scheme']) ? $parts['scheme'].'://' : '';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = $parts['path'] ?? '';
        $queryString = $query !== [] ? '?'.http_build_query($query) : '';

        return $scheme.$host.$port.$path.$queryString;
    }

    private function signUrl(?string $url, Carbon $expires): ?string
    {
        $trimmed = trim((string) $url);
        if ($trimmed === '') {
            return null;
        }

        $secret = (string) config('cinema.playback_signing_secret', '');
        if ($secret === '') {
            return $trimmed;
        }

        $signature = hash_hmac('sha256', $trimmed.'|'.$expires->getTimestamp(), $secret);
        $separator = str_contains($trimmed, '?') ? '&' : '?';

        return $trimmed.$separator.'cinema_exp='.$expires->getTimestamp().'&cinema_sig='.$signature;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function resolveDashUrl(array $metadata, ?string $masterHlsUrl): ?string
    {
        $dash = data_get($metadata, 'playback.dash_url')
            ?? data_get($metadata, 'playback.dash_manifest')
            ?? data_get($metadata, 'hls_package.dash_url');

        if (filled($dash)) {
            return trim((string) $dash);
        }

        if (! filled($masterHlsUrl)) {
            return null;
        }

        $base = preg_replace('#/master\.m3u8(\?.*)?$#i', '', (string) $masterHlsUrl);

        return $base ? rtrim((string) $base, '/').'/manifest.mpd' : null;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function resolveProgressiveUrl(array $metadata): ?string
    {
        $url = data_get($metadata, 'playback.progressive_url')
            ?? data_get($metadata, 'playback.preview_mp4');

        return filled($url) ? trim((string) $url) : null;
    }
}
