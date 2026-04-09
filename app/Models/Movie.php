<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class Movie extends Model
{
    protected $fillable = [
        'title',
        'slug',
        'original_title',
        'synopsis',
        'release_year',
        'duration_seconds',
        'provider_name',
        'poster_path',
        'media_folder',
        'publish_status',
        'processing_status',
        'processing_error',
        'scheduled_for',
        'published_at',
        'metadata',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'published_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function mediaAsset(): MorphOne
    {
        return $this->morphOne(MediaAsset::class, 'mediable');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
