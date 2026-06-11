<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Collection extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'tmdb_collection_id',
        'description',
        'poster_url',
        'backdrop_url',
        'metadata',
        'is_visible',
        'sort_order',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'is_visible' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function movies(): BelongsToMany
    {
        return $this->belongsToMany(Movie::class, 'collection_movie')
            ->withPivot(['sort_order'])
            ->withTimestamps()
            ->orderBy('collection_movie.sort_order')
            ->orderBy('movies.release_year')
            ->orderBy('movies.title');
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_visible', true);
    }
}
