<?php

namespace App\Services\Cinema;

use Illuminate\Support\Facades\File;
use Illuminate\Http\UploadedFile;
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

    public function audioRelativeDirectory(string $slug): string
    {
        return $this->movieRelativeDirectory($slug) . '/audio';
    }

    public function subtitlesRelativeDirectory(string $slug): string
    {
        return $this->movieRelativeDirectory($slug) . '/subtitles';
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

        return $this->mediaPublicBaseUrl() . '/' . $this->encodeRelativePath($relativePath);
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
     * Hostname-style CDN paths (e.g. cinema.af/content2/...) must use HTTP, not media_root on disk.
     */
    public function coerceRemoteMediaUrl(string $path): string
    {
        $trimmed = trim($path, " \t\n\r\0\x0B\"'");

        if ($trimmed === '' || !$this->isRemoteMediaReference($trimmed)) {
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

    public function normalizeInputPath(string $path): string
    {
        $path = trim($path, " \t\n\r\0\x0B\"'");

        if ($path === '') {
            throw new InvalidArgumentException('A valid media path is required.');
        }

        if ($this->isRemoteMediaReference($path)) {
            throw new InvalidArgumentException(
                'Remote media paths must be full HTTP(S) URLs (e.g. http://cinema.af/content2/...), not local disk paths.',
            );
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
        $audioDirectory = $this->toAbsolutePath($this->audioRelativeDirectory($slug));
        $subtitlesDirectory = $this->toAbsolutePath($this->subtitlesRelativeDirectory($slug));

        File::ensureDirectoryExists($movieDirectory);
        File::ensureDirectoryExists($hlsDirectory);
        File::ensureDirectoryExists($assetsDirectory);
        File::ensureDirectoryExists($audioDirectory);
        File::ensureDirectoryExists($subtitlesDirectory);

        return [
            'movie' => $movieDirectory,
            'hls' => $hlsDirectory,
            'assets' => $assetsDirectory,
            'audio' => $audioDirectory,
            'subtitles' => $subtitlesDirectory,
        ];
    }

    public function storeMoviePoster(string $path, string $slug): string
    {
        return $this->storeManagedMovieFile($path, $slug, 'assets', 'poster');
    }

    public function storeMovieTrailer(string $path, string $slug): string
    {
        return $this->storeManagedMovieFile($path, $slug, 'assets', 'trailer');
    }

    public function storeMovieDubbedSource(string $path, string $slug): string
    {
        return $this->storeManagedMovieFile($path, $slug, 'audio', 'dubbed');
    }

    public function storeMovieSubtitle(string $path, string $slug): string
    {
        return $this->storeManagedMovieFile($path, $slug, 'subtitles', 'subtitle');
    }

    public function storeUploadedMovieDubbedSource(UploadedFile $file, string $slug): string
    {
        return $this->storeUploadedManagedMovieFile($file, $slug, 'audio', 'dubbed');
    }

    public function storeUploadedMovieSubtitle(UploadedFile $file, string $slug): string
    {
        return $this->storeUploadedManagedMovieFile($file, $slug, 'subtitles', 'subtitle');
    }

    public function isPathInside(string $path, string $directory): bool
    {
        $normalizedPath = strtolower($this->normalizeAbsolutePath($path));
        $normalizedDirectory = strtolower($this->normalizeAbsolutePath($directory));
        $directoryPrefix = rtrim($normalizedDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return $normalizedPath === $normalizedDirectory
            || str_starts_with($normalizedPath, $directoryPrefix);
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

    /**
     * @return array<int, array{path: string, label: string, is_media_root: bool}>
     */
    public function browseRoots(): array
    {
        $roots = [];
        $seen = [];
        $mediaRoot = $this->mediaRoot();

        $roots[] = [
            'path' => $mediaRoot,
            'label' => 'Media Root - ' . $mediaRoot,
            'is_media_root' => true,
        ];
        $seen[strtolower($mediaRoot)] = true;

        foreach (range('C', 'Z') as $driveLetter) {
            $driveRoot = $driveLetter . ':' . DIRECTORY_SEPARATOR;

            if (!is_dir($driveRoot)) {
                continue;
            }

            $normalized = $this->normalizeAbsolutePath($driveRoot);
            $key = strtolower($normalized);

            if (isset($seen[$key])) {
                continue;
            }

            $roots[] = [
                'path' => $normalized,
                'label' => 'Drive ' . $driveLetter . ':',
                'is_media_root' => false,
            ];
            $seen[$key] = true;
        }

        return $roots;
    }

    /**
     * @return array{current_path: string, parent_path: ?string, directories: array<int, array{name: string, path: string}>, files: array<int, array{name: string, path: string, extension: string, size_bytes: int, size_label: string, last_modified_at: ?string, is_supported: bool, public_url: ?string}>}
     */
    public function browseDirectory(?string $path, string $kind): array
    {
        $currentPath = $path
            ? $this->normalizeInputPath($path)
            : $this->browseRoots()[0]['path'];

        $this->ensureBrowsablePath($currentPath);

        if (!is_dir($currentPath) || !is_readable($currentPath)) {
            throw new InvalidArgumentException("Directory is not readable: {$currentPath}");
        }

        $allowedExtensions = $this->allowedExtensionsForKind($kind);
        $directories = collect(File::directories($currentPath))
            ->map(fn (string $directory) => [
                'name' => basename($directory),
                'path' => $this->normalizeAbsolutePath($directory),
            ])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        $files = collect(File::files($currentPath))
            ->map(function (\SplFileInfo $file) use ($allowedExtensions) {
                $absolutePath = $this->normalizeAbsolutePath($file->getPathname());
                $extension = strtolower($file->getExtension());
                $lastModified = null;

                try {
                    $mtime = $file->getMTime();
                    $lastModified = $mtime > 0
                        ? now()->setTimestamp($mtime)->toISOString()
                        : null;
                } catch (\RuntimeException) {
                    $lastModified = null;
                }

                return [
                    'name' => $file->getFilename(),
                    'path' => $absolutePath,
                    'extension' => $extension,
                    'size_bytes' => $file->getSize(),
                    'size_label' => $this->formatBytes($file->getSize()),
                    'last_modified_at' => $lastModified,
                    'is_supported' => in_array($extension, $allowedExtensions, true),
                    'public_url' => $this->inferPublicUrlFromAbsolutePath($absolutePath),
                ];
            })
            ->filter(fn (array $file) => $file['is_supported'])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        return [
            'current_path' => $currentPath,
            'parent_path' => $this->parentBrowsablePath($currentPath),
            'directories' => $directories,
            'files' => $files,
        ];
    }

    /**
     * @return array<int, string>
     */
    public function allowedExtensionsForKind(string $kind): array
    {
        return match ($kind) {
            'image' => ['jpg', 'jpeg', 'png', 'webp', 'gif'],
            'subtitle' => [
                'srt', 'vtt', 'ass', 'ssa', 'sub', 'idx', 'sup', 'smi', 'sami',
                'ttml', 'dfxp', 'sbv', 'txt', 'rt', 'usf',
            ],
            'dubbed' => [
                'mp4', 'mkv', 'mov', 'avi', 'm4v', 'ts', 'm2ts', 'mts', 'mpg', 'mpeg', 'wmv', 'webm', 'flv', '3gp',
                'mp3', 'aac', 'm4a', 'wav', 'flac', 'mka', 'ogg', 'oga', 'opus', 'ac3', 'eac3', 'dts', 'mp2', 'wma', 'weba',
            ],
            default => ['mp4', 'mkv', 'mov', 'avi', 'm4v', 'ts', 'm2ts', 'mts', 'mpg', 'mpeg', 'wmv', 'webm', 'flv', '3gp'],
        };
    }

    private function ensureBrowsablePath(string $path): void
    {
        $normalizedPath = strtolower($this->normalizeAbsolutePath($path));

        foreach ($this->browseRoots() as $root) {
            $normalizedRoot = strtolower($this->normalizeAbsolutePath($root['path']));
            $rootPrefix = rtrim($normalizedRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

            if (
                $normalizedPath === $normalizedRoot
                || str_starts_with($normalizedPath, $rootPrefix)
            ) {
                return;
            }
        }

        throw new InvalidArgumentException("Path is outside the allowed browse roots: {$path}");
    }

    private function parentBrowsablePath(string $path): ?string
    {
        $normalizedPath = $this->normalizeAbsolutePath($path);

        foreach ($this->browseRoots() as $root) {
            $normalizedRoot = $this->normalizeAbsolutePath($root['path']);

            if (strtolower($normalizedPath) === strtolower($normalizedRoot)) {
                return null;
            }
        }

        $parent = dirname($normalizedPath);

        if ($parent === '.' || $parent === $normalizedPath) {
            return null;
        }

        return $this->normalizeAbsolutePath($parent);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        $units = ['KB', 'MB', 'GB', 'TB'];
        $size = $bytes / 1024;
        $unitIndex = 0;

        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }

        return number_format($size, $size >= 10 ? 0 : 1) . ' ' . $units[$unitIndex];
    }

    private function normalizeRelativePath(string $path): string
    {
        return ltrim(str_replace('\\', '/', trim($path)), '/');
    }

    private function encodeRelativePath(string $path): string
    {
        $normalizedPath = $this->normalizeRelativePath($path);
        $segments = array_filter(explode('/', $normalizedPath), fn (string $segment) => $segment !== '');

        return implode('/', array_map('rawurlencode', $segments));
    }

    private function normalizeAbsolutePath(string $path): string
    {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim($path));
        $pattern = '#' . preg_quote(DIRECTORY_SEPARATOR, '#') . '+#';
        $normalized = (string) preg_replace($pattern, DIRECTORY_SEPARATOR, $path);

        if (preg_match('/^[A-Za-z]:$/', $normalized) === 1) {
            return strtoupper($normalized[0]) . ':' . DIRECTORY_SEPARATOR;
        }

        if (preg_match('/^[A-Za-z]:\\\\$/', $normalized) === 1) {
            return strtoupper($normalized[0]) . ':' . DIRECTORY_SEPARATOR;
        }

        return rtrim($normalized, DIRECTORY_SEPARATOR);
    }

    private function isAbsolutePath(string $path): bool
    {
        return preg_match('/^[A-Za-z]:(?:[\\\\\\/]|$)/', $path) === 1 || str_starts_with($path, '\\\\');
    }

    public function storeManagedMovieFile(string $path, string $slug, string $bucket, string $baseName): string
    {
        $sourcePath = $this->ensureReadableFile($path);
        $directories = $this->ensureMovieDirectories($slug);
        $destinationDirectory = match ($bucket) {
            'assets' => $directories['assets'],
            'audio' => $directories['audio'],
            'subtitles' => $directories['subtitles'],
            default => $directories['movie'],
        };

        $extension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION) ?: 'bin');
        $destinationPath = $destinationDirectory . DIRECTORY_SEPARATOR . $baseName . '.' . $extension;

        $this->deleteSiblingManagedFiles($destinationDirectory, $baseName, $extension);

        if (strtolower($sourcePath) !== strtolower($destinationPath)) {
            File::copy($sourcePath, $destinationPath);
        }

        if (!is_file($destinationPath) || !is_readable($destinationPath)) {
            throw new InvalidArgumentException("Managed media file could not be prepared: {$destinationPath}");
        }

        return $this->ensureReadableFile($destinationPath);
    }

    public function storeUploadedManagedMovieFile(UploadedFile $file, string $slug, string $bucket, string $baseName): string
    {
        if (!$file->isValid()) {
            throw new InvalidArgumentException("Uploaded {$baseName} file is invalid.");
        }

        $directories = $this->ensureMovieDirectories($slug);
        $destinationDirectory = match ($bucket) {
            'assets' => $directories['assets'],
            'audio' => $directories['audio'],
            'subtitles' => $directories['subtitles'],
            default => $directories['movie'],
        };

        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $destinationPath = $destinationDirectory . DIRECTORY_SEPARATOR . $baseName . '.' . $extension;

        $this->deleteSiblingManagedFiles($destinationDirectory, $baseName, $extension);

        $copied = File::copy($file->getRealPath(), $destinationPath);

        if (!$copied || !is_file($destinationPath) || !is_readable($destinationPath)) {
            throw new InvalidArgumentException("Uploaded {$baseName} file could not be copied into the media library.");
        }

        return $this->ensureReadableFile($destinationPath);
    }

    private function deleteSiblingManagedFiles(string $directory, string $baseName, string $keepExtension): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (File::files($directory) as $file) {
            $filename = strtolower($file->getFilenameWithoutExtension());
            $extension = strtolower($file->getExtension());

            if ($filename !== strtolower($baseName)) {
                continue;
            }

            if ($extension === strtolower($keepExtension)) {
                continue;
            }

            File::delete($file->getPathname());
        }
    }
}
