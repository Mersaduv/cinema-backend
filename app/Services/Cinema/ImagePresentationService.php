<?php

namespace App\Services\Cinema;

use App\Models\ImageAsset;
use App\Models\Person;

/**
 * Resolves preferred public image URLs and responsive image objects for API responses.
 */
class ImagePresentationService
{
    /** @var array<string, ImageAsset|null> */
    private array $readyAssetByUrl = [];

    /** @var array<int|string, bool> */
    private array $assetExistsById = [];

    /** @var array<string, bool> */
    private array $usableUrlCache = [];

    public function __construct(
        private readonly ImageVariantUrlBuilder $variantUrlBuilder,
    ) {
    }

    public function resolveMoviePosterUrl(?string $posterPath, array $metadata): ?string
    {
        return $this->resolvePreferredUrl(
            data_get($metadata, 'images.poster.original'),
            data_get($metadata, 'tmdb.poster_url'),
            $this->resolveStoredPathUrl($posterPath),
            data_get($metadata, 'tmdb.poster_source_url'),
        );
    }

    public function resolveMovieBackdropUrl(array $metadata): ?string
    {
        return $this->resolvePreferredUrl(
            data_get($metadata, 'images.backdrop.original'),
            data_get($metadata, 'tmdb.backdrop_url'),
            data_get($metadata, 'tmdb.backdrop_source_url'),
        );
    }

    public function resolveSeriesPosterUrl(?string $posterUrl, array $metadata): ?string
    {
        return $this->resolvePreferredUrl(
            data_get($metadata, 'images.poster.original'),
            $posterUrl,
            data_get($metadata, 'tmdb.poster_url'),
            data_get($metadata, 'tmdb.poster_source_url'),
        );
    }

    public function resolveSeriesBackdropUrl(?string $backdropUrl, array $metadata): ?string
    {
        return $this->resolvePreferredUrl(
            data_get($metadata, 'images.backdrop.original'),
            $backdropUrl,
            data_get($metadata, 'tmdb.backdrop_url'),
            data_get($metadata, 'tmdb.backdrop_source_url'),
        );
    }

    public function resolveCollectionPosterUrl(?string $posterUrl, array $metadata): ?string
    {
        return $this->resolvePreferredUrl(
            data_get($metadata, 'images.poster.original'),
            $posterUrl,
            data_get($metadata, 'tmdb.poster_url'),
        );
    }

    public function resolveCollectionBackdropUrl(?string $backdropUrl, array $metadata): ?string
    {
        return $this->resolvePreferredUrl(
            data_get($metadata, 'images.backdrop.original'),
            $backdropUrl,
            data_get($metadata, 'tmdb.backdrop_url'),
        );
    }

    public function resolvePersonProfileUrl(Person $person): ?string
    {
        $metadata = is_array($person->metadata) ? $person->metadata : [];

        return $this->resolvePreferredUrl(
            data_get($metadata, 'images.profile.original'),
            $person->profile_url,
            $person->getProfileImageUrl(),
        );
    }

    /**
     * @return ?array<string, mixed>
     */
    public function moviePosterImage(array $metadata): ?array
    {
        return $this->resolveImageObject(data_get($metadata, 'images.poster'), data_get($metadata, 'tmdb.poster_url'));
    }

    /**
     * @return ?array<string, mixed>
     */
    public function movieBackdropImage(array $metadata): ?array
    {
        return $this->resolveImageObject(data_get($metadata, 'images.backdrop'), data_get($metadata, 'tmdb.backdrop_url'));
    }

    /**
     * @return ?array<string, mixed>
     */
    public function seriesPosterImage(array $metadata): ?array
    {
        return $this->resolveImageObject(data_get($metadata, 'images.poster'), data_get($metadata, 'tmdb.poster_url'));
    }

    /**
     * @return ?array<string, mixed>
     */
    public function seriesBackdropImage(array $metadata): ?array
    {
        return $this->resolveImageObject(data_get($metadata, 'images.backdrop'), data_get($metadata, 'tmdb.backdrop_url'));
    }

    /**
     * @return ?array<string, mixed>
     */
    public function collectionPosterImage(array $metadata): ?array
    {
        return $this->resolveImageObject(data_get($metadata, 'images.poster'), data_get($metadata, 'tmdb.poster_url'));
    }

    /**
     * @return ?array<string, mixed>
     */
    public function collectionBackdropImage(array $metadata): ?array
    {
        return $this->resolveImageObject(data_get($metadata, 'images.backdrop'), data_get($metadata, 'tmdb.backdrop_url'));
    }

    /**
     * @return ?array<string, mixed>
     */
    public function seasonPosterImage(array $metadata, ?string $posterUrl): ?array
    {
        return $this->resolveImageObject(data_get($metadata, 'images.poster'), $posterUrl);
    }

    /**
     * @return ?array<string, mixed>
     */
    public function episodeStillImage(array $metadata, ?string $stillUrl): ?array
    {
        return $this->resolveImageObject(data_get($metadata, 'images.still'), $stillUrl);
    }

    /**
     * @return ?array<string, mixed>
     */
    public function personProfileImage(Person $person): ?array
    {
        $metadata = is_array($person->metadata) ? $person->metadata : [];

        return $this->resolveImageObject(
            data_get($metadata, 'images.profile'),
            $this->resolvePersonProfileUrl($person),
        );
    }

    /**
     * @param  ?array<string, mixed>  $storedImage
     */
    public function personProfileImageFromStored(?array $storedImage, ?string $fallbackUrl): ?array
    {
        return $this->resolveImageObject($storedImage, $fallbackUrl);
    }

    /**
     * @param  ?array<string, mixed>  $storedImage
     */
    public function resolvePreferredPersonProfileFromStored(?array $storedImage, ?string $fallbackUrl): ?string
    {
        return $this->resolvePreferredUrl(
            data_get($storedImage, 'original'),
            $fallbackUrl,
        );
    }

    /**
     * @param  array<string, mixed>|null  $stored
     * @return array<string, mixed>
     */
    public function enrichListImageFields(?string $url, ?array $stored = null): array
    {
        $image = $this->resolveImageObject($stored, $url);

        return [
            'url' => $this->resolvePreferredUrl(data_get($image, 'original'), $url),
            'blur_url' => data_get($image, 'blur'),
            'srcset' => data_get($image, 'srcset_string'),
            'image' => $image,
        ];
    }

    /**
     * MovieLand-style catalog/home card posters: tiny srcset (≤640w), no full-bleed widths.
     *
     * @param  array<string, mixed>|null  $stored
     * @return array{url: ?string, blur: ?string, srcset_string: ?string, image: ?array<string, mixed>}
     */
    public function catalogCardPoster(?string $url, ?array $stored = null): array
    {
        $original = $this->resolvePreferredUrl(
            data_get($stored, 'original'),
            $url,
        );

        if (! filled($original)) {
            return [
                'url' => null,
                'blur' => null,
                'srcset_string' => null,
                'image' => null,
            ];
        }

        if (! $this->variantUrlBuilder->isManagedPublicUrl((string) $original)) {
            return [
                'url' => (string) $original,
                'blur' => null,
                'srcset_string' => null,
                'image' => null,
            ];
        }

        $built = $this->variantUrlBuilder->buildFromPublicPath(
            (string) $original,
            is_array($stored) ? ($stored['mime_type'] ?? null) : null,
            is_array($stored) && isset($stored['width']) ? (int) $stored['width'] : null,
            is_array($stored) && isset($stored['height']) ? (int) $stored['height'] : null,
            'card',
        );

        return [
            'url' => (string) ($built['original'] ?? $original),
            'blur' => $built['blur'] ?? null,
            'srcset_string' => $built['srcset_string'] ?? null,
            'image' => $built,
        ];
    }

    /**
     * @param  mixed  $stored
     * @return ?array<string, mixed>
     */
    private function resolveImageObject(mixed $stored, ?string $fallbackUrl): ?array
    {
        if (is_array($stored) && filled($stored['original'] ?? null)) {
            $storedOriginal = (string) $stored['original'];

            if (! $this->variantUrlBuilder->isManagedPublicUrl($storedOriginal)) {
                return $stored;
            }

            $asset = $this->findReadyAssetByPublicUrl($storedOriginal);

            if (! $asset) {
                return $stored;
            }

            if ($this->assetFileExists($asset)) {
                return $asset->toImageObject();
            }

            return $stored;
        }

        if (! filled($fallbackUrl) || ! $this->variantUrlBuilder->isManagedPublicUrl($fallbackUrl)) {
            return null;
        }

        $asset = $this->findReadyAssetByPublicUrl((string) $fallbackUrl);

        if ($asset && $this->assetFileExists($asset)) {
            return $asset->toImageObject();
        }

        return null;
    }

    private function resolvePreferredUrl(mixed ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (filled($candidate) && $this->isUsablePreferredUrl((string) $candidate)) {
                return (string) $candidate;
            }
        }

        return null;
    }

    private function isUsablePreferredUrl(string $url): bool
    {
        if (array_key_exists($url, $this->usableUrlCache)) {
            return $this->usableUrlCache[$url];
        }

        if (! $this->variantUrlBuilder->isManagedPublicUrl($url)) {
            return $this->usableUrlCache[$url] = true;
        }

        $asset = $this->findReadyAssetByPublicUrl($url);

        if ($asset) {
            return $this->usableUrlCache[$url] = $this->assetFileExists($asset);
        }

        try {
            $path = parse_url($url, PHP_URL_PATH);
            $path = is_string($path) && $path !== '' ? $path : $url;
            $path = explode('?', $path, 2)[0];
            $physical = app(ImageStorageService::class)->toPhysicalPath($path);

            return $this->usableUrlCache[$url] = is_file($physical);
        } catch (\Throwable) {
            return $this->usableUrlCache[$url] = false;
        }
    }

    private function findReadyAssetByPublicUrl(string $url): ?ImageAsset
    {
        if (array_key_exists($url, $this->readyAssetByUrl)) {
            return $this->readyAssetByUrl[$url];
        }

        return $this->readyAssetByUrl[$url] = ImageAsset::query()
            ->where('original_public_url', $url)
            ->where('status', 'ready')
            ->first();
    }

    private function assetFileExists(ImageAsset $asset): bool
    {
        $cacheKey = $asset->getKey() ?? ('url:'.(string) ($asset->original_public_url ?? ''));

        if (array_key_exists($cacheKey, $this->assetExistsById)) {
            return $this->assetExistsById[$cacheKey];
        }

        $publicUrl = (string) ($asset->original_public_url ?? '');

        if ($publicUrl !== '') {
            $physical = app(ImageStorageService::class)->ensureManagedFileOnCurrentRoot(
                $publicUrl,
                filled($asset->physical_storage_path) ? (string) $asset->physical_storage_path : null,
            );

            if ($physical !== null) {
                if ((string) $asset->physical_storage_path !== $physical) {
                    $asset->update(['physical_storage_path' => $physical]);
                }

                return $this->assetExistsById[$cacheKey] = true;
            }

            return $this->assetExistsById[$cacheKey] = false;
        }

        return $this->assetExistsById[$cacheKey] = filled($asset->physical_storage_path)
            && is_file((string) $asset->physical_storage_path);
    }

    private function resolveStoredPathUrl(?string $path): ?string
    {
        if (! filled($path)) {
            return null;
        }

        if (preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }

        if (str_starts_with($path, '/images/')) {
            return $path;
        }

        return null;
    }
}
