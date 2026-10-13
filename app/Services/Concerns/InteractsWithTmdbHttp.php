<?php

namespace App\Services\Concerns;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

trait InteractsWithTmdbHttp
{
    private const TMDB_RAW_CACHE_TTL_SECONDS = 900;
    private const TMDB_HTTP_TIMEOUT_SECONDS = 20;
    private const TMDB_HTTP_RETRY_ATTEMPTS = 3;

    protected function tmdbRawCacheKey(string $path, array $query): string
    {
        ksort($query);

        return 'tmdb_raw_v1_'.md5($path.'|'.http_build_query($query));
    }

    /**
     * @return list<array{access_token: string, api_key: string}>
     */
    protected function tmdbCredentialSets(): array
    {
        $sets = [];
        $seen = [];

        foreach ([
            [
                'access_token' => trim((string) config('services.tmdb.access_token')),
                'api_key' => trim((string) config('services.tmdb.api_key')),
            ],
            [
                'access_token' => trim((string) config('services.tmdb.access_token_fallback')),
                'api_key' => trim((string) config('services.tmdb.api_key_fallback')),
            ],
        ] as $set) {
            if ($set['access_token'] === '' && $set['api_key'] === '') {
                continue;
            }

            $fingerprint = $set['access_token'].'|'.$set['api_key'];
            if (isset($seen[$fingerprint])) {
                continue;
            }

            $seen[$fingerprint] = true;
            $sets[] = $set;
        }

        return $sets;
    }

    /**
     * @return array<string, mixed>
     */
    protected function requestCached(string $path, array $query = []): array
    {
        $cacheKey = $this->tmdbRawCacheKey($path, $query);

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $body = $this->request($path, $query);
        Cache::put($cacheKey, $body, self::TMDB_RAW_CACHE_TTL_SECONDS);

        return $body;
    }

    /**
     * @param  array<string, mixed>  $extraQuery
     * @return array{primary: array<string, mixed>, fallback: array<string, mixed>|null}
     */
    protected function fetchPrimaryAndEnglishParallel(string $path, string $language, array $extraQuery = []): array
    {
        if (strtolower($language) === 'en-us') {
            return [
                'primary' => $this->requestCached($path, array_merge($extraQuery, ['language' => $language])),
                'fallback' => null,
            ];
        }

        $responses = $this->requestParallelKeyed([
            'primary' => [$path, array_merge($extraQuery, ['language' => $language])],
            'en' => [$path, array_merge($extraQuery, ['language' => 'en-US'])],
        ]);

        $fallback = $responses['en'] ?? null;

        return [
            'primary' => $responses['primary'] ?? [],
            'fallback' => is_array($fallback) && $fallback !== [] ? $fallback : null,
        ];
    }

    /**
     * @param  array<string, array{0: string, 1: array<string, mixed>}>  $keyedRequests
     * @return array<string, array<string, mixed>>
     */
    protected function requestParallelKeyed(array $keyedRequests): array
    {
        $results = [];
        $pending = [];

        foreach ($keyedRequests as $key => [$path, $query]) {
            $cacheKey = $this->tmdbRawCacheKey($path, $query);
            $cached = Cache::get($cacheKey);

            if (is_array($cached)) {
                $results[$key] = $cached;

                continue;
            }

            $pending[$key] = [$path, $query, $cacheKey];
        }

        if ($pending === []) {
            return $results;
        }

        $credentialSets = $this->tmdbCredentialSets();

        if ($credentialSets === []) {
            throw new RuntimeException('TMDB credentials are not configured.');
        }

        for ($attempt = 1; $attempt <= self::TMDB_HTTP_RETRY_ATTEMPTS; $attempt++) {
            if ($pending === []) {
                break;
            }

            $creds = $credentialSets[($attempt - 1) % count($credentialSets)];
            $accessToken = $creds['access_token'];
            $apiKey = $creds['api_key'];
            $baseUrl = rtrim((string) config('services.tmdb.base_url'), '/');

            $responses = Http::pool(function (Pool $pool) use ($pending, $baseUrl, $accessToken, $apiKey) {
                foreach ($pending as $key => [$path, $query]) {
                    $url = $baseUrl.$path;
                    $request = $this->configureHttpClient(
                        $pool->as((string) $key)->acceptJson()->timeout(self::TMDB_HTTP_TIMEOUT_SECONDS)
                    );

                    if ($accessToken !== '') {
                        $request->withToken($accessToken)->get($url, $query);
                    } else {
                        $request->get($url, array_merge($query, ['api_key' => $apiKey]));
                    }
                }
            });

            $nextPending = [];

            foreach ($pending as $key => [$path, $query, $cacheKey]) {
                $response = $responses[(string) $key] ?? null;

                if (! $response instanceof Response || ! $response->successful()) {
                    if ($attempt < self::TMDB_HTTP_RETRY_ATTEMPTS) {
                        $nextPending[$key] = [$path, $query, $cacheKey];
                    } else {
                        $results[$key] = [];
                    }

                    continue;
                }

                $body = $response->json() ?? [];

                if ($body !== []) {
                    Cache::put($cacheKey, $body, self::TMDB_RAW_CACHE_TTL_SECONDS);
                }

                $results[$key] = $body;
            }

            $pending = $nextPending;

            if ($pending !== [] && $attempt < self::TMDB_HTTP_RETRY_ATTEMPTS) {
                usleep(250_000 * $attempt);
            }
        }

        return $results;
    }

    public function forgetTmdbCacheForId(int $tmdbId): void
    {
        // Best-effort: flush is expensive; callers can use artisan tmdb:clear-cache {id} for targeted keys.
        // Store a generation counter pattern if finer invalidation is needed later.
        Cache::forget('tmdb_movie_'.$tmdbId.'_fa-IR_v1');
        Cache::forget('tmdb_tv_'.$tmdbId.'_fa-IR_v1');
    }
}
