<?php

namespace App\Http\Middleware;

use App\Services\Cinema\PublishScheduleService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class PublishScheduledContentMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            // Use the file store so concurrent API traffic never deadlocks the
            // MySQL `cache` table and strip CORS headers via a 500 response.
            $acquired = Cache::store('file')->add(
                'cinema:publish-scheduled:last-run',
                now()->timestamp,
                30,
            );

            if ($acquired) {
                app(PublishScheduleService::class)->publishDueContent();
            }
        } catch (Throwable $exception) {
            Log::warning('PublishScheduledContentMiddleware skipped', [
                'message' => $exception->getMessage(),
            ]);
        }

        return $next($request);
    }
}
