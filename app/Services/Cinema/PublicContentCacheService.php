<?php

namespace App\Services\Cinema;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PublicContentCacheService
{
    public function detailTtl(): int
    {
        return max(60, (int) config('cinema.cache.detail_ttl', 300));
    }

    public function watchTtl(): int
    {
        return max(30, (int) config('cinema.cache.watch_ttl', 120));
    }

    public function relatedTtl(): int
    {
        return max(60, (int) config('cinema.cache.related_ttl', 3600));
    }

    public function homeCriticalTtl(): int
    {
        return max(60, (int) config('cinema.cache.home_critical_ttl', 300));
    }

    public function movieDetailKey(string $slug): string
    {
        return 'public_movie_detail_v1_'.$slug;
    }

    public function seriesDetailKey(string $slug, bool $lite = false): string
    {
        return 'public_series_detail_v1_'.$slug.($lite ? '_lite' : '');
    }

    public function seriesGraphqlOverviewKey(string $slug, string $selectionFingerprint): string
    {
        return 'gql_series_overview_v1_'.$slug.'_'.$selectionFingerprint;
    }

    public function seriesEpisodesKey(string $slug): string
    {
        return 'public_series_episodes_v1_'.$slug;
    }

    public function watchMovieKey(string $slug): string
    {
        return 'watch_movie_v1_'.$slug;
    }

    public function watchEpisodeKey(string $seriesSlug, int $season, int $episode): string
    {
        return 'watch_episode_v1_'.$seriesSlug.'_'.$season.'_'.$episode;
    }

    public function relatedMoviesKey(int $movieId): string
    {
        return 'related_movies_v1_'.$movieId;
    }

    public function relatedSeriesKey(int $seriesId): string
    {
        return 'related_series_v1_'.$seriesId;
    }

    public function homeCriticalKey(): string
    {
        return 'home_critical_v1_g'.app(CatalogCacheService::class)->catalogGeneration();
    }

    public function detailCacheHeaders(int $ttl): array
    {
        return [
            'Cache-Control' => 'public, max-age='.$ttl.', stale-while-revalidate=60',
            'Vary' => 'Accept-Encoding',
        ];
    }

    public function forgetMovie(string $slug, int $movieId): void
    {
        Cache::forget($this->movieDetailKey($slug));
        Cache::forget($this->watchMovieKey($slug));
        Cache::forget($this->relatedMoviesKey($movieId));
    }

    public function forgetSeries(string $slug, int $seriesId): void
    {
        Cache::forget($this->seriesDetailKey($slug, false));
        Cache::forget($this->seriesDetailKey($slug, true));
        Cache::forget($this->seriesEpisodesKey($slug));
        Cache::forget($this->relatedSeriesKey($seriesId));
        // Pattern deletes (gql_/watch_episode_) are deferred to the catalog rebuild job
        // so admin save is not blocked by database LIKE scans.
    }

    public function forgetHomeCritical(): void
    {
        $this->forgetByPattern('home_critical_v1_g%');
    }

    private function forgetByPattern(string $pattern): void
    {
        $store = (string) config('cache.default');

        if ($store === 'redis') {
            try {
                $prefix = (string) config('cache.prefix', '');
                $keys = \Illuminate\Support\Facades\Redis::connection('cache')->keys($prefix.$pattern);

                if ($keys !== []) {
                    \Illuminate\Support\Facades\Redis::connection('cache')->del(...$keys);
                }
            } catch (\Throwable) {
                // Expire via TTL when redis is unavailable.
            }

            return;
        }

        if ($store === 'database') {
            $prefix = (string) config('cache.prefix', '');
            $table = (string) config('cache.stores.database.table', 'cache');
            $likePattern = '%'.$this->escapeLikePattern($prefix.$pattern).'%';

            DB::table($table)
                ->where('key', 'like', $likePattern)
                ->delete();
        }
    }

    private function escapeLikePattern(string $pattern): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $pattern);
    }
}
