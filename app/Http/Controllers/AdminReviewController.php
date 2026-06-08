<?php

namespace App\Http\Controllers;

use App\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminReviewController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->integer('per_page', 20), 1), 50);

        $reviews = Review::query()
            ->with('user')
            ->when($request->filled('reviewable_type'), function ($query) use ($request): void {
                $query->where('reviewable_type', (string) $request->string('reviewable_type'));
            })
            ->when($request->has('is_approved'), function ($query) use ($request): void {
                $query->where('is_approved', $request->boolean('is_approved'));
            })
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'reviews' => collect($reviews->items())->map(fn (Review $review) => [
                    'id' => $review->id,
                    'reviewable_type' => $review->reviewable_type,
                    'reviewable_id' => $review->reviewable_id,
                    'user' => [
                        'id' => $review->user->id,
                        'name' => $review->user->name,
                    ],
                    'rating' => (int) $review->rating,
                    'body' => $review->body,
                    'is_approved' => (bool) $review->is_approved,
                    'created_at' => $review->created_at?->toISOString(),
                ])->values(),
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
        $review->update(['is_approved' => true]);

        return response()->json(['success' => true, 'message' => 'Review approved.']);
    }

    public function reject(Review $review): JsonResponse
    {
        $review->update(['is_approved' => false]);

        return response()->json(['success' => true, 'message' => 'Review rejected.']);
    }

    public function destroy(Review $review): JsonResponse
    {
        $review->delete();

        return response()->json(['success' => true, 'message' => 'Review deleted.']);
    }
}
