<?php

namespace App\Services\Cinema;

use App\Jobs\RebuildCatalogCacheJob;
use App\Jobs\RevalidateNextCatalogJob;
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

    /**
     * Stable cache fingerprint for catalog list requests.
     * Includes carousel mode, cursor, page size, and all filter params.
     */
    public function catalogListFingerprint(\Illuminate\Http\Request $request): string
    {
        $params = $request->query();
        ksort($params);

        $normalized = http_build_query($params);

        return md5($normalized);
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
        Cache::forget('filter_options_v2');
        Cache::forget(AdminReferenceOptionsService::CACHE_KEY);
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

    /**
     * @param  array<int, string|null>  $nextPaths
     */
    public function scheduleRebuildAfterContentChange(array $nextPaths = []): void
    {
        // Generation bump is enough to invalidate generation-keyed list caches.
        // Avoid synchronous LIKE deletes + Next HTTP during admin save.
        $this->bumpCatalogGeneration();
        Cache::forget('admin_movie_summary_v1');
        Cache::forget('admin_series_summary_v1');
        Cache::forget('filter_options');
        Cache::forget('filter_options_v2');

        $paths = array_values(array_filter(
            $nextPaths,
            fn ($path) => is_string($path) && filled($path),
        ));

        // Real queue — NOT afterResponse (Laravel afterResponse = dispatchSync on terminate,
        // which blocks IIS/php-cgi until images/TMDB/revalidate finish).
        RebuildCatalogCacheJob::dispatch(warm: true);
        RevalidateNextCatalogJob::dispatch($paths);
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
