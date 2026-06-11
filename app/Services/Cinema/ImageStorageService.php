<?php

namespace App\Services\Cinema;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RuntimeException;

class ImageStorageService
{
    public function storageRoot(): string
    {
        return $this->normalizeAbsolutePath((string) config('cinema_images.storage_root'));
    }

    public function publicPrefix(): string
    {
        $prefix = (string) config('cinema_images.public_prefix', '/images');

        return '/' . trim($prefix, '/');
    }

    public function resolveFolderType(string $imageType, ?string $entityType = null): string
    {
        if (in_array($imageType, ['collection_poster', 'collection_backdrop', 'collection_cover', 'collection_banner'], true)
            || $entityType === 'collection') {
            return 'collections';
        }

        if (in_array($imageType, ['genre_icon', 'genre_artwork'], true) || $entityType === 'genre') {
            return 'genres';
        }

        return 'year';
    }

    /**
     * @return array{
     *   folder_type: string,
     *   year: ?int,
     *   relative_public_dir: string,
     *   absolute_dir: string,
     *   filename: string,
     *   extension: string,
     *   public_path: string,
     *   physical_path: string
     * }
     */
    public function buildStoragePlan(string $folderType, string $extension): array
    {
        $extension = $this->sanitizeExtension($extension);
        $filename = $this->generateUniqueFilename($extension);
        $year = $folderType === 'year' ? (int) now()->format('Y') : null;

        $relativePublicDir = match ($folderType) {
            'collections' => $this->publicPrefix() . '/' . (string) config('cinema_images.collection_folder', 'collections'),
            'genres' => $this->publicPrefix() . '/' . (string) config('cinema_images.genre_folder', 'genres'),
            default => $this->publicPrefix() . '/' . (string) $year,
        };

        $publicPath = rtrim($relativePublicDir, '/') . '/' . $filename;
        $physicalPath = $this->toPhysicalPath($publicPath);
        $absoluteDir = dirname($physicalPath);

        return [
            'folder_type' => $folderType,
            'year' => $year,
            'relative_public_dir' => $relativePublicDir,
            'absolute_dir' => $absoluteDir,
            'filename' => $filename,
            'extension' => $extension,
            'public_path' => $publicPath,
            'physical_path' => $physicalPath,
        ];
    }

    public function toPhysicalPath(string $publicPath): string
    {
        $normalizedPublic = $this->normalizePublicPath($publicPath);
        $prefix = $this->publicPrefix();

        if (! str_starts_with($normalizedPublic, $prefix . '/') && $normalizedPublic !== $prefix) {
            throw new InvalidArgumentException('Public image path must stay under the configured image prefix.');
        }

        $relative = ltrim(substr($normalizedPublic, strlen($prefix)), '/');

        return $this->normalizeAbsolutePath($this->storageRoot() . $prefix . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    }

    public function normalizePublicPath(string $path): string
    {
        $trimmed = trim($path);

        if ($trimmed === '') {
            throw new InvalidArgumentException('Public image path is required.');
        }

        if (preg_match('#^https?://#i', $trimmed) === 1) {
            $parsed = parse_url($trimmed, PHP_URL_PATH);

            if (! is_string($parsed) || $parsed === '') {
                throw new InvalidArgumentException('Unable to parse public image URL path.');
            }

            $trimmed = $parsed;
        }

        $normalized = '/' . trim(str_replace('\\', '/', $trimmed), '/');
        $segments = array_values(array_filter(explode('/', $normalized), fn (string $segment) => $segment !== '' && $segment !== '.' && $segment !== '..'));

        if ($segments === []) {
            throw new InvalidArgumentException('Public image path is invalid.');
        }

        return '/' . implode('/', $segments);
    }

    public function saveBinary(string $physicalPath, string $binary): void
    {
        if (str_contains(str_replace('\\', '/', $physicalPath), '..')) {
            throw new InvalidArgumentException('Image path escapes configured storage root.');
        }

        $normalized = $this->normalizeAbsolutePath($physicalPath);
        $this->assertPathInsideStorageRoot($normalized);

        File::ensureDirectoryExists(dirname($normalized));

        if (File::put($normalized, $binary) === false) {
            throw new RuntimeException('Failed to save image file.');
        }
    }

    public function generateUniqueFilename(string $extension): string
    {
        $extension = $this->sanitizeExtension($extension);
        $milliseconds = (int) floor(microtime(true) * 1000);
        $random = random_int(100000000, 999999999);

        return $milliseconds . '-' . $random . '.' . $extension;
    }

    public function sanitizeExtension(string $extension): string
    {
        $extension = strtolower(trim($extension, '. '));

        if ($extension === 'jpeg') {
            return 'jpg';
        }

        if (! in_array($extension, ['jpg', 'png', 'webp', 'svg'], true)) {
            throw new InvalidArgumentException('Unsupported image extension.');
        }

        return $extension;
    }

    public function extensionFromMimeType(string $mimeType, bool $allowSvg = false): string
    {
        return match (strtolower(trim($mimeType))) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/svg+xml' => $allowSvg ? 'svg' : throw new InvalidArgumentException('SVG is only allowed for genre artwork.'),
            default => throw new InvalidArgumentException('Unsupported image mime type: ' . $mimeType),
        };
    }

    private function assertPathInsideStorageRoot(string $absolutePath): void
    {
        $normalizedPath = strtolower($this->normalizeAbsolutePath($absolutePath));
        $normalizedRoot = strtolower(rtrim($this->storageRoot(), DIRECTORY_SEPARATOR)) . DIRECTORY_SEPARATOR;

        if (! str_starts_with($normalizedPath, $normalizedRoot)) {
            throw new InvalidArgumentException('Image path escapes configured storage root.');
        }
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
}
