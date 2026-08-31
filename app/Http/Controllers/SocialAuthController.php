<?php

namespace App\Http\Controllers;

use App\Models\Viewer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Support\Str;

class SocialAuthController extends Controller
{
    public function redirectToGoogle()
    {
        return Socialite::driver('google')
            ->scopes(['openid', 'profile', 'email'])
            ->redirect();
    }

    public function handleGoogleCallback(Request $request)
    {
        try {
            // Disable SSL verification in development (Windows SSL certificate issue)
            $httpClient = new \GuzzleHttp\Client([
                'verify' => config('app.env') === 'production',
            ]);

            $googleUser = Socialite::driver('google')
                ->setHttpClient($httpClient)
                ->user();

            // Staff emails cannot register/login as viewers via Google.
            if (\App\Models\User::where('email', $googleUser->getEmail())->exists()) {
                $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');

                return redirect($frontendUrl . '/login?error=' . urlencode('این ایمیل مربوط به حساب پنل است. از صفحه /adminbod وارد شوید.'));
            }

            $viewer = Viewer::where('google_id', $googleUser->getId())->first();

            if (! $viewer) {
                $viewer = Viewer::where('email', $googleUser->getEmail())->first();

                if ($viewer) {
                    $viewer->google_id = $googleUser->getId();
                    if (! $viewer->name || $viewer->name === '') {
                        $viewer->name = $googleUser->getName() ?? '';
                    }
                    $viewer->save();
                } else {
                    $viewer = Viewer::create([
                        'name' => $googleUser->getName() ?? '',
                        'email' => $googleUser->getEmail(),
                        'google_id' => $googleUser->getId(),
                        'password' => bcrypt(Str::random(16)),
                        'status' => 'active',
                        'email_verified_at' => now(),
                    ]);
                }
            } else {
                if (! $viewer->name || $viewer->name === '') {
                    $viewer->name = $googleUser->getName() ?? '';
                    $viewer->save();
                }
            }

            if (! $viewer->isActive()) {
                $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');

                return redirect($frontendUrl . '/login?error=' . urlencode('حساب کاربری شما مسدود شده است.'));
            }

            $token = $viewer->createToken('auth_token')->plainTextToken;

            $userData = [
                'id' => $viewer->id,
                'name' => $viewer->name,
                'email' => $viewer->email,
                'status' => $viewer->status,
                'account_type' => 'viewer',
                'roles' => [],
            ];

            $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');

            $queryParams = http_build_query([
                'token' => $token,
                'user' => base64_encode(json_encode($userData)),
            ]);

            return redirect($frontendUrl . '/auth/callback?' . $queryParams);
        } catch (\Illuminate\Http\Client\RequestException $e) {
            \Log::error('Google OAuth HTTP Error: ' . $e->getMessage());
            $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
            $errorMessage = 'خطا در ارتباط با Google. لطفا دوباره تلاش کنید.';
            if (config('app.debug')) {
                $errorMessage .= ' (' . $e->getMessage() . ')';
            }

            return redirect($frontendUrl . '/login?error=' . urlencode($errorMessage));
        } catch (\Laravel\Socialite\Two\InvalidStateException $e) {
            \Log::error('Google OAuth State Error: ' . $e->getMessage());
            $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');

            return redirect($frontendUrl . '/login?error=' . urlencode('خطا در احراز هویت. لطفا دوباره تلاش کنید.'));
        } catch (\Exception $e) {
            \Log::error('Google OAuth Error: ' . $e->getMessage(), [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
            $errorMessage = 'ورود با گوگل ناموفق بود. لطفا دوباره تلاش کنید.';

            if (config('app.debug')) {
                $errorMessage .= ' (' . $e->getMessage() . ')';
            }

            return redirect($frontendUrl . '/login?error=' . urlencode($errorMessage));
        }
    }

    public function completeProfile(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $viewer = $request->user();

        if (! $viewer instanceof Viewer) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $viewer->name = $request->name;
        $viewer->save();

        return response()->json([
            'success' => true,
            'message' => 'Profile completed successfully',
            'data' => [
                'user' => [
                    'id' => $viewer->id,
                    'name' => $viewer->name,
                    'email' => $viewer->email,
                    'status' => $viewer->status,
                    'account_type' => 'viewer',
                    'roles' => [],
                ],
            ],
        ]);
    }
}
