<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  ...$permissions
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        if (!$request->user()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        foreach ($permissions as $permission) {
            if (!$request->user()->hasPermission($permission)) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have the required permission: ' . $permission,
                ], 403);
            }
        }

        return $next($request);
    }
}
