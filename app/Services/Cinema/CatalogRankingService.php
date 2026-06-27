<?php

namespace App\Services\Cinema;

/**
 * Approximate ranking helpers. TMDB trending is a separate short-window model;
 * these formulas are fallbacks only — not substitutes for RefreshTrendingJob output.
 */
class CatalogRankingService
{
    public function topRatedMinVoteCount(): int
    {
        return max(1, (int) config('cinema.catalog.top_rated_min_vote_count', 30));
    }

    public function trendingStaleHours(): int
    {
        return max(1, (int) config('cinema.catalog.trending_stale_hours', 48));
    }

    public function trendingFallbackMinItems(): int
    {
        return max(1, (int) config('cinema.catalog.trending_fallback_min_items', 6));
    }

    /**
     * Fallback score when TMDB trending ∩ catalog is too small.
     * trending_fallback_score = filter_popularity * recency_weight(year)
     */
    public function trendingFallbackScore(
        ?float $filterPopularity,
        ?int $releaseYear,
        ?\DateTimeInterface $publishedAt = null,
    ): float {
        $popularity = max(0.0, (float) ($filterPopularity ?? 0));

        if ($popularity <= 0) {
            return 0.0;
        }

        $currentYear = (int) date('Y');
        $year = $releaseYear;

        if ($year === null && $publishedAt !== null) {
            $year = (int) $publishedAt->format('Y');
        }

        $year ??= $currentYear;
        $age = max(0, $currentYear - $year);
        $weight = max(0.3, 1.0 - ($age * 0.12));

        return round($popularity * $weight, 6);
    }

    /**
     * Blended Cinema popularity: TMDB global + local watch activity (Phase 4).
     * Weights are configurable — TMDB reflects global TMDB traffic, internal reflects our audience.
     */
    public function popularHybridScore(?float $filterPopularity, ?int $internalPopularity30d): float
    {
        $tmdbWeight = (float) config('cinema.popular_hybrid_tmdb_weight', 0.6);
        $internalWeight = (float) config('cinema.popular_hybrid_internal_weight', 0.4);
        $totalWeight = $tmdbWeight + $internalWeight;

        if ($totalWeight <= 0) {
            return 0.0;
        }

        $tmdbNorm = max(0.0, (float) ($filterPopularity ?? 0));
        $internalNorm = max(0.0, (float) ($internalPopularity30d ?? 0));

        return round((($tmdbNorm * $tmdbWeight) + ($internalNorm * $internalWeight)) / $totalWeight, 6);
    }
}
