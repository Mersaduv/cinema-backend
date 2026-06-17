<?php

namespace App\Http\Controllers;

use App\Services\Cinema\TvActivationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class TvActivationController extends Controller
{
    public function __construct(
        private readonly TvActivationService $activationService,
    ) {}

    /**
     * TV device requests a short-lived pairing code.
     */
    public function request(Request $request): JsonResponse
    {
        $deviceLabel = $request->string('device_label')->toString() ?: null;
        $record = $this->activationService->createCode($deviceLabel);

        return response()->json([
            'success' => true,
            'data' => [
                'code' => $record->code,
                'expires_at' => $record->expires_at->toIso8601String(),
                'expires_in' => TvActivationService::TTL_SECONDS,
                'pair_url' => rtrim(config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:3000')), '/') . '/tv-login',
            ],
        ]);
    }

    /**
     * TV polls until the user confirms the code on a companion device.
     */
    public function poll(Request $request): JsonResponse
    {
        $code = strtoupper(trim((string) $request->query('code', '')));

        if ($code === '') {
            return response()->json([
                'success' => false,
                'message' => 'کد فعال‌سازی الزامی است.',
            ], 422);
        }

        $status = $this->activationService->pollStatus($code);

        if ($status['status'] === 'activated') {
            $cacheKey = TvActivationService::tokenCacheKey($code);
            $token = Cache::pull($cacheKey);

            if ($token) {
                $status['token'] = $token;
                $this->activationService->deleteCode($code);
            }
        }

        return response()->json([
            'success' => true,
            'data' => $status,
        ]);
    }

    /**
     * Logged-in user confirms a TV code from phone/desktop.
     */
    public function confirm(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string|min:4|max:12',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'کد نامعتبر است.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();
        $result = $this->activationService->activateForUser($request->string('code')->toString(), $user);

        if (! $result) {
            return response()->json([
                'success' => false,
                'message' => 'کد منقضی شده یا قبلاً استفاده شده است.',
            ], 410);
        }

        Cache::put(
            TvActivationService::tokenCacheKey($result['code']),
            $result['token'],
            now()->addMinutes(2),
        );

        return response()->json([
            'success' => true,
            'message' => 'تلویزیون با موفقیت متصل شد.',
            'data' => [
                'code' => $result['code'],
            ],
        ]);
    }
}
