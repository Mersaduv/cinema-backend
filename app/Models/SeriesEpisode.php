<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeriesEpisode extends Model
{
    protected $fillable = [
        'series_id',
        'series_season_id',
        'season_number',
        'episode_number',
        'title',
        'slug',
        'synopsis',
        'air_date',
        'duration_minutes',
        'still_url',
        'source_method',
        'video_url',
        'embed_url',
        'hls_url',
        'dash_url',
        'dash_verified',
        'subtitle_url',
        'dubbed_audio_url',
        'publish_status',
        'arrived_at',
        'sort_order',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'air_date' => 'date',
            'arrived_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(Series::class);
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(SeriesSeason::class, 'series_season_id');
    }
}
