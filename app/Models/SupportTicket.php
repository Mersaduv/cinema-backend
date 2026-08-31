<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SupportTicket extends Model
{
    public const STATUS_WAITING = 'waiting';

    public const STATUS_ANSWERED = 'answered';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'viewer_id',
        'category',
        'category_label',
        'description',
        'image_path',
        'image_url',
        'status',
        'admin_reply',
        'answered_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'answered_at' => 'datetime',
            'closed_at' => 'datetime',
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

    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class)->orderBy('created_at');
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(SupportTicketMessage::class)->latestOfMany();
    }

    public function scopeForViewer(Builder $query, int $viewerId): Builder
    {
        return $query->where('viewer_id', $viewerId);
    }
}
