<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class WatchProgress extends Model
{
    protected $table = 'watch_progress';

    protected $fillable = [
        'user_id',
        'media_type',
        'media_id',
        'episode_key',
        'episode_id',
        'season_number',
        'episode_number',
        'current_time',
        'duration',
        'percent_watched',
        'is_completed',
        'platform',
        'device_id',
        'last_watched_at',
    ];

    protected function casts(): array
    {
        return [
            'media_id' => 'integer',
            'episode_id' => 'integer',
            'season_number' => 'integer',
            'episode_number' => 'integer',
            'current_time' => 'float',
            'duration' => 'integer',
            'percent_watched' => 'float',
            'is_completed' => 'boolean',
            'last_watched_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForMedia(
        Builder $query,
        string $mediaId,
        ?string $episodeId,
    ): Builder {
        $episodeKey = $episodeId ? 'ep_'.$episodeId : 'movie';

        return $query
            ->where('media_id', (int) $mediaId)
            ->where('episode_key', $episodeKey);
    }

    /** Progress tied to published, existing catalog rows only. */
    public function scopePlayable(Builder $query): Builder
    {
        return $query
            ->where(function (Builder $movieQuery) {
                $movieQuery
                    ->where('media_type', 'movie')
                    ->whereExists(function ($sub) {
                        $sub->select(DB::raw(1))
                            ->from('movies')
                            ->whereColumn('movies.id', 'watch_progress.media_id')
                            ->where('movies.publish_status', 'published');
                    });
            })
            ->orWhere(function (Builder $seriesQuery) {
                $seriesQuery
                    ->where('media_type', 'series')
                    ->whereExists(function ($sub) {
                        $sub->select(DB::raw(1))
                            ->from('series')
                            ->whereColumn('series.id', 'watch_progress.media_id')
                            ->where('series.publish_status', 'published');
                    })
                    ->where(function (Builder $episodeQuery) {
                        $episodeQuery
                            ->whereNull('episode_id')
                            ->orWhereExists(function ($sub) {
                                $sub->select(DB::raw(1))
                                    ->from('series_episodes')
                                    ->whereColumn('series_episodes.id', 'watch_progress.episode_id')
                                    ->whereColumn('series_episodes.series_id', 'watch_progress.media_id')
                                    ->where('series_episodes.publish_status', 'published');
                            });
                    });
            });
    }
}
