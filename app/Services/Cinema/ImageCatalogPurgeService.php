<?php

namespace App\Services\Cinema;

use App\Models\ImageAsset;
use App\Models\Movie;
use App\Models\Person;
use App\Models\Series;
use App\Models\SeriesEpisode;
use App\Models\SeriesSeason;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

class ImageCatalogPurgeService
{
    public function __construct(
        private readonly ImageStorageService $imageStorageService,
        private readonly ImageVariantUrlBuilder $variantUrlBuilder,
    ) {
    }

    /**
     * @return array{
     *   assets_deleted: int,
     *   files_deleted: int,
     *   movies_updated: int,
     *   series_updated: int,
     *   seasons_updated: int,
     *   episodes_updated: int,
     *   persons_updated: int,
     *   storage_root: string
     * }
     */
    public function purgeAll(): array
    {
        $assetsDeleted = (int) ImageAsset::query()->count();
        ImageAsset::query()->delete();

        $filesDeleted = $this->purgePhysicalStorage();

        $moviesUpdated = $this->purgeMovieMetadata();
        $seriesUpdated = $this->purgeSeriesRecords();
        $seasonsUpdated = $this->purgeSeasonRecords();
        $episodesUpdated = $this->purgeEpisodeRecords();
        $personsUpdated = $this->purgePersonRecords();

        return [
            'assets_deleted' => $assetsDeleted,
            'files_deleted' => $filesDeleted,
            'movies_updated' => $moviesUpdated,
            'series_updated' => $seriesUpdated,
            'seasons_updated' => $seasonsUpdated,
            'episodes_updated' => $episodesUpdated,
            'persons_updated' => $personsUpdated,
            'storage_root' => $this->imageStorageService->storageRoot(),
        ];
    }

    private function purgePhysicalStorage(): int
    {
        $prefix = $this->imageStorageService->publicPrefix();
        $absolutePrefixDir = $this->imageStorageService->toPhysicalPath($prefix);

        if (! is_dir($absolutePrefixDir)) {
            return 0;
        }

        $deleted = 0;

        foreach (File::allFiles($absolutePrefixDir) as $file) {
            if (@unlink($file->getPathname())) {
                $deleted++;
            }
        }

        foreach (File::directories($absolutePrefixDir) as $directory) {
            File::deleteDirectory($directory);
        }

        File::ensureDirectoryExists($absolutePrefixDir);

        return $deleted;
    }

    private function purgeMovieMetadata(): int
    {
        $updated = 0;

        Movie::query()->select(['id', 'metadata'])->orderBy('id')->chunkById(100, function ($movies) use (&$updated): void {
            foreach ($movies as $movie) {
                $metadata = is_array($movie->metadata) ? $movie->metadata : [];
                $nextMetadata = $this->stripProcessedImageMetadata($metadata);

                if ($nextMetadata !== $metadata) {
                    $movie->update(['metadata' => $nextMetadata]);
                    Cache::forget("cinema_img_movie_{$movie->id}");
                    $updated++;
                }
            }
        });

        return $updated;
    }

    private function purgeSeriesRecords(): int
    {
        $updated = 0;

        Series::query()->select(['id', 'poster_url', 'backdrop_url', 'metadata'])->orderBy('id')->chunkById(100, function ($seriesRows) use (&$updated): void {
            foreach ($seriesRows as $series) {
                $metadata = is_array($series->metadata) ? $series->metadata : [];
                $nextMetadata = $this->stripProcessedImageMetadata($metadata);
                $updates = [];

                if ($nextMetadata !== $metadata) {
                    $updates['metadata'] = $nextMetadata;
                }

                if ($this->isManagedImageUrl($series->poster_url)) {
                    $updates['poster_url'] = $this->restoreExternalImageUrl($nextMetadata, 'poster_url');
                }

                if ($this->isManagedImageUrl($series->backdrop_url)) {
                    $updates['backdrop_url'] = $this->restoreExternalImageUrl($nextMetadata, 'backdrop_url');
                }

                if ($updates !== []) {
                    $series->update($updates);
                    Cache::forget("cinema_img_series_{$series->id}");
                    $updated++;
                }
            }
        });

        return $updated;
    }

    private function purgeSeasonRecords(): int
    {
        $updated = 0;

        SeriesSeason::query()->select(['id', 'poster_url', 'metadata'])->orderBy('id')->chunkById(100, function ($seasons) use (&$updated): void {
            foreach ($seasons as $season) {
                $metadata = is_array($season->metadata) ? $season->metadata : [];
                $nextMetadata = $this->stripProcessedImageMetadata($metadata);
                $updates = [];

                if ($nextMetadata !== $metadata) {
                    $updates['metadata'] = $nextMetadata;
                }

                if ($this->isManagedImageUrl($season->poster_url)) {
                    $updates['poster_url'] = null;
                }

                if ($updates !== []) {
                    $season->update($updates);
                    $updated++;
                }
            }
        });

        return $updated;
    }

    private function purgeEpisodeRecords(): int
    {
        $updated = 0;

        SeriesEpisode::query()->select(['id', 'still_url', 'metadata'])->orderBy('id')->chunkById(100, function ($episodes) use (&$updated): void {
            foreach ($episodes as $episode) {
                $metadata = is_array($episode->metadata) ? $episode->metadata : [];
                $nextMetadata = $this->stripProcessedImageMetadata($metadata);
                $updates = [];

                if ($nextMetadata !== $metadata) {
                    $updates['metadata'] = $nextMetadata;
                }

                if ($this->isManagedImageUrl($episode->still_url)) {
                    $updates['still_url'] = null;
                }

                if ($updates !== []) {
                    $episode->update($updates);
                    $updated++;
                }
            }
        });

        return $updated;
    }

    private function purgePersonRecords(): int
    {
        $updated = 0;

        Person::query()->select(['id', 'profile_url', 'metadata'])->orderBy('id')->chunkById(100, function ($persons) use (&$updated): void {
            foreach ($persons as $person) {
                $metadata = is_array($person->metadata) ? $person->metadata : [];
                $nextMetadata = $this->stripProcessedImageMetadata($metadata);
                $updates = [];

                if ($nextMetadata !== $metadata) {
                    $updates['metadata'] = $nextMetadata;
                }

                if ($this->isManagedImageUrl($person->profile_url)) {
                    $updates['profile_url'] = data_get($nextMetadata, 'profile_source_url');
                }

                if ($updates !== []) {
                    $person->update($updates);
                    Cache::forget("cinema_img_person_{$person->id}");
                    $updated++;
                }
            }
        });

        return $updated;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function stripProcessedImageMetadata(array $metadata): array
    {
        unset($metadata['images']);

        $tmdb = is_array($metadata['tmdb'] ?? null) ? $metadata['tmdb'] : [];

        foreach ([
            'poster_url' => 'poster_source_url',
            'backdrop_url' => 'backdrop_source_url',
            'banner_url' => 'banner_source_url',
            'logo_url' => 'logo_source_url',
        ] as $field => $sourceKey) {
            $current = $tmdb[$field] ?? null;

            if ($this->isManagedImageUrl($current)) {
                $tmdb[$field] = $tmdb[$sourceKey] ?? null;
            }
        }

        $metadata['tmdb'] = $tmdb;

        foreach (['actors', 'cast'] as $collectionKey) {
            if (! isset($metadata[$collectionKey]) || ! is_array($metadata[$collectionKey])) {
                continue;
            }

            $metadata[$collectionKey] = array_map(function ($row) {
                if (! is_array($row)) {
                    return $row;
                }

                unset($row['profile_image']);

                if ($this->isManagedImageUrl($row['profile_url'] ?? null)) {
                    $row['profile_url'] = $row['profile_source_url'] ?? null;
                }

                return $row;
            }, $metadata[$collectionKey]);
        }

        return $metadata;
    }

    private function restoreExternalImageUrl(array $metadata, string $field): ?string
    {
        $sourceKey = match ($field) {
            'poster_url' => 'poster_source_url',
            'backdrop_url' => 'backdrop_source_url',
            default => $field . '_source_url',
        };

        $source = data_get($metadata, 'tmdb.' . $sourceKey);

        if (filled($source) && ! $this->isManagedImageUrl($source)) {
            return (string) $source;
        }

        $current = data_get($metadata, 'tmdb.' . $field);

        return filled($current) && ! $this->isManagedImageUrl($current) ? (string) $current : null;
    }

    private function isManagedImageUrl(mixed $value): bool
    {
        if (! filled($value)) {
            return false;
        }

        return $this->variantUrlBuilder->isManagedPublicUrl(trim((string) $value));
    }
}
