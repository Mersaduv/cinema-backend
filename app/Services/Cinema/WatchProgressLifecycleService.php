<?php

namespace App\Services\Cinema;

use App\Models\Movie;
use App\Models\Series;
use App\Models\SeriesEpisode;
use App\Models\WatchProgress;
use Illuminate\Support\Facades\DB;

class WatchProgressLifecycleService
{
    public function purgeForMovie(int $movieId): int
    {
        if ($movieId <= 0) {
            return 0;
        }

        return WatchProgress::query()
            ->where('media_type', 'movie')
            ->where('media_id', $movieId)
            ->delete();
    }

    public function purgeForSeries(int $seriesId): int
    {
        if ($seriesId <= 0) {
            return 0;
        }

        return WatchProgress::query()
            ->where('media_type', 'series')
            ->where('media_id', $seriesId)
            ->delete();
    }

    public function purgeForEpisode(int $seriesId, int $episodeId): int
    {
        if ($seriesId <= 0 || $episodeId <= 0) {
            return 0;
        }

        return WatchProgress::query()
            ->where('media_type', 'series')
            ->where('media_id', $seriesId)
            ->where('episode_id', $episodeId)
            ->delete();
    }

    /**
     * @param  iterable<int>  $episodeIds
     */
    public function purgeForEpisodes(int $seriesId, iterable $episodeIds): int
    {
        $ids = collect($episodeIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($seriesId <= 0 || $ids === []) {
            return 0;
        }

        return WatchProgress::query()
            ->where('media_type', 'series')
            ->where('media_id', $seriesId)
            ->whereIn('episode_id', $ids)
            ->delete();
    }

    public function purgeInvalidMediaIds(): int
    {
        return WatchProgress::query()
            ->where('media_id', '<=', 0)
            ->delete();
    }

    /** Remove progress rows whose movie/series/episode no longer exists. */
    public function purgeOrphans(): int
    {
        $removed = 0;

        $removed += WatchProgress::query()
            ->where('media_type', 'movie')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('movies')
                    ->whereColumn('movies.id', 'watch_progress.media_id');
            })
            ->delete();

        $removed += WatchProgress::query()
            ->where('media_type', 'series')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('series')
                    ->whereColumn('series.id', 'watch_progress.media_id');
            })
            ->delete();

        $removed += WatchProgress::query()
            ->where('media_type', 'series')
            ->whereNotNull('episode_id')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('series_episodes')
                    ->whereColumn('series_episodes.id', 'watch_progress.episode_id');
            })
            ->delete();

        return $removed;
    }

    /** Remove progress for unpublished or missing playback targets. */
    public function purgeUnplayable(): int
    {
        $removed = 0;

        $removed += WatchProgress::query()
            ->where('media_type', 'movie')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('movies')
                    ->whereColumn('movies.id', 'watch_progress.media_id')
                    ->where('movies.publish_status', 'published');
            })
            ->delete();

        $removed += WatchProgress::query()
            ->where('media_type', 'series')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('series')
                    ->whereColumn('series.id', 'watch_progress.media_id')
                    ->where('series.publish_status', 'published');
            })
            ->delete();

        $removed += WatchProgress::query()
            ->where('media_type', 'series')
            ->whereNotNull('episode_id')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('series_episodes')
                    ->whereColumn('series_episodes.id', 'watch_progress.episode_id')
                    ->where('series_episodes.publish_status', 'published');
            })
            ->delete();

        return $removed;
    }

    public function handleMovieUpdated(Movie $movie): void
    {
        if ($this->moviePlaybackIdentityChanged($movie)) {
            $this->purgeForMovie($movie->id);
        }
    }

    public function handleSeriesUpdated(Series $series): void
    {
        if ($series->wasChanged(['slug', 'publish_status'])) {
            if ($series->publish_status !== 'published') {
                $this->purgeForSeries($series->id);
            }
        }
    }

    public function handleEpisodeUpdated(SeriesEpisode $episode): void
    {
        if ($episode->wasChanged(['season_number', 'episode_number'])) {
            $this->syncEpisodeIdentity($episode);
        }

        if ($episode->wasChanged(['hls_url', 'dash_url', 'duration_minutes', 'publish_status'])) {
            if ($episode->publish_status !== 'published') {
                $this->purgeForEpisode((int) $episode->series_id, (int) $episode->id);

                return;
            }

            if ($episode->wasChanged(['hls_url', 'dash_url', 'duration_minutes'])) {
                $this->resetEpisodePlayback($episode);
            }
        }
    }

    public function syncEpisodeIdentity(SeriesEpisode $episode): void
    {
        $episodeKey = 'ep_'.$episode->id;

        WatchProgress::query()
            ->where('media_type', 'series')
            ->where('media_id', (int) $episode->series_id)
            ->where('episode_id', (int) $episode->id)
            ->update([
                'episode_key' => $episodeKey,
                'season_number' => (int) $episode->season_number,
                'episode_number' => (int) $episode->episode_number,
            ]);
    }

    public function resetEpisodePlayback(SeriesEpisode $episode): void
    {
        $updates = [
            'current_time' => 0,
            'percent_watched' => 0,
            'is_completed' => false,
        ];

        if ($episode->duration_minutes !== null) {
            $updates['duration'] = (int) $episode->duration_minutes * 60;
        }

        WatchProgress::query()
            ->where('media_type', 'series')
            ->where('media_id', (int) $episode->series_id)
            ->where('episode_id', (int) $episode->id)
            ->update($updates);
    }

    public function assertMediaIsTrackable(string $mediaType, int $mediaId, ?int $episodeId = null): bool
    {
        if ($mediaId <= 0) {
            return false;
        }

        if ($mediaType === 'movie') {
            return Movie::query()
                ->whereKey($mediaId)
                ->where('publish_status', 'published')
                ->exists();
        }

        if ($mediaType !== 'series') {
            return false;
        }

        $seriesExists = Series::query()
            ->whereKey($mediaId)
            ->where('publish_status', 'published')
            ->exists();

        if (! $seriesExists) {
            return false;
        }

        if ($episodeId === null) {
            return true;
        }

        return SeriesEpisode::query()
            ->whereKey($episodeId)
            ->where('series_id', $mediaId)
            ->where('publish_status', 'published')
            ->exists();
    }

    private function moviePlaybackIdentityChanged(Movie $movie): bool
    {
        if ($movie->wasChanged(['slug', 'duration_seconds', 'publish_status'])) {
            if ($movie->publish_status !== 'published') {
                return true;
            }

            if ($movie->wasChanged(['slug', 'duration_seconds'])) {
                return true;
            }
        }

        if ($movie->wasChanged('metadata')) {
            $original = $movie->getOriginal('metadata');
            $originalPlayback = is_array($original) ? data_get($original, 'playback') : [];
            $currentPlayback = is_array($movie->metadata) ? data_get($movie->metadata, 'playback') : [];

            $playbackKeys = ['hls_url', 'dash_url', 'dash_verified'];

            foreach ($playbackKeys as $key) {
                if (data_get($originalPlayback, $key) !== data_get($currentPlayback, $key)) {
                    return true;
                }
            }
        }

        return false;
    }
}
