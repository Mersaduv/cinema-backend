<?php

namespace App\Services\Cinema;

use App\Models\Movie;
use App\Models\Series;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Home hero editorial slider: hard cap across movies + series.
 */
class HomeHeroFeaturedLimitService
{
    public const MAX_FEATURED = 7;

    public function max(): int
    {
        return self::MAX_FEATURED;
    }

    /**
     * Count items currently marked for the home hero slider.
     *
     * @param  'movie'|'series'|null  $excludeType
     */
    public function countFeatured(?string $excludeType = null, ?int $excludeId = null): int
    {
        $movies = 0;
        $series = 0;

        if (Schema::hasColumn('movies', 'is_featured')) {
            $query = Movie::query()->where('is_featured', true);
            if ($excludeType === 'movie' && $excludeId !== null) {
                $query->where('id', '!=', $excludeId);
            }
            $movies = (int) $query->count();
        }

        if (Schema::hasColumn('series', 'is_featured')) {
            $query = Series::query()->where('is_featured', true);
            if ($excludeType === 'series' && $excludeId !== null) {
                $query->where('id', '!=', $excludeId);
            }
            $series = (int) $query->count();
        }

        return $movies + $series;
    }

    /**
     * @param  'movie'|'series'  $type
     *
     * @throws ValidationException
     */
    public function assertCanEnableFeatured(string $type, ?int $excludeId = null): void
    {
        $count = $this->countFeatured($type, $excludeId);

        if ($count >= self::MAX_FEATURED) {
            throw ValidationException::withMessages([
                'is_featured' => [
                    sprintf(
                        'اسلایدر صفحه اصلی حداکثر %d مورد می‌تواند داشته باشد. هم‌اکنون %d مورد فعال است؛ ابتدا یکی را غیرفعال کنید.',
                        self::MAX_FEATURED,
                        $count,
                    ),
                ],
            ]);
        }
    }

    /**
     * Snapshot for admin forms / options endpoints.
     *
     * @return array{max: int, current: int, remaining: int}
     */
    public function snapshot(?string $excludeType = null, ?int $excludeId = null): array
    {
        $current = $this->countFeatured($excludeType, $excludeId);

        return [
            'max' => self::MAX_FEATURED,
            'current' => $current,
            'remaining' => max(0, self::MAX_FEATURED - $current),
        ];
    }
}
