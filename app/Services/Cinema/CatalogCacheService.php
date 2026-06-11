<?php

namespace App\Services\Cinema;

use App\Jobs\RebuildCatalogCacheJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class CatalogCacheService
{
    private const GENERATION_KEY = 'catalog:list:generation';

    public function catalogGeneration(): int
    {
        return max(1, (int) Cache::get(self::GENERATION_KEY, 1));
    }

    public function listCacheKey(string $namespace, string $fingerprint): string
    {
        return $namespace.'_g'.$this->catalogGeneration().'_'.$fingerprint;
    }

    public function bumpCatalogGeneration(): int
    {
        $next = $this->catalogGeneration() + 1;
        Cache::forever(self::GENERATION_KEY, $next);

        return $next;
    }

    public function forgetAdminMoviesList(): void
    {
        $this->forgetByPattern('admin_movies_list_*');
        Cache::forget('admin_movie_summary_v1');
    }

    public function forgetAdminSeriesList(): void
    {
        $this->forgetByPattern('admin_series_list_*');
        Cache::forget('admin_series_summary_v1');
    }

    public function forgetPublicMoviesCatalog(): void
    {
        $this->forgetByPattern('movies_list_*');
        $this->forgetByPattern('movies_catalog_*');
    }

    public function forgetPublicSeriesCatalog(): void
    {
        $this->forgetByPattern('series_list_*');
        $this->forgetByPattern('series_catalog_*');
    }

    public function forgetPublicCollectionsList(): void
    {
        $this->forgetByPattern('collections_list_*');
    }

    public function forgetSharedReferenceData(): void
    {
        Cache::forget('filter_options');
        Cache::forget('admin_reference_options_v1');
    }

    public function forgetAllCatalogLists(): void
    {
        $this->forgetAdminMoviesList();
        $this->forgetAdminSeriesList();
        $this->forgetPublicMoviesCatalog();
        $this->forgetPublicSeriesCatalog();
        $this->forgetPublicCollectionsList();
        $this->forgetSharedReferenceData();
    }

    public function invalidateImmediately(): void
    {
        $this->bumpCatalogGeneration();
        $this->forgetAllCatalogLists();
    }

    public function scheduleRebuildAfterContentChange(): void
    {
        $this->invalidateImmediately();
        RebuildCatalogCacheJob::dispatch(warm: true)->afterResponse();
    }

    private function forgetByPattern(string $pattern): void
    {
        $store = (string) config('cache.default');

        if ($store === 'redis') {
            $this->forgetRedisKeysByPattern($pattern);

            return;
        }

        if ($store === 'database') {
            $this->forgetDatabaseKeysByPattern($pattern);
        }
    }

    private function forgetRedisKeysByPattern(string $pattern): void
    {
        try {
            $prefix = (string) config('cache.prefix', '');
            $keys = Redis::connection('cache')->keys($prefix.$pattern);

            if ($keys !== []) {
                Redis::connection('cache')->del(...$keys);
            }
        } catch (\Throwable) {
            // Non-redis or unavailable — list entries expire via TTL.
        }
    }

    private function forgetDatabaseKeysByPattern(string $pattern): void
    {
        $prefix = (string) config('cache.prefix', '');
        $table = (string) config('cache.stores.database.table', 'cache');
        $likePattern = '%'.$this->escapeLikePattern($prefix.$pattern).'%';

        DB::table($table)
            ->where('key', 'like', $likePattern)
            ->where('key', 'not like', '%'.self::GENERATION_KEY.'%')
            ->delete();
    }

    private function escapeLikePattern(string $pattern): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $pattern);
    }
}
