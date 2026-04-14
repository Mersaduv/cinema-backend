<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
        'trailer_path',
        'has_dubbed_audio',
        'dubbed_source_path',
        'audio_tracks',
        'has_subtitle',
        'subtitle_source_path',
        'age_rating',
        'cast_members',
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
            'cast_members' => 'array',
            'audio_tracks' => 'array',
            'has_dubbed_audio' => 'boolean',
            'has_subtitle' => 'boolean',
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

    public function genres(): BelongsToMany
    {
        return $this->belongsToMany(Genre::class);
    }

    public function countries(): BelongsToMany
    {
        return $this->belongsToMany(Country::class);
    }

    public function languages(): BelongsToMany
    {
        return $this->belongsToMany(Language::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    public function actors(): BelongsToMany
    {
        return $this->belongsToMany(Actor::class);
    }
}
