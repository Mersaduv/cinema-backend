<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class Movie extends Model
{
    protected $fillable = [
        'tmdb_id',
        'title',
        'slug',
        'original_title',
        'synopsis',
        'release_year',
        'origin_country',
        'original_language',
        'content_rating',
        'filter_rating',
        'cached_avg_rating',
        'filter_primary_genre',
        'filter_primary_country',
        'filter_primary_network',
        'duration_seconds',
        'provider_name',
        'poster_path',
        'trailer_path',
        'has_dubbed_audio',
        'drm_scheme',
        'drm_license_url',
        'dubbed_source_path',
        'audio_tracks',
        'has_subtitle',
        'subtitle_source_path',
        'age_rating',
        'cast_members',
        'media_folder',
        'publish_status',
        'processing_status',
        'pipeline_status',
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

    public function castMembers(): HasMany
    {
        return $this->hasMany(MovieCast::class)->orderBy('cast_order');
    }

    public function crewMembers(): HasMany
    {
        return $this->hasMany(MovieCrew::class);
    }

    public function persons(): BelongsToMany
    {
        return $this->belongsToMany(Person::class, 'movie_cast')
            ->withPivot(['tmdb_credit_id', 'character_name', 'cast_order'])
            ->withTimestamps();
    }

    public function genres(): BelongsToMany
    {
        return $this->belongsToMany(Genre::class, 'movie_genre');
    }

    public function collections(): BelongsToMany
    {
        return $this->belongsToMany(Collection::class, 'collection_movie')
            ->withPivot(['sort_order'])
            ->withTimestamps();
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class, 'reviewable_id')
            ->where('reviewable_type', 'movie');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('publish_status', 'published')
            ->latest('published_at');
    }
}
