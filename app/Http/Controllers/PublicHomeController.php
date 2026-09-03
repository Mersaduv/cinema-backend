<?php

namespace App\Http\Controllers;

use App\Services\Cinema\HomeCriticalAssemblyService;
use App\Services\Cinema\PublicContentCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class PublicHomeController extends Controller
{
    public function __construct(
        private readonly HomeCriticalAssemblyService $homeCriticalAssembly,
        private readonly PublicContentCacheService $contentCacheService,
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
}
