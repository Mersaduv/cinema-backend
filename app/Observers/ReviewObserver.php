<?php

namespace App\Observers;

use App\Models\Review;
use App\Services\Cinema\ReviewRatingService;

class ReviewObserver
{
    public function __construct(
        private readonly ReviewRatingService $reviewRatingService,
    ) {
    }

    public function created(Review $review): void
    {
        $this->reviewRatingService->recalculateForContent(
            $review->reviewable_type,
            (int) $review->reviewable_id,
        );
    }

    public function updated(Review $review): void
    {
        $this->reviewRatingService->recalculateForContent(
            $review->reviewable_type,
            (int) $review->reviewable_id,
        );
    }

    public function deleted(Review $review): void
    {
        $this->reviewRatingService->recalculateForContent(
            $review->reviewable_type,
            (int) $review->reviewable_id,
        );
    }
}
