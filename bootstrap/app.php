<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(append: [
            \App\Http\Middleware\PublishScheduledContentMiddleware::class,
        ]);

        $middleware->alias([
            'role' => \App\Http\Middleware\CheckRole::class,
            'permission' => \App\Http\Middleware\CheckPermission::class,
            'viewer' => \App\Http\Middleware\EnsureViewer::class,
            'staff' => \App\Http\Middleware\EnsureStaffUser::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Illuminate\Http\Exceptions\PostTooLargeException $exception, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'حجم درخواست از محدودیت سرور بیشتر است. حداکثر مجاز آپلود تصویر ۱۰ مگابایت است.',
                ], 413);
            }

            return null;
        });

        // When an exception escapes middleware, HandleCors never adds headers and
        // the browser reports a misleading CORS failure instead of the real error.
        $exceptions->respond(function ($response, \Throwable $exception, $request) {
            if (! $request instanceof \Illuminate\Http\Request) {
                return $response;
            }

            $origin = (string) $request->headers->get('Origin', '');

            if ($origin === '' || $response->headers->has('Access-Control-Allow-Origin')) {
                return $response;
            }

            $allowed = config('cors.allowed_origins', ['*']);
            $allowAny = in_array('*', $allowed, true);
            $patterns = config('cors.allowed_origins_patterns', []);

            $originAllowed = $allowAny
                || in_array($origin, $allowed, true)
                || collect($patterns)->contains(fn ($pattern) => @preg_match($pattern, $origin) === 1);

            if (! $originAllowed) {
                return $response;
            }

            $response->headers->set(
                'Access-Control-Allow-Origin',
                $allowAny ? '*' : $origin,
            );
            $response->headers->set(
                'Access-Control-Allow-Methods',
                'GET, POST, PUT, PATCH, DELETE, OPTIONS',
            );
            $response->headers->set(
                'Access-Control-Allow-Headers',
                $request->headers->get('Access-Control-Request-Headers') ?: '*',
            );
            $response->headers->set('Access-Control-Max-Age', (string) config('cors.max_age', 86400));
            $response->headers->set('Vary', 'Origin', false);

            return $response;
        });
    })->create();
