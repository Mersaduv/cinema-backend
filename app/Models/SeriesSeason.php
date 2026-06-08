<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeriesSeason extends Model
{
    protected $fillable = [
        'series_id',
        'season_number',
        'name',
        'synopsis',
        'air_date',
        'episode_count',
        'poster_url',
        'sort_order',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'air_date' => 'date',
            'metadata' => 'array',
        ];
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(Series::class);
    }

    public function episodes(): HasMany
    {
        return $this->hasMany(SeriesEpisode::class)->orderBy('sort_order')->orderBy('episode_number');
    }
}
