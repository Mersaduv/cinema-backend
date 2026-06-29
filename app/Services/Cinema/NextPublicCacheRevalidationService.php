<?php

namespace App\Services\Cinema;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NextPublicCacheRevalidationService
{
    /**
     * @param  array<int, string|null>  $extraPaths
     */
    public function revalidateCatalog(array $extraPaths = []): void
    {
        $paths = array_values(array_unique(array_filter(array_merge([
            '/',
            '/movies',
            '/series',
            '/collections',
            '/animation',
            '/search',
        ], $this->normalizePaths($extraPaths)))));

        $this->dispatch($paths, self::homePageTags());
    }

    public function revalidateMovie(?string $slug, ?string $previousSlug = null): void
    {
        $paths = ['/', '/movies'];

        foreach ([$slug, $previousSlug] as $candidate) {
            if (filled($candidate)) {
                $paths[] = '/movies/'.ltrim((string) $candidate, '/');
            }
        }

        $this->dispatch($paths, [...self::homePageTags(), 'catalog-movies']);
    }

    public function revalidateSeries(?string $slug, ?string $previousSlug = null): void
    {
        $paths = ['/', '/series'];

        foreach ([$slug, $previousSlug] as $candidate) {
            if (filled($candidate)) {
                $paths[] = '/series/'.ltrim((string) $candidate, '/');
            }
        }

        $this->dispatch($paths, [...self::homePageTags(), 'catalog-series']);
    }

    /**
     * Tags must match Next.js `"use cache"` entries on `/` (home-critical.ts, home-deferred.ts).
     *
     * @return array<int, string>
     */
    private static function homePageTags(): array
    {
        return [
            'home-critical',
            'home-rails',
            'catalog',
            'catalog-movies',
            'catalog-series',
        ];
    }

    /**
     * @param  array<int, string>  $paths
     * @param  array<int, string>  $tags
     */
    private function dispatch(array $paths, array $tags): void
    {
        $url = env('NEXT_REVALIDATION_URL');
        $secret = env('NEXT_REVALIDATION_SECRET');

        if (! filled($url) || ! filled($secret)) {
            return;
        }

        $endpoint = rtrim((string) $url, '/').'/api/revalidate';

        try {
            foreach (array_unique($tags) as $tag) {
                Http::timeout(5)->post($endpoint, [
                    'secret' => $secret,
                    'tag' => $tag,
                ]);
            }

            foreach (array_unique($paths) as $path) {
                Http::timeout(5)->post($endpoint, [
                    'secret' => $secret,
                    'path' => $path,
                ]);
            }
        } catch (\Throwable $exception) {
            Log::warning('Next.js cache revalidation failed', [
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<int, mixed>  $paths
     * @return array<int, string>
     */
    private function normalizePaths(array $paths): array
    {
        return collect($paths)
            ->filter(fn ($path) => is_string($path) && filled(trim($path)))
            ->map(fn (string $path) => str_starts_with($path, '/') ? $path : '/'.$path)
            ->values()
            ->all();
    }
}
