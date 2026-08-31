<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_FULFILLED = 'fulfilled';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'viewer_id',
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

    public function viewer(): BelongsTo
    {
        return $this->belongsTo(Viewer::class);
    }

    /** @deprecated Prefer viewer() — kept for transitional callers. */
    public function user(): BelongsTo
    {
        return $this->viewer();
    }

    public function scopeForViewer(Builder $query, int $viewerId): Builder
    {
        return $query->where('viewer_id', $viewerId);
    }
}
