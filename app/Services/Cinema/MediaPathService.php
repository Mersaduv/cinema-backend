<?php

namespace App\Services\Cinema;

use InvalidArgumentException;

/**
 * Remote media URL helpers.
 *
 * Public URLs stay as cinema.af for browsers. The API host often cannot open
 * that public name (hairpin), so outbound package reads use a local content2
 * root or an internal fetch base when configured.
 */
class MediaPathService
{
    public function movieRelativeDirectory(string $slug): string
    {
        return trim((string) config('cinema.movies_directory', 'movies'), '/') . '/' . $slug;
    }

    public function hlsRelativeDirectory(string $slug): string
    {
        return $this->movieRelativeDirectory($slug) . '/' . trim((string) config('cinema.hls_directory', 'hls'), '/');
    }

    public function masterPlaylistRelativePath(string $slug): string
    {
        return $this->hlsRelativeDirectory($slug) . '/master.m3u8';
    }

    public function isRemoteMediaReference(string $path): bool
    {
        $trimmed = trim($path, " \t\n\r\0\x0B\"'");

        if ($trimmed === '') {
            return false;
        }

        if (preg_match('#^https?://#i', $trimmed) === 1) {
            return true;
        }

        if (str_starts_with($trimmed, '//')) {
            return true;
        }

        if ($this->isAbsolutePath($trimmed)) {
            return false;
        }

        return preg_match(
            '#^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}(?:[:/]|$)#i',
            $trimmed,
        ) === 1;
    }

    /**
     * Hostname-style CDN paths (e.g. cinema.af/content2/...) must use HTTP.
     */
    public function coerceRemoteMediaUrl(string $path): string
    {
        $trimmed = trim($path, " \t\n\r\0\x0B\"'");

        if ($trimmed === '' || ! $this->isRemoteMediaReference($trimmed)) {
            return $trimmed;
        }

        if (preg_match('#^https?://#i', $trimmed) === 1) {
            return $trimmed;
        }

        if (str_starts_with($trimmed, '//')) {
            return 'http:' . $trimmed;
        }

        return 'http://' . ltrim($trimmed, '/');
    }

    public function assertRemoteMediaUrl(string $path, string $field = 'path'): string
    {
        $trimmed = trim($path);

        if ($trimmed === '') {
            throw new InvalidArgumentException("A valid {$field} is required.");
        }

        if ($this->isAbsolutePath($trimmed)) {
            throw new InvalidArgumentException(
                "Local disk {$field} values are not supported. Use a full HTTP(S) URL.",
            );
        }

        if (! $this->isRemoteMediaReference($trimmed)) {
            throw new InvalidArgumentException(
                "{$field} must be a full HTTP(S) URL (e.g. http://cinema.af/content2/...).",
            );
        }

        return $this->coerceRemoteMediaUrl($trimmed);
    }

    /**
     * Map a public /content2/... URL onto the local package tree when configured.
     */
    public function resolveLocalContent2Path(string $url): ?string
    {
        $root = trim((string) config('cinema.content2_storage_root', ''));

        if ($root === '') {
            return null;
        }

        $coerced = $this->coerceRemoteMediaUrl($url);
        $path = parse_url($coerced, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return null;
        }

        $path = rawurldecode($path);

        if (! str_starts_with($path, '/content2/') && $path !== '/content2') {
            return null;
        }

        $relative = ltrim(substr($path, strlen('/content2')), '/');
        $relative = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
        $absolute = rtrim($root, "\\/") . DIRECTORY_SEPARATOR . $relative;
        $normalizedRoot = $this->normalizeAbsolutePath($root);
        $normalizedAbsolute = $this->normalizeAbsolutePath($absolute);

        if (! str_starts_with($normalizedAbsolute, $normalizedRoot)) {
            return null;
        }

        return $normalizedAbsolute;
    }

    /**
     * @return array{url: string, headers: array<string, string>}
     */
    public function outboundMediaRequest(string $url): array
    {
        $coerced = $this->coerceRemoteMediaUrl($url);
        $headers = [];
        $fetchBase = rtrim(trim((string) config('cinema.remote_media_fetch_base_url', '')), '/');

        if ($fetchBase === '' || preg_match('#^https?://#i', $coerced) !== 1) {
            return ['url' => $coerced, 'headers' => $headers];
        }

        $parts = parse_url($coerced);

        if ($parts === false || empty($parts['host'])) {
            return ['url' => $coerced, 'headers' => $headers];
        }

        $host = strtolower((string) $parts['host']);
        $rewriteHosts = array_values(array_filter(array_map(
            static fn ($value) => strtolower(trim((string) $value)),
            (array) config('cinema.remote_media_fetch_hosts', ['cinema.af', 'www.cinema.af']),
        )));

        if (! in_array($host, $rewriteHosts, true)) {
            return ['url' => $coerced, 'headers' => $headers];
        }

        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        $headers['Host'] = $host . (isset($parts['port']) ? ':' . $parts['port'] : '');

        return [
            'url' => $fetchBase . $path . $query,
            'headers' => $headers,
        ];
    }

    private function isAbsolutePath(string $path): bool
    {
        return preg_match('/^[A-Za-z]:(?:[\\\\\\/]|$)/', $path) === 1 || str_starts_with($path, '\\\\');
    }

    private function normalizeAbsolutePath(string $path): string
    {
        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);

        return strtolower(rtrim($normalized, DIRECTORY_SEPARATOR));
    }
}
