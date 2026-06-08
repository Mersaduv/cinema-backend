<?php

namespace App\Support;

use App\Models\MediaAsset;
use App\Models\Movie;
use Illuminate\Support\Collection;

class CatalogListPresentation
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function listRating(?float $filterRating, array $metadata): ?float
    {
        if ($filterRating !== null) {
            return $filterRating;
        }

        $tmdbRating = data_get($metadata, 'tmdb.rating');

        return $tmdbRating !== null ? (float) $tmdbRating : null;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function playbackQualityLabel(?MediaAsset $mediaAsset, array $metadata): string
    {
        $variants = $mediaAsset?->relationLoaded('variants')
            ? $mediaAsset->variants
            : collect();

        foreach ($variants as $variant) {
            $resolution = strtolower((string) ($variant->resolution ?? ''));
            if (str_contains($resolution, '2160') || str_contains($resolution, '4k')) {
                return '4K';
            }
        }

        $hint = strtolower((string) data_get($metadata, 'playback.max_quality', ''));
        if (str_contains($hint, '2160') || str_contains($hint, '4k')) {
            return '4K';
        }

        return 'HD';
    }

    /**
     * @return array<int, string>
     */
    public static function posterGenreLine(Collection $genres, int $limit = 3): array
    {
        return $genres
            ->pluck('name')
            ->filter()
            ->take($limit)
            ->values()
            ->all();
    }
}
