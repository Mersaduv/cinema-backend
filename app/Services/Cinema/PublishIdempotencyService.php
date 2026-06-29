<?php

namespace App\Services\Cinema;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

final class PublishIdempotencyService
{
    private const TTL_SECONDS = 60;

    public function replayIfSeen(Request $request, string $scope): ?JsonResponse
    {
        $key = trim((string) $request->header('X-Idempotency-Key'));

        if ($key === '') {
            return null;
        }

        $cacheKey = sprintf('publish-idempotency:%s:%s', $scope, sha1($key));
        $cached = Cache::get($cacheKey);

        if (! is_array($cached) || ! isset($cached['status'], $cached['body'])) {
            return null;
        }

        return response()->json($cached['body'], (int) $cached['status']);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function remember(Request $request, string $scope, array $body, int $status = 200): void
    {
        $key = trim((string) $request->header('X-Idempotency-Key'));

        if ($key === '') {
            return;
        }

        $cacheKey = sprintf('publish-idempotency:%s:%s', $scope, sha1($key));

        Cache::put($cacheKey, [
            'status' => $status,
            'body' => $body,
        ], self::TTL_SECONDS);
    }
}
