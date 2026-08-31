<?php

namespace App\Http\Middleware;

use App\Models\Viewer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureViewer
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof Viewer) {
            return response()->json([
                'success' => false,
                'message' => 'این بخش فقط برای بینندگان است.',
            ], 403);
        }

        if (! $user->isActive()) {
            return response()->json([
                'success' => false,
                'message' => 'Your account has been banned',
            ], 403);
        }

        return $next($request);
    }
}
