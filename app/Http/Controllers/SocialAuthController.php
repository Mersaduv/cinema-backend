<?php

namespace App\Http\Controllers;

use App\Models\User;
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

            // Check if user exists by google_id
            $user = User::where('google_id', $googleUser->getId())->first();

            // If user doesn't exist, check by email
            if (!$user) {
                $user = User::where('email', $googleUser->getEmail())->first();

                if ($user) {
                    // Link Google account to existing user
                    $user->google_id = $googleUser->getId();
                    if (!$user->name || $user->name === '') {
                        $user->name = $googleUser->getName() ?? '';
                    }
                    $user->save();
                } else {
                    // Create new user
                    $user = User::create([
                        'name' => $googleUser->getName() ?? '',
                        'email' => $googleUser->getEmail(),
                        'google_id' => $googleUser->getId(),
                        'password' => bcrypt(Str::random(16)), // Random password for social login
                        'status' => 'active',
                        'email_verified_at' => now(),
                    ]);

                    // Assign default user role
                    $userRole = \App\Models\Role::where('slug', 'user')->first();
                    if ($userRole) {
                        $user->roles()->attach($userRole);
                    }
                }
            } else {
                // Update name if it's empty
                if (!$user->name || $user->name === '') {
                    $user->name = $googleUser->getName() ?? '';
                    $user->save();
                }
            }

            // Check if user is active
            if (!$user->isActive()) {
                $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
                return redirect($frontendUrl . '/login?error=' . urlencode('حساب کاربری شما مسدود شده است.'));
            }

            // Create token
            $token = $user->createToken('auth_token')->plainTextToken;

            // Load user with roles and permissions
            $user->load('roles.permissions');
            
            // Prepare user data for frontend
            $userData = [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'status' => $user->status,
                'roles' => $user->roles->map(function ($role) {
                    return [
                        'id' => $role->id,
                        'name' => $role->name,
                        'slug' => $role->slug,
                        'permissions' => $role->permissions->map(function ($permission) {
                            return [
                                'id' => $permission->id,
                                'name' => $permission->name,
                                'slug' => $permission->slug,
                            ];
                        }),
                    ];
                }),
            ];

            // Redirect to frontend with token
            $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
            
            // Use query parameters properly
            $queryParams = http_build_query([
                'token' => $token,
                'user' => base64_encode(json_encode($userData)),
            ]);
            
            return redirect($frontendUrl . '/auth/callback?' . $queryParams);

        } catch (\Illuminate\Http\Client\RequestException $e) {
            // Network or HTTP errors
            \Log::error('Google OAuth HTTP Error: ' . $e->getMessage());
            $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
            $errorMessage = 'خطا در ارتباط با Google. لطفا دوباره تلاش کنید.';
            if (config('app.debug')) {
                $errorMessage .= ' (' . $e->getMessage() . ')';
            }
            return redirect($frontendUrl . '/login?error=' . urlencode($errorMessage));
        } catch (\Laravel\Socialite\Two\InvalidStateException $e) {
            // OAuth state mismatch
            \Log::error('Google OAuth State Error: ' . $e->getMessage());
            $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
            return redirect($frontendUrl . '/login?error=' . urlencode('خطا در احراز هویت. لطفا دوباره تلاش کنید.'));
        } catch (\Exception $e) {
            // Log the error for debugging
            \Log::error('Google OAuth Error: ' . $e->getMessage(), [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
            $errorMessage = 'ورود با گوگل ناموفق بود. لطفا دوباره تلاش کنید.';
            
            // In development, show the actual error
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

        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $user->name = $request->name;
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Profile completed successfully',
            'data' => [
                'user' => $user->load('roles.permissions'),
            ],
        ]);
    }
}
