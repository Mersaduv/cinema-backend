<?php

namespace App\Services\Cinema;

use App\Models\Movie;
use App\Models\Review;
use App\Models\Series;
use Illuminate\Support\Facades\DB;

class ReviewRatingService
{
    /**
     * @return array{
     *   total_count: int,
     *   average_rating: float,
     *   distribution: array<string, int>
     * }
     */
    public function summaryForContent(string $type, int $contentId): array
    {
        $rows = Review::query()
            ->approved()
            ->forContent($type, $contentId)
            ->select('rating', DB::raw('COUNT(*) as count'))
            ->groupBy('rating')
            ->pluck('count', 'rating');

        $distribution = collect(range(1, 10))
            ->mapWithKeys(fn (int $rating) => [(string) $rating => 0])
            ->all();

        $totalCount = 0;
        $weightedSum = 0;

        foreach ($rows as $rating => $count) {
            $ratingKey = (string) (int) $rating;
            $countValue = (int) $count;
            $distribution[$ratingKey] = $countValue;
            $totalCount += $countValue;
            $weightedSum += (int) $rating * $countValue;
        }

        return [
            'total_count' => $totalCount,
            'average_rating' => $totalCount > 0 ? round($weightedSum / $totalCount, 1) : 0,
            'distribution' => $distribution,
        ];
    }

    public function recalculateForContent(string $type, int $contentId): void
    {
        $summary = $this->summaryForContent($type, $contentId);
        $average = $summary['total_count'] > 0 ? $summary['average_rating'] : null;

        if ($type === 'movie') {
            Movie::query()->whereKey($contentId)->update(['cached_avg_rating' => $average]);

            return;
        }

        Series::query()->whereKey($contentId)->update(['cached_avg_rating' => $average]);
    }
}
