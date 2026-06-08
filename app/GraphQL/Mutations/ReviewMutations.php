<?php

namespace App\GraphQL\Mutations;

use App\Models\Movie;
use App\Models\Review;
use App\Models\Series;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class ReviewMutations
{
    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function submitReview(null $_, array $args): array
    {
        $user = Auth::user();

        if (! $user instanceof User || $user->status === 'banned') {
            throw new \GraphQL\Error\Error('Unauthorized');
        }

        $type = (string) $args['reviewable_type'];
        $slug = (string) $args['slug'];
        $rating = (int) $args['rating'];
        $body = filled($args['body'] ?? null) ? strip_tags((string) $args['body']) : null;

        $content = $type === 'movie'
            ? Movie::query()->where('slug', $slug)->where('publish_status', 'published')->firstOrFail()
            : Series::query()->where('slug', $slug)->where('publish_status', 'published')->firstOrFail();

        $existing = Review::query()
            ->where('user_id', $user->id)
            ->forContent($type, $content->id)
            ->first();

        if ($existing) {
            throw new \GraphQL\Error\Error('Duplicate review');
        }

        $review = Review::query()->create([
            'reviewable_type' => $type,
            'reviewable_id' => $content->id,
            'user_id' => $user->id,
            'rating' => $rating,
            'body' => $body,
            'is_approved' => true,
        ]);

        $review->load('user');

        return $this->transform($review, $user);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function updateReview(null $_, array $args): array
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new \GraphQL\Error\Error('Unauthorized');
        }

        $review = Review::query()->findOrFail((int) $args['id']);

        if ((int) $review->user_id !== (int) $user->id) {
            throw new \GraphQL\Error\Error('Forbidden');
        }

        $review->update([
            'rating' => array_key_exists('rating', $args) ? (int) $args['rating'] : $review->rating,
            'body' => array_key_exists('body', $args)
                ? (filled($args['body']) ? strip_tags((string) $args['body']) : null)
                : $review->body,
            'is_approved' => true,
        ]);

        $review->load('user');

        return $this->transform($review, $user);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    public function deleteReview(null $_, array $args): bool
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new \GraphQL\Error\Error('Unauthorized');
        }

        $review = Review::query()->findOrFail((int) $args['id']);

        if ((int) $review->user_id !== (int) $user->id) {
            throw new \GraphQL\Error\Error('Forbidden');
        }

        $review->delete();

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(Review $review, User $user): array
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
            'is_own' => (int) $review->user_id === (int) $user->id,
        ];
    }
}
