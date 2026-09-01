<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Viewer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        if (! $this->passesRecaptcha($request)) {
            return response()->json([
                'success' => false,
                'message' => 'CAPTCHA validation failed.',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('viewers', 'email'),
                Rule::unique('users', 'email'),
            ],
            'password' => 'required|string|min:8|confirmed',
            'captcha_token' => $this->recaptchaTokenRules(),
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $viewer = Viewer::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'status' => 'active',
        ]);

        $token = $viewer->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Viewer registered successfully',
            'data' => [
                'user' => $this->transformViewer($viewer),
                'token' => $token,
            ],
        ], 201);
    }

    public function login(Request $request)
    {
        $key = 'viewer-login.' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);

            return response()->json([
                'success' => false,
                'message' => 'Too many login attempts. Please try again in ' . $seconds . ' seconds.',
            ], 429);
        }

        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required',
            'captcha_token' => $this->recaptchaTokenRules(),
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        if (! $this->passesRecaptcha($request)) {
            return response()->json([
                'success' => false,
                'message' => 'CAPTCHA validation failed.',
            ], 422);
        }

        // Staff accounts must use the staff login endpoint.
        if (User::where('email', $request->email)->exists()) {
            RateLimiter::hit($key, 60);

            return response()->json([
                'success' => false,
                'message' => 'لطفاً از صفحه ورود پنل مدیریت استفاده کنید.',
            ], 403);
        }

        $viewer = Viewer::where('email', $request->email)->first();

        if (! $viewer || ! Hash::check($request->password, $viewer->password)) {
            RateLimiter::hit($key, 60);

            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials',
            ], 401);
        }

        if (! $viewer->isActive()) {
            return response()->json([
                'success' => false,
                'message' => 'Your account has been banned',
            ], 403);
        }

        RateLimiter::clear($key);

        $token = $viewer->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'user' => $this->transformViewer($viewer),
                'token' => $token,
            ],
        ]);
    }

    public function staffLogin(Request $request)
    {
        $key = 'staff-login.' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);

            return response()->json([
                'success' => false,
                'message' => 'Too many login attempts. Please try again in ' . $seconds . ' seconds.',
            ], 429);
        }

        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required',
            'captcha_token' => $this->recaptchaTokenRules(),
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        if (! $this->passesRecaptcha($request)) {
            return response()->json([
                'success' => false,
                'message' => 'CAPTCHA validation failed.',
            ], 422);
        }

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            RateLimiter::hit($key, 60);

            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials',
            ], 401);
        }

        if (! $user->isActive()) {
            return response()->json([
                'success' => false,
                'message' => 'Your account has been banned',
            ], 403);
        }

        if (! $user->hasAnyRole(['admin', 'media', 'support'])) {
            return response()->json([
                'success' => false,
                'message' => 'این حساب اجازه ورود به پنل را ندارد.',
            ], 403);
        }

        RateLimiter::clear($key);

        $token = $user->createToken('staff_auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'user' => $this->transformStaff($user->load(['roles.permissions', 'permissions'])),
                'token' => $token,
            ],
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully',
        ]);
    }

    public function me(Request $request)
    {
        $actor = $request->user();

        if ($actor instanceof Viewer) {
            return response()->json([
                'success' => true,
                'data' => [
                    'user' => $this->transformViewer($actor),
                ],
            ]);
        }

        if ($actor instanceof User) {
            if ($request->boolean('include_permissions')) {
                $actor->load(['roles.permissions', 'permissions']);
            } else {
                $actor->load('roles');
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'user' => $this->transformStaff($actor),
                ],
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Unauthenticated',
        ], 401);
    }

    /**
     * @return array<string, mixed>
     */
    private function transformViewer(Viewer $viewer): array
    {
        return [
            'id' => $viewer->id,
            'name' => $viewer->name,
            'email' => $viewer->email,
            'status' => $viewer->status,
            'account_type' => 'viewer',
            'roles' => [],
            'created_at' => $viewer->created_at ? $viewer->created_at->toISOString() : null,
            'updated_at' => $viewer->updated_at ? $viewer->updated_at->toISOString() : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformStaff(User $user): array
    {
        $permissions = $this->resolveEffectivePermissions($user);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'status' => $user->status,
            'account_type' => 'staff',
            'roles' => $user->roles,
            'permissions' => $permissions,
            'created_at' => $user->created_at ? $user->created_at->toISOString() : null,
            'updated_at' => $user->updated_at ? $user->updated_at->toISOString() : null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function resolveEffectivePermissions(User $user): array
    {
        if ($user->relationLoaded('permissions') && $user->permissions->isNotEmpty()) {
            return $user->permissions->map(function ($permission) {
                return [
                    'id' => $permission->id,
                    'name' => $permission->name,
                    'slug' => $permission->slug,
                    'description' => $permission->description,
                ];
            })->values()->all();
        }

        if (! $user->relationLoaded('roles')) {
            return [];
        }

        return $user->roles
            ->flatMap(function ($role) {
                return $role->permissions ?? collect();
            })
            ->unique('id')
            ->values()
            ->map(function ($permission) {
                return [
                    'id' => $permission->id,
                    'name' => $permission->name,
                    'slug' => $permission->slug,
                    'description' => $permission->description,
                ];
            })
            ->all();
    }

    /**
     * Validate Google reCAPTCHA token (fail-closed only when secret is configured).
     */
    private function passesRecaptcha(Request $request): bool
    {
        $secret = (string) config('recaptcha.secret', '');

        // Local/dev: when secret is not configured, skip verification.
        if ($secret === '') {
            return true;
        }

        $token = (string) $request->input('captcha_token', '');
        if ($token === '') {
            return false;
        }

        try {
            $verifySsl = (bool) config('recaptcha.verify_ssl', true);

            $response = Http::asForm()
                ->withOptions(['verify' => $verifySsl])
                ->timeout(5)
                ->post(
                'https://www.google.com/recaptcha/api/siteverify',
                [
                    'secret' => $secret,
                    'response' => $token,
                    'remoteip' => (string) $request->ip(),
                ],
            );

            if (! $response->successful()) {
                return false;
            }

            $payload = $response->json();

            $ok = (bool) ($payload['success'] ?? false) === true;

            if (! $ok) {
                Log::warning('reCAPTCHA verify failed', [
                    'errors' => $payload['error-codes'] ?? null,
                    'score' => $payload['score'] ?? null,
                    'action' => $payload['action'] ?? null,
                ]);
            }

            return $ok;
        } catch (\Throwable $e) {
            Log::warning('reCAPTCHA verify exception', [
                'message' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Validation rules for captcha_token depending on whether secret is configured.
     *
     * @return array<int, string>
     */
    private function recaptchaTokenRules(): array
    {
        $secret = (string) config('recaptcha.secret', '');

        if ($secret === '') {
            return ['nullable', 'string'];
        }

        return ['required', 'string', 'min:20'];
    }
}
