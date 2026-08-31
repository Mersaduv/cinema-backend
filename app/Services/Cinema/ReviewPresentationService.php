<?php

namespace App\Services\Cinema;

use App\Models\Movie;
use App\Models\Review;
use App\Models\Series;
use App\Models\Viewer;

class ReviewPresentationService
{
    /**
     * @return array<string, mixed>
     */
    public function transform(Review $review, ?Viewer $authViewer): array
    {
        $review->loadMissing('viewer');

        return [
            'id' => $review->id,
            'user' => [
                'id' => $review->viewer->id,
                'name' => $review->viewer->name,
                'avatar_url' => null,
            ],
            'viewer' => [
                'id' => $review->viewer->id,
                'name' => $review->viewer->name,
                'avatar_url' => null,
            ],
            'rating' => (int) $review->rating,
            'body' => $review->body,
            'status' => $review->status,
            'created_at' => $review->created_at?->toISOString(),
            'is_own' => $authViewer !== null && (int) $review->viewer_id === (int) $authViewer->id,
            'is_pending' => $review->status === Review::STATUS_PENDING,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function adminTransform(Review $review): array
    {
        $content = $this->resolveContent($review);
        $review->loadMissing('viewer');

        return [
            'id' => $review->id,
            'reviewable_type' => $review->reviewable_type,
            'reviewable_id' => $review->reviewable_id,
            'content_title' => $content['title'] ?? null,
            'content_slug' => $content['slug'] ?? null,
            'viewer' => [
                'id' => $review->viewer->id,
                'name' => $review->viewer->name,
                'email' => $review->viewer->email,
            ],
            'rating' => (int) $review->rating,
            'body' => $review->body,
            'body_original' => $review->body_original,
            'status' => $review->status,
            'admin_note' => $review->admin_note,
            'is_approved' => $review->status === Review::STATUS_APPROVED,
            'created_at' => $review->created_at?->toISOString(),
            'moderated_at' => $review->moderated_at?->toISOString(),
        ];
    }

    /**
     * @return array{title: ?string, slug: ?string}
     */
    private function resolveContent(Review $review): array
    {
        if ($review->reviewable_type === 'movie') {
            $movie = Movie::query()->find($review->reviewable_id);

            return [
                'title' => $movie?->title,
                'slug' => $movie?->slug,
            ];
        }

        $series = Series::query()->find($review->reviewable_id);

        return [
            'title' => $series?->title,
            'slug' => $series?->slug,
        ];
    }
}
