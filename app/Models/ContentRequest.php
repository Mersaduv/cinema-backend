<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

class ContentRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_FULFILLED = 'fulfilled';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'viewer_id',
        'user_id',
        'title',
        'genre_slug',
        'genre_name',
        'description',
        'status',
        'admin_note',
        'moderated_at',
    ];

    protected function casts(): array
    {
        return [
            'moderated_at' => 'datetime',
        ];
    }

    /**
     * Older databases still have user_id. Querying viewer_id there is a 500.
     */
    public static function ownerColumn(): string
    {
        return Schema::hasColumn((new static)->getTable(), 'viewer_id')
            ? 'viewer_id'
            : 'user_id';
    }

    public function viewer(): BelongsTo
    {
        return $this->belongsTo(Viewer::class, static::ownerColumn());
    }

    /** @deprecated Prefer viewer() — kept for transitional callers. */
    public function user(): BelongsTo
    {
        return $this->viewer();
    }

    public function scopeForViewer(Builder $query, int $viewerId): Builder
    {
        return $query->where(static::ownerColumn(), $viewerId);
    }
}
