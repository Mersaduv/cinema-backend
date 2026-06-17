<?php

namespace App\GraphQL\Support;

use GraphQL\Error\Error;
use Illuminate\Support\Facades\Auth;

class WatchAuthGate
{
    public static function enforce(): void
    {
        if (! config('cinema.watch_auth_required', false)) {
            return;
        }

        if (Auth::guard('sanctum')->check()) {
            return;
        }

        throw new Error(
            'برای تماشای فیلم باید وارد حساب کاربری خود شوید.',
            extensions: [
                'category' => 'authentication',
                'code' => 'WATCH_AUTH_REQUIRED',
            ],
        );
    }
}
