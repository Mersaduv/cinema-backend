<?php

namespace App\Services\Cinema;

use App\Models\Movie;
use App\Models\Review;
use App\Models\Series;

class ReviewRatingService
{
    public function recalculateForContent(string $type, int $id): void
    {
        $average = Review::query()
            ->approved()
            ->forContent($type, $id)
            ->avg('rating');

        $rounded = $average !== null ? round((float) $average, 1) : null;

        if ($type === 'movie') {
            Movie::query()->whereKey($id)->update(['cached_avg_rating' => $rounded]);

            return;
        }

        if ($type === 'series') {
            Series::query()->whereKey($id)->update(['cached_avg_rating' => $rounded]);
        }
    }

    /**
     * @return array{total_count: int, average_rating: float, distribution: array<string, int>}
     */
    public function summaryForContent(string $type, int $id): array
    {
        $rows = Review::query()
            ->approved()
            ->forContent($type, $id)
            ->selectRaw('rating, COUNT(*) as count')
            ->groupBy('rating')
            ->pluck('count', 'rating');

        $distribution = [];
        for ($i = 1; $i <= 10; $i++) {
            $distribution[(string) $i] = (int) ($rows[$i] ?? 0);
        }

        $total = array_sum($distribution);
        $weighted = 0;
        foreach ($distribution as $rating => $count) {
            $weighted += (int) $rating * $count;
        }

        $average = $total > 0 ? round($weighted / $total, 1) : 0.0;

        return [
            'total_count' => $total,
            'average_rating' => $average,
            'distribution' => $distribution,
        ];
    }
}
