<?php

namespace App\Http\Controllers;

use App\Models\Movie;
use App\Models\Review;
use App\Models\Series;
use App\Models\User;
use App\Services\Cinema\ReviewRatingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class PublicReviewController extends Controller
{
    public function __construct(
        private readonly ReviewRatingService $reviewRatingService,
    ) {
    }

    public function movieReviews(Request $request, Movie $movie): JsonResponse
    {
        if ($movie->publish_status !== 'published') {
            return response()->json(['success' => false, 'message' => 'Movie is not published.'], 404);
        }

        return $this->reviewsPayload($request, 'movie', $movie->id);
    }

    public function seriesReviews(Request $request, Series $series): JsonResponse
    {
        if ($series->publish_status !== 'published') {
            return response()->json(['success' => false, 'message' => 'Series is not published.'], 404);
        }

        return $this->reviewsPayload($request, 'series', $series->id);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User || $user->status === 'banned') {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
        }

        $validated = Validator::make($request->all(), [
            'reviewable_type' => ['required', Rule::in(['movie', 'series'])],
            'slug' => ['required', 'string', 'max:255'],
            'rating' => ['required', 'integer', 'between:1,10'],
            'body' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        $content = $this->resolvePublishedContent(
            $validated['reviewable_type'],
            $validated['slug'],
        );

        $existing = Review::query()
            ->where('user_id', $user->id)
            ->forContent($validated['reviewable_type'], $content->id)
            ->first();

        if ($existing) {
            return response()->json([
                'success' => false,
                'message' => 'You have already reviewed this title.',
                'data' => ['review' => $this->transformReview($existing, $user)],
            ], 409);
        }

        $review = Review::query()->create([
            'reviewable_type' => $validated['reviewable_type'],
            'reviewable_id' => $content->id,
            'user_id' => $user->id,
            'rating' => (int) $validated['rating'],
            'body' => filled($validated['body'] ?? null)
                ? strip_tags((string) $validated['body'])
                : null,
            'is_approved' => true,
        ]);

        $review->load('user');

        return response()->json([
            'success' => true,
            'data' => ['review' => $this->transformReview($review, $user)],
        ], 201);
    }

    public function update(Request $request, Review $review): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
        }

        if ((int) $review->user_id !== (int) $user->id) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        $validated = Validator::make($request->all(), [
            'rating' => ['sometimes', 'integer', 'between:1,10'],
            'body' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        $review->update([
            'rating' => array_key_exists('rating', $validated) ? (int) $validated['rating'] : $review->rating,
            'body' => array_key_exists('body', $validated)
                ? (filled($validated['body']) ? strip_tags((string) $validated['body']) : null)
                : $review->body,
            'is_approved' => true,
        ]);

        $review->load('user');

        return response()->json([
            'success' => true,
            'data' => ['review' => $this->transformReview($review, $user)],
        ]);
    }

    public function destroy(Request $request, Review $review): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
        }

        if ((int) $review->user_id !== (int) $user->id) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        $review->delete();

        return response()->json(['success' => true, 'message' => 'Review deleted.']);
    }

    private function reviewsPayload(Request $request, string $type, int $contentId): JsonResponse
    {
        $page = max(1, (int) $request->integer('page', 1));
        $perPage = min(max((int) $request->integer('per_page', 10), 1), 50);
        $sort = (string) $request->string('sort', 'newest');

        $query = Review::query()
            ->approved()
            ->forContent($type, $contentId)
            ->with('user');

        $query = match ($sort) {
            'oldest' => $query->orderBy('created_at'),
            'top_rated' => $query->orderByDesc('rating')->orderByDesc('created_at'),
            default => $query->orderByDesc('created_at'),
        };

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);
        $authUser = $request->user();

        $userReview = null;
        if ($authUser instanceof User) {
            $own = Review::query()
                ->forContent($type, $contentId)
                ->where('user_id', $authUser->id)
                ->with('user')
                ->first();
            $userReview = $own ? $this->transformReview($own, $authUser) : null;
        }

        return response()->json([
            'success' => true,
            'data' => [
                'reviews' => collect($paginator->items())
                    ->map(fn (Review $review) => $this->transformReview($review, $authUser))
                    ->values(),
                'summary' => $this->reviewRatingService->summaryForContent($type, $contentId),
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
                'user_review' => $userReview,
            ],
        ]);
    }

    private function resolvePublishedContent(string $type, string $slug): Movie|Series
    {
        if ($type === 'movie') {
            return Movie::query()
                ->where('slug', $slug)
                ->where('publish_status', 'published')
                ->firstOrFail();
        }

        return Series::query()
            ->where('slug', $slug)
            ->where('publish_status', 'published')
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function transformReview(Review $review, ?User $authUser): array
    {
        return [
            'id' => $review->id,
            'user' => [
                'id' => $review->user->id,
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
