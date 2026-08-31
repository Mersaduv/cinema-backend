<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Review extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'reviewable_type',
        'reviewable_id',
        'viewer_id',
        'rating',
        'body',
        'body_original',
        'status',
        'admin_note',
        'is_approved',
        'moderated_at',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'is_approved' => 'boolean',
            'moderated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Review $review): void {
            $review->is_approved = $review->status === self::STATUS_APPROVED;
        });
    }

    public function viewer(): BelongsTo
    {
        return $this->belongsTo(Viewer::class);
    }

    /** @deprecated Prefer viewer() — kept for transitional callers. */
    public function user(): BelongsTo
    {
        return $this->viewer();
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeForContent(Builder $query, string $type, int $id): Builder
    {
        return $query
            ->where('reviewable_type', $type)
            ->where('reviewable_id', $id);
    }
}
