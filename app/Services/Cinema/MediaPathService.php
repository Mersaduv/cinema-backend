<?php

namespace App\Services\Cinema;

use InvalidArgumentException;

/**
 * Remote media URL helpers. Local disk media roots (e.g. D:\media) are not used.
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

    private function isAbsolutePath(string $path): bool
    {
        return preg_match('/^[A-Za-z]:(?:[\\\\\\/]|$)/', $path) === 1 || str_starts_with($path, '\\\\');
    }
}
