<?php

namespace App\GraphQL\Resolvers;

use App\Models\Movie;
use App\Models\Review;
use App\Models\Series;
use App\Models\User;
use App\Services\Cinema\ReviewRatingService;
use Illuminate\Support\Facades\Auth;

class ContentReviewsResolver
{
    public function __construct(
        private readonly ReviewRatingService $reviewRatingService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function forMovie(Movie $movie, array $args): array
    {
        return $this->buildPayload('movie', (int) $movie->id, $args);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function forSeries(Series $series, array $args): array
    {
        return $this->buildPayload('series', (int) $series->id, $args);
    }

    /**
     * @param  array<string, mixed>  $root
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function forSeriesOverview(array $root, array $args): array
    {
        $series = Series::query()
            ->where('slug', (string) ($root['slug'] ?? ''))
            ->firstOrFail();

        return $this->buildPayload('series', (int) $series->id, $args);
    }

    public function movieCachedAvgRating(Movie $movie): ?float
    {
        return $movie->cached_avg_rating !== null ? (float) $movie->cached_avg_rating : null;
    }

    /**
     * @param  array<string, mixed>  $root
     */
    public function seriesOverviewCachedAvgRating(array $root): ?float
    {
        $series = Series::query()
            ->where('slug', (string) ($root['slug'] ?? ''))
            ->first();

        return $series?->cached_avg_rating !== null ? (float) $series->cached_avg_rating : null;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function buildPayload(string $type, int $contentId, array $args): array
    {
        $first = min(max((int) ($args['first'] ?? 10), 1), 50);
        $page = max(1, (int) ($args['page'] ?? 1));
        $sort = (string) ($args['sort'] ?? 'NEWEST');

        $query = Review::query()
            ->approved()
            ->forContent($type, $contentId)
            ->with('user');

        $query = match (strtoupper($sort)) {
            'OLDEST' => $query->orderBy('created_at'),
            'TOP_RATED' => $query->orderByDesc('rating')->orderByDesc('created_at'),
            default => $query->orderByDesc('created_at'),
        };

        $paginator = $query->paginate($first, ['*'], 'page', $page);
        $authUser = Auth::user();

        $userReview = null;
        if ($authUser instanceof User) {
            $own = Review::query()
                ->forContent($type, $contentId)
                ->where('user_id', $authUser->id)
                ->with('user')
                ->first();
            $userReview = $own ? $this->transformReview($own, $authUser) : null;
        }

        return [
            'reviews' => collect($paginator->items())
                ->map(fn (Review $review) => $this->transformReview($review, $authUser))
                ->values()
                ->all(),
            'summary' => $this->formatSummaryForGraphql(
                $this->reviewRatingService->summaryForContent($type, $contentId),
            ),
            'user_review' => $userReview,
            'paginatorInfo' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /**
     * @param  array{total_count: int, average_rating: float, distribution: array<string, int>}  $summary
     * @return array{total_count: int, average_rating: float, distribution: array<int, array{rating: int, count: int}>}
     */
    private function formatSummaryForGraphql(array $summary): array
    {
        $distribution = [];
        foreach ($summary['distribution'] as $rating => $count) {
            $distribution[] = [
                'rating' => (int) $rating,
                'count' => (int) $count,
            ];
        }

        return [
            'total_count' => $summary['total_count'],
            'average_rating' => $summary['average_rating'],
            'distribution' => $distribution,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformReview(Review $review, ?User $authUser): array
    {
        return [
            'id' => (string) $review->id,
            'user' => [
                'id' => (string) $review->user->id,
                'name' => $review->user->name,
                'avatar_url' => null,
            ],
            'rating' => (int) $review->rating,
            'body' => $review->body,
            'created_at' => $review->created_at?->toISOString(),
            'is_own' => $authUser !== null && (int) $review->user_id === (int) $authUser->id,
        ];
    }
}
