<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentChangeLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'entity_type',
        'entity_id',
        'user_id',
        'action',
        'changed_fields',
        'old_values',
        'new_values',
        'ip_address',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'changed_fields' => 'array',
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
