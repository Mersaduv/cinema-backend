<?php

namespace App\Http\Controllers;

use App\Models\Movie;
use App\Models\Review;
use App\Models\Series;
use App\Models\Viewer;
use App\Services\Cinema\ReviewPresentationService;
use App\Services\Cinema\ReviewRatingService;
use App\Support\ReviewValidation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PublicReviewController extends Controller
{
    public function __construct(
        private readonly ReviewRatingService $reviewRatingService,
        private readonly ReviewPresentationService $reviewPresentationService,
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
        $viewer = $request->user();

        if (! $viewer instanceof Viewer || $viewer->status === 'banned') {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
        }

        $validated = Validator::make($request->all(), ReviewValidation::submitRules())->validate();

        $content = $this->resolvePublishedContent(
            $validated['reviewable_type'],
            $validated['slug'],
        );

        $existing = Review::query()
            ->where('viewer_id', $viewer->id)
            ->forContent($validated['reviewable_type'], $content->id)
            ->first();

        if ($existing) {
            return response()->json([
                'success' => false,
                'message' => 'شما قبلاً برای این عنوان نظر ثبت کرده‌اید.',
                'data' => ['review' => $this->reviewPresentationService->transform($existing, $viewer)],
            ], 409);
        }

        $review = Review::query()->create([
            'reviewable_type' => $validated['reviewable_type'],
            'reviewable_id' => $content->id,
            'viewer_id' => $viewer->id,
            'rating' => (int) $validated['rating'],
            'body' => ReviewValidation::sanitizeBody($validated['body']),
            'status' => Review::STATUS_PENDING,
            'is_approved' => false,
        ]);

        $review->load('viewer');

        return response()->json([
            'success' => true,
            'message' => 'نظر شما ثبت شد و پس از تایید ادمین نمایش داده می‌شود.',
            'data' => ['review' => $this->reviewPresentationService->transform($review, $viewer)],
        ], 201);
    }

    public function update(Request $request, Review $review): JsonResponse
    {
        $viewer = $request->user();

        if (! $viewer instanceof Viewer) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
        }

        if ((int) $review->viewer_id !== (int) $viewer->id) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        $validated = Validator::make($request->all(), ReviewValidation::updateRules())->validate();

        $review->update([
            'rating' => array_key_exists('rating', $validated) ? (int) $validated['rating'] : $review->rating,
            'body' => array_key_exists('body', $validated)
                ? ReviewValidation::sanitizeBody($validated['body'])
                : $review->body,
            'status' => Review::STATUS_PENDING,
            'is_approved' => false,
            'moderated_at' => null,
        ]);

        $review->load('viewer');

        return response()->json([
            'success' => true,
            'message' => 'نظر شما ویرایش شد و دوباره در صف تایید قرار گرفت.',
            'data' => ['review' => $this->reviewPresentationService->transform($review, $viewer)],
        ]);
    }

    public function destroy(Request $request, Review $review): JsonResponse
    {
        $viewer = $request->user();

        if (! $viewer instanceof Viewer) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
        }

        if ((int) $review->viewer_id !== (int) $viewer->id) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        $type = $review->reviewable_type;
        $contentId = (int) $review->reviewable_id;
        $wasApproved = $review->status === Review::STATUS_APPROVED;

        $review->delete();

        if ($wasApproved) {
            $this->reviewRatingService->recalculateForContent($type, $contentId);
        }

        return response()->json(['success' => true, 'message' => 'نظر حذف شد.']);
    }

    private function reviewsPayload(Request $request, string $type, int $contentId): JsonResponse
    {
        $page = max(1, (int) $request->integer('page', 1));
        $perPage = min(max((int) $request->integer('per_page', 10), 1), 50);
        $sort = (string) $request->string('sort', 'newest');

        $query = Review::query()
            ->approved()
            ->forContent($type, $contentId)
            ->with('viewer');

        $query = match ($sort) {
            'oldest' => $query->orderBy('created_at'),
            'top_rated' => $query->orderByDesc('rating')->orderByDesc('created_at'),
            default => $query->orderByDesc('created_at'),
        };

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);
        $authViewer = $request->user();

        $userReview = null;
        if ($authViewer instanceof Viewer) {
            $own = Review::query()
                ->forContent($type, $contentId)
                ->where('viewer_id', $authViewer->id)
                ->with('viewer')
                ->first();
            $userReview = $own ? $this->reviewPresentationService->transform($own, $authViewer) : null;
        }

        return response()->json([
            'success' => true,
            'data' => [
                'reviews' => collect($paginator->items())
                    ->map(fn (Review $review) => $this->reviewPresentationService->transform($review, $authViewer instanceof Viewer ? $authViewer : null))
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
}
