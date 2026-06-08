<?php

namespace App\Http\Middleware;

use App\Services\Cinema\PublishScheduleService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class PublishScheduledContentMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Cache::add('cinema:publish-scheduled:last-run', now()->timestamp, 30)) {
            app(PublishScheduleService::class)->publishDueContent();
        }

        return $next($request);
    }
}
