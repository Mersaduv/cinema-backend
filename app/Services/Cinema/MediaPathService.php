<?php

namespace App\Services\Cinema;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;

class MediaPathService
{
    public function mediaRoot(): string
    {
        return $this->normalizeAbsolutePath((string) config('cinema.media_root'));
    }

    public function mediaPublicBaseUrl(): string
    {
        return rtrim((string) config('cinema.media_public_base_url'), '/');
    }

    public function movieRelativeDirectory(string $slug): string
    {
        return trim((string) config('cinema.movies_directory', 'movies'), '/') . '/' . $slug;
    }

    public function movieAbsoluteDirectory(string $slug): string
    {
        return $this->toAbsolutePath($this->movieRelativeDirectory($slug));
    }

    public function hlsRelativeDirectory(string $slug): string
    {
        return $this->movieRelativeDirectory($slug) . '/' . trim((string) config('cinema.hls_directory', 'hls'), '/');
    }

    public function assetsRelativeDirectory(string $slug): string
    {
        return $this->movieRelativeDirectory($slug) . '/' . trim((string) config('cinema.assets_directory', 'assets'), '/');
    }

    public function masterPlaylistRelativePath(string $slug): string
    {
        return $this->hlsRelativeDirectory($slug) . '/master.m3u8';
    }

    public function toAbsolutePath(string $relativePath): string
    {
        return $this->normalizeAbsolutePath($this->mediaRoot() . DIRECTORY_SEPARATOR . $this->normalizeRelativePath($relativePath));
    }

    public function toPublicUrl(?string $relativePath): ?string
    {
        if (!$relativePath) {
            return null;
        }

        return $this->mediaPublicBaseUrl() . '/' . ltrim($this->normalizeRelativePath($relativePath), '/');
    }

    public function normalizeInputPath(string $path): string
    {
        $path = trim($path, " \t\n\r\0\x0B\"'");

        if ($path === '') {
            throw new InvalidArgumentException('A valid media path is required.');
        }

        if ($this->isAbsolutePath($path)) {
            return $this->normalizeAbsolutePath($path);
        }

        return $this->normalizeAbsolutePath($this->mediaRoot() . DIRECTORY_SEPARATOR . $path);
    }

    public function ensureReadableFile(string $path): string
    {
        $normalizedPath = $this->normalizeInputPath($path);

        if (!is_file($normalizedPath) || !is_readable($normalizedPath)) {
            throw new InvalidArgumentException("Media file is not readable: {$normalizedPath}");
        }

        return $normalizedPath;
    }

    /**
     * @return array<string, string>
     */
    public function ensureMovieDirectories(string $slug): array
    {
        $movieDirectory = $this->movieAbsoluteDirectory($slug);
        $hlsDirectory = $this->toAbsolutePath($this->hlsRelativeDirectory($slug));
        $assetsDirectory = $this->toAbsolutePath($this->assetsRelativeDirectory($slug));

        File::ensureDirectoryExists($movieDirectory);
        File::ensureDirectoryExists($hlsDirectory);
        File::ensureDirectoryExists($assetsDirectory);

        return [
            'movie' => $movieDirectory,
            'hls' => $hlsDirectory,
            'assets' => $assetsDirectory,
        ];
    }

    public function isPathInside(string $path, string $directory): bool
    {
        $normalizedPath = strtolower($this->normalizeAbsolutePath($path));
        $normalizedDirectory = strtolower($this->normalizeAbsolutePath($directory));

        return $normalizedPath === $normalizedDirectory
            || str_starts_with($normalizedPath, $normalizedDirectory . DIRECTORY_SEPARATOR);
    }

    public function inferPublicUrlFromAbsolutePath(?string $absolutePath): ?string
    {
        if (!$absolutePath) {
            return null;
        }

        $normalizedPath = $this->normalizeInputPath($absolutePath);

        if (!$this->isPathInside($normalizedPath, $this->mediaRoot())) {
            return null;
        }

        $relativePath = ltrim(str_replace($this->mediaRoot(), '', $normalizedPath), DIRECTORY_SEPARATOR);

        return $this->toPublicUrl(str_replace(DIRECTORY_SEPARATOR, '/', $relativePath));
    }

    private function normalizeRelativePath(string $path): string
    {
        return ltrim(str_replace('\\', '/', trim($path)), '/');
    }

    private function normalizeAbsolutePath(string $path): string
    {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim($path));
        $pattern = '#' . preg_quote(DIRECTORY_SEPARATOR, '#') . '+#';

        return rtrim((string) preg_replace($pattern, DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
    }

    private function isAbsolutePath(string $path): bool
    {
        return preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1 || str_starts_with($path, '\\\\');
    }
}
