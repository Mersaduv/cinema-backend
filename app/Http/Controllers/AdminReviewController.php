<?php

namespace App\Http\Controllers;

use App\Models\Review;
use App\Services\Cinema\ReviewPresentationService;
use App\Services\Cinema\ReviewRatingService;
use App\Support\ReviewValidation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AdminReviewController extends Controller
{
    public function __construct(
        private readonly ReviewPresentationService $reviewPresentationService,
        private readonly ReviewRatingService $reviewRatingService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->integer('per_page', 20), 1), 50);

        $reviews = Review::query()
            ->with('viewer')
            ->when($request->filled('reviewable_type'), function ($query) use ($request): void {
                $query->where('reviewable_type', (string) $request->string('reviewable_type'));
            })
            ->when($request->filled('status'), function ($query) use ($request): void {
                $query->where('status', (string) $request->string('status'));
            })
            ->when($request->has('is_approved') && ! $request->filled('status'), function ($query) use ($request): void {
                $query->where(
                    'status',
                    $request->boolean('is_approved') ? Review::STATUS_APPROVED : Review::STATUS_PENDING,
                );
            })
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = trim((string) $request->string('search'));
                $query->where(function ($inner) use ($search): void {
                    $inner
                        ->where('body', 'like', '%' . $search . '%')
                        ->orWhereHas('viewer', fn ($viewerQuery) => $viewerQuery->where('name', 'like', '%' . $search . '%'));
                });
            })
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'reviews' => collect($reviews->items())
                    ->map(fn (Review $review) => $this->reviewPresentationService->adminTransform($review))
                    ->values(),
                'pagination' => [
                    'current_page' => $reviews->currentPage(),
                    'last_page' => $reviews->lastPage(),
                    'per_page' => $reviews->perPage(),
                    'total' => $reviews->total(),
                ],
            ],
        ]);
    }

    public function approve(Review $review): JsonResponse
    {
        $wasApproved = $review->status === Review::STATUS_APPROVED;

        $review->update([
            'status' => Review::STATUS_APPROVED,
            'is_approved' => true,
            'moderated_at' => now(),
        ]);

        if (! $wasApproved) {
            $this->reviewRatingService->recalculateForContent(
                $review->reviewable_type,
                (int) $review->reviewable_id,
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'نظر تایید شد.',
            'data' => ['review' => $this->reviewPresentationService->adminTransform($review->fresh('viewer'))],
        ]);
    }

    public function reject(Review $review): JsonResponse
    {
        $wasApproved = $review->status === Review::STATUS_APPROVED;

        $review->update([
            'status' => Review::STATUS_REJECTED,
            'is_approved' => false,
            'moderated_at' => now(),
        ]);

        if ($wasApproved) {
            $this->reviewRatingService->recalculateForContent(
                $review->reviewable_type,
                (int) $review->reviewable_id,
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'نظر رد شد.',
            'data' => ['review' => $this->reviewPresentationService->adminTransform($review->fresh('viewer'))],
        ]);
    }

    public function update(Request $request, Review $review): JsonResponse
    {
        $validated = Validator::make($request->all(), ReviewValidation::adminUpdateRules())->validate();
        $wasApproved = $review->status === Review::STATUS_APPROVED;
        $updates = [];

        if (array_key_exists('rating', $validated)) {
            $updates['rating'] = (int) $validated['rating'];
        }

        if (array_key_exists('admin_note', $validated)) {
            $updates['admin_note'] = $validated['admin_note'];
        }

        if (array_key_exists('body', $validated)) {
            $sanitized = ReviewValidation::sanitizeBody($validated['body']);

            if ($request->boolean('censor') && filled($sanitized) && ! filled($review->body_original)) {
                $updates['body_original'] = $review->body;
            }

            $updates['body'] = $sanitized;
        }

        if ($updates !== []) {
            $updates['moderated_at'] = now();
            $review->update($updates);
        }

        if ($wasApproved) {
            $this->reviewRatingService->recalculateForContent(
                $review->reviewable_type,
                (int) $review->reviewable_id,
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'نظر به‌روزرسانی شد.',
            'data' => ['review' => $this->reviewPresentationService->adminTransform($review->fresh('viewer'))],
        ]);
    }

    public function destroy(Review $review): JsonResponse
    {
        $type = $review->reviewable_type;
        $contentId = (int) $review->reviewable_id;
        $wasApproved = $review->status === Review::STATUS_APPROVED;

        $review->delete();

        if ($wasApproved) {
            $this->reviewRatingService->recalculateForContent($type, $contentId);
        }

        return response()->json(['success' => true, 'message' => 'نظر حذف شد.']);
    }
}
