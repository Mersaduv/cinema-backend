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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
