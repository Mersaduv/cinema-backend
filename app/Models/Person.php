<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Person extends Model
{
    protected $table = 'persons';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'birthday' => 'date',
            'deathday' => 'date',
        ];
    }

    public function movieCast(): HasMany
    {
        return $this->hasMany(MovieCast::class);
    }

    public function movieCrew(): HasMany
    {
        return $this->hasMany(MovieCrew::class);
    }

    public function seriesCast(): HasMany
    {
        return $this->hasMany(SeriesCast::class);
    }

    public function seriesCrew(): HasMany
    {
        return $this->hasMany(SeriesCrew::class);
    }

    public function movies(): BelongsToMany
    {
        return $this->belongsToMany(Movie::class, 'movie_cast')
            ->withPivot(['tmdb_credit_id', 'character_name', 'cast_order'])
            ->withTimestamps();
    }

    public function series(): BelongsToMany
    {
        return $this->belongsToMany(Series::class, 'series_cast', 'person_id', 'series_id')
            ->withPivot(['tmdb_credit_id', 'character_name', 'episode_count', 'cast_order'])
            ->withTimestamps();
    }

    public function scopeActors(Builder $query): Builder
    {
        return $query->where('known_for_department', 'Acting');
    }

    public function scopeDirectors(Builder $query): Builder
    {
        return $query->where('known_for_department', 'Directing');
    }

    public function getProfileImageUrl(): ?string
    {
        if (filled($this->profile_url)) {
            return $this->profile_url;
        }

        if (filled($this->profile_path_tmdb)) {
            return rtrim((string) config('services.tmdb.image_base_url'), '/') . '/w500/' . ltrim((string) $this->profile_path_tmdb, '/');
        }

        return null;
    }
}
