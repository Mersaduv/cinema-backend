<?php

namespace App\Http\Controllers;

use App\Services\Cinema\HomeCriticalAssemblyService;
use App\Services\Cinema\LatestCinemaEpisodesService;
use App\Services\Cinema\PublicContentCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class PublicHomeController extends Controller
{
    public function __construct(
        private readonly HomeCriticalAssemblyService $homeCriticalAssembly,
        private readonly PublicContentCacheService $contentCacheService,
        private readonly LatestCinemaEpisodesService $latestCinemaEpisodes,
    ) {
    }

    public function critical(): JsonResponse
    {
        $cacheKey = $this->contentCacheService->homeCriticalKey();
        $ttl = $this->contentCacheService->homeCriticalTtl();

        $payload = Cache::remember($cacheKey, $ttl, function () {
            return $this->homeCriticalAssembly->assemble();
        });

        return response()
            ->json([
                'success' => true,
                'data' => $payload,
            ])
            ->withHeaders($this->contentCacheService->detailCacheHeaders($ttl));
    }

    public function latestEpisodes(Request $request): JsonResponse
    {
        $limit = min(max((int) $request->integer('limit', 12), 1), 20);
        $cacheKey = 'home_latest_episodes_v4_'.$limit.'_g'.$this->contentCacheService->homeCriticalKey();
        $ttl = min(180, $this->contentCacheService->homeCriticalTtl());

        $items = Cache::remember($cacheKey, $ttl, function () use ($limit) {
            return $this->latestCinemaEpisodes->list($limit);
        });

        return response()
            ->json([
                'success' => true,
                'data' => [
                    'items' => $items,
                ],
            ])
            ->withHeaders($this->contentCacheService->detailCacheHeaders($ttl));
    }
}
