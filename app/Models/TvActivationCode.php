<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TvActivationCode extends Model
{
    protected $fillable = [
        'code',
        'viewer_id',
        'personal_access_token_id',
        'device_label',
        'expires_at',
        'activated_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'activated_at' => 'datetime',
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

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isActivated(): bool
    {
        return $this->activated_at !== null && $this->personal_access_token_id !== null;
    }
}
