<?php

namespace App\Services\Cinema;

use InvalidArgumentException;

class ImageSourceNormalizer
{
    public function __construct(
        private readonly ImageVariantUrlBuilder $variantUrlBuilder,
    ) {
    }

    public function normalize(?string $source): ?string
    {
        if (! filled($source)) {
            return null;
        }

        $trimmed = trim((string) $source);

        if ($trimmed === '') {
            return null;
        }

        if ($this->variantUrlBuilder->isManagedPublicUrl($trimmed)) {
            return $trimmed;
        }

        if (preg_match('#^https?://#i', $trimmed) === 1) {
            return $this->normalizeHttpUrl($trimmed);
        }

        if (str_starts_with($trimmed, '//')) {
            return $this->normalizeHttpUrl('https:' . $trimmed);
        }

        if (str_starts_with($trimmed, '/')) {
            return $this->normalizeTmdbRelativePath($trimmed);
        }

        throw new InvalidArgumentException('Image source must be an http(s) URL or a TMDB image path.');
    }

    public function sourceHash(string $normalizedSource): string
    {
        return hash('sha256', strtolower($normalizedSource));
    }

    public function isIngestible(?string $source): bool
    {
        if (! filled($source)) {
            return false;
        }

        $trimmed = trim((string) $source);

        if ($trimmed === '') {
            return false;
        }

        if ($this->variantUrlBuilder->isManagedPublicUrl($trimmed)) {
            return false;
        }

        if (str_starts_with($trimmed, '/media/')) {
            return false;
        }

        if (str_contains($trimmed, '/placeholders/')) {
            return false;
        }

        try {
            $normalized = $this->normalize($source);

            return filled($normalized) && ! $this->variantUrlBuilder->isManagedPublicUrl($normalized);
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    private function normalizeHttpUrl(string $url): string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('Invalid image URL.');
        }

        $scheme = strtolower((string) $parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('Only http and https image URLs are allowed.');
        }

        return $url;
    }

    private function normalizeTmdbRelativePath(string $path): string
    {
        $base = rtrim((string) config('cinema_images.tmdb_image_base_url'), '/');
        $normalizedPath = '/' . trim($path, '/');

        if (str_starts_with($normalizedPath, '/t/p/')) {
            return $base . substr($normalizedPath, 4);
        }

        return $base . '/original' . $normalizedPath;
    }
}
