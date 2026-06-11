<?php

namespace App\Services\Cinema;

use App\Models\ImageAsset;
use App\Models\Person;

/**
 * Resolves preferred public image URLs and responsive image objects for API responses.
 */
class ImagePresentationService
{
    public function __construct(
        private readonly MediaPathService $mediaPathService,
        private readonly ImageVariantUrlBuilder $variantUrlBuilder,
    ) {
    }

    public function resolveMoviePosterUrl(?string $posterPath, array $metadata): ?string
    {
        return $this->resolvePreferredUrl(
            data_get($metadata, 'images.poster.original'),
            data_get($metadata, 'tmdb.poster_url'),
            $this->mediaPathService->inferPublicUrlFromAbsolutePath($posterPath),
        );
    }

    public function resolveMovieBackdropUrl(array $metadata): ?string
    {
        return $this->resolvePreferredUrl(
            data_get($metadata, 'images.backdrop.original'),
            data_get($metadata, 'tmdb.backdrop_url'),
        );
    }

    public function resolveSeriesPosterUrl(?string $posterUrl, array $metadata): ?string
    {
        return $this->resolvePreferredUrl(
            data_get($metadata, 'images.poster.original'),
            $posterUrl,
            data_get($metadata, 'tmdb.poster_url'),
        );
    }

    public function resolveSeriesBackdropUrl(?string $backdropUrl, array $metadata): ?string
    {
        return $this->resolvePreferredUrl(
            data_get($metadata, 'images.backdrop.original'),
            $backdropUrl,
            data_get($metadata, 'tmdb.backdrop_url'),
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
        if (! $this->variantUrlBuilder->isManagedPublicUrl($url)) {
            return true;
        }

        $asset = $this->findReadyAssetByPublicUrl($url);

        if (! $asset) {
            return true;
        }

        return $this->assetFileExists($asset);
    }

    private function findReadyAssetByPublicUrl(string $url): ?ImageAsset
    {
        return ImageAsset::query()
            ->where('original_public_url', $url)
            ->where('status', 'ready')
            ->first();
    }

    private function assetFileExists(ImageAsset $asset): bool
    {
        return filled($asset->physical_storage_path) && is_file((string) $asset->physical_storage_path);
    }
}
