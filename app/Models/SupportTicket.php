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
        'user_id',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class)->orderBy('created_at');
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(SupportTicketMessage::class)->latestOfMany();
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
