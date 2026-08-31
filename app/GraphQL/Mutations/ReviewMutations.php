<?php

namespace App\GraphQL\Mutations;

use App\Models\Movie;
use App\Models\Review;
use App\Models\Series;
use App\Models\Viewer;
use App\Services\Cinema\ReviewPresentationService;
use App\Support\ReviewValidation;
use GraphQL\Error\Error;
use Illuminate\Support\Facades\Validator;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class ReviewMutations
{
    public function __construct(
        private readonly ReviewPresentationService $reviewPresentationService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function submitReview(mixed $root, array $args, GraphQLContext $context): array
    {
        $viewer = $this->authViewer($context);

        $validated = Validator::make($args, [
            'slug' => ['required', 'string', 'max:255'],
            'reviewable_type' => ['required', 'in:movie,series'],
            'rating' => ['required', 'integer', 'between:1,10'],
            'body' => ReviewValidation::submitRules()['body'],
        ])->validate();

        $content = $this->resolvePublishedContent(
            $validated['reviewable_type'],
            $validated['slug'],
        );

        $existing = Review::query()
            ->where('viewer_id', $viewer->id)
            ->forContent($validated['reviewable_type'], $content->id)
            ->first();

        if ($existing) {
            throw new Error('شما قبلاً برای این عنوان نظر ثبت کرده‌اید.');
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

        return $this->reviewPresentationService->transform($review, $viewer);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function updateReview(mixed $root, array $args, GraphQLContext $context): array
    {
        $viewer = $this->authViewer($context);

        $review = Review::query()->with('viewer')->findOrFail((int) $args['id']);

        if ((int) $review->viewer_id !== (int) $viewer->id) {
            throw new Error('دسترسی مجاز نیست.');
        }

        $validated = Validator::make($args, [
            'rating' => ReviewValidation::updateRules()['rating'],
            'body' => ReviewValidation::updateRules()['body'],
        ])->validate();

        $review->update([
            'rating' => array_key_exists('rating', $validated) ? (int) $validated['rating'] : $review->rating,
            'body' => array_key_exists('body', $validated)
                ? ReviewValidation::sanitizeBody($validated['body'])
                : $review->body,
            'status' => Review::STATUS_PENDING,
            'is_approved' => false,
            'moderated_at' => null,
        ]);

        return $this->reviewPresentationService->transform($review->fresh('viewer'), $viewer);
    }

    public function deleteReview(mixed $root, array $args, GraphQLContext $context): bool
    {
        $viewer = $this->authViewer($context);
        $review = Review::query()->findOrFail((int) $args['id']);

        if ((int) $review->viewer_id !== (int) $viewer->id) {
            throw new Error('دسترسی مجاز نیست.');
        }

        $review->delete();

        return true;
    }

    private function authViewer(GraphQLContext $context): Viewer
    {
        $viewer = $context->user();

        if (! $viewer instanceof Viewer || $viewer->status === 'banned') {
            throw new Error('برای ثبت نظر باید وارد حساب شوید.');
        }

        return $viewer;
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
