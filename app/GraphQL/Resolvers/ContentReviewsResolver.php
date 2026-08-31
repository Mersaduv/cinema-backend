<?php

namespace App\GraphQL\Resolvers;

use App\Models\Movie;
use App\Models\Review;
use App\Models\Viewer;
use App\Services\Cinema\ReviewPresentationService;
use App\Services\Cinema\ReviewRatingService;
use GraphQL\Type\Definition\ResolveInfo;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class ContentReviewsResolver
{
    public function __construct(
        private readonly ReviewRatingService $reviewRatingService,
        private readonly ReviewPresentationService $reviewPresentationService,
    ) {
    }

    public function movieCachedAvgRating(Movie $movie): ?float
    {
        return $movie->cached_avg_rating !== null ? (float) $movie->cached_avg_rating : null;
    }

    public function seriesOverviewCachedAvgRating(array $root): ?float
    {
        $value = $root['cached_avg_rating'] ?? null;

        return $value !== null ? (float) $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function forMovie(mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): array
    {
        $movie = $root instanceof Movie ? $root : null;

        if (! $movie) {
            return $this->emptyPayload();
        }

        return $this->buildPayload('movie', (int) $movie->id, $args, $context);
    }

    /**
     * @return array<string, mixed>
     */
    public function forSeriesOverview(mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): array
    {
        if (! is_array($root) || ! isset($root['id'])) {
            return $this->emptyPayload();
        }

        return $this->buildPayload('series', (int) $root['id'], $args, $context);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(string $type, int $contentId, array $args, GraphQLContext $context): array
    {
        $page = max(1, (int) ($args['page'] ?? 1));
        $perPage = min(max((int) ($args['first'] ?? 10), 1), 50);
        $sort = strtoupper((string) ($args['sort'] ?? 'NEWEST'));

        $query = Review::query()
            ->approved()
            ->forContent($type, $contentId)
            ->with('viewer');

        $query = match ($sort) {
            'OLDEST' => $query->orderBy('created_at'),
            'TOP_RATED' => $query->orderByDesc('rating')->orderByDesc('created_at'),
            default => $query->orderByDesc('created_at'),
        };

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);
        $authViewer = $context->user();
        $viewer = $authViewer instanceof Viewer ? $authViewer : null;

        $userReview = null;
        if ($viewer !== null) {
            $own = Review::query()
                ->forContent($type, $contentId)
                ->where('viewer_id', $viewer->id)
                ->with('viewer')
                ->first();
            $userReview = $own ? $this->reviewPresentationService->transform($own, $viewer) : null;
        }

        $summary = $this->reviewRatingService->summaryForContent($type, $contentId);

        return [
            'reviews' => collect($paginator->items())
                ->map(fn (Review $review) => $this->reviewPresentationService->transform($review, $viewer))
                ->values()
                ->all(),
            'summary' => [
                'total_count' => $summary['total_count'],
                'average_rating' => $summary['average_rating'],
                'distribution' => collect($summary['distribution'])
                    ->map(fn ($count, $rating) => ['rating' => (int) $rating, 'count' => (int) $count])
                    ->values()
                    ->all(),
            ],
            'user_review' => $userReview,
            'paginatorInfo' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyPayload(): array
    {
        return [
            'reviews' => [],
            'summary' => [
                'total_count' => 0,
                'average_rating' => 0,
                'distribution' => collect(range(1, 10))
                    ->map(fn (int $rating) => ['rating' => $rating, 'count' => 0])
                    ->values()
                    ->all(),
            ],
            'user_review' => null,
            'paginatorInfo' => [
                'currentPage' => 1,
                'lastPage' => 1,
                'total' => 0,
            ],
        ];
    }
}
