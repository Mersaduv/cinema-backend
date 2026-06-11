<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Series extends Model
{
    protected $table = 'series';

    protected $fillable = [
        'tmdb_id',
        'title',
        'slug',
        'original_title',
        'synopsis',
        'first_air_date',
        'last_air_date',
        'release_year',
        'origin_country',
        'original_language',
        'content_rating',
        'filter_rating',
        'cached_avg_rating',
        'filter_primary_genre',
        'filter_primary_country',
        'filter_primary_network',
        'status',
        'show_type',
        'episode_runtime_minutes',
        'number_of_seasons',
        'number_of_episodes',
        'has_subtitle',
        'has_dubbed',
        'network',
        'provider_name',
        'poster_url',
        'backdrop_url',
        'pipeline_status',
        'trailer_url',
        'age_rating',
        'catalog_visibility',
        'is_featured',
        'publish_status',
        'scheduled_for',
        'published_at',
        'metadata',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'first_air_date' => 'date',
            'last_air_date' => 'date',
            'scheduled_for' => 'datetime',
            'published_at' => 'datetime',
            'metadata' => 'array',
            'is_featured' => 'boolean',
            'has_subtitle' => 'boolean',
            'has_dubbed' => 'boolean',
        ];
    }

    public function seasons(): HasMany
    {
        return $this->hasMany(SeriesSeason::class)->orderBy('sort_order')->orderBy('season_number');
    }

    public function episodes(): HasMany
    {
        return $this->hasMany(SeriesEpisode::class)->orderBy('season_number')->orderBy('episode_number');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function castMembers(): HasMany
    {
        return $this->hasMany(SeriesCast::class)->orderBy('cast_order');
    }

    public function crewMembers(): HasMany
    {
        return $this->hasMany(SeriesCrew::class);
    }

    public function persons(): BelongsToMany
    {
        return $this->belongsToMany(Person::class, 'series_cast')
            ->withPivot(['tmdb_credit_id', 'character_name', 'episode_count', 'cast_order'])
            ->withTimestamps();
    }

    public function genres(): BelongsToMany
    {
        return $this->belongsToMany(Genre::class, 'series_genre');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class, 'reviewable_id')
            ->where('reviewable_type', 'series');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('publish_status', 'published')
            ->latest('published_at');
    }
}
