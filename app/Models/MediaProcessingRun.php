<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaProcessingRun extends Model
{
    protected $fillable = [
        'media_asset_id',
        'created_by',
        'status',
        'progress_percent',
        'eta_seconds',
        'current_profile_key',
        'source_duration_seconds',
        'ffmpeg_command',
        'logs',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'progress_percent' => 'decimal:2',
            'eta_seconds' => 'integer',
            'source_duration_seconds' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
