<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Genre extends Model
{
    protected $fillable = [
        'tmdb_id',
        'name',
        'slug',
        'type',
        'display_order',
    ];

    public function movies(): BelongsToMany
    {
        return $this->belongsToMany(Movie::class, 'movie_genre');
    }

    public function series(): BelongsToMany
    {
        return $this->belongsToMany(Series::class, 'series_genre');
    }
}
