<?php

namespace App\Services\Cinema;

use App\Models\TvActivationCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TvActivationService
{
    public const TTL_SECONDS = 600;

    public static function tokenCacheKey(string $code): string
    {
        return 'tv_activation_token:' . strtoupper(trim($code));
    }

    public function createCode(?string $deviceLabel = null): TvActivationCode
    {
        $code = $this->generateUniqueCode();

        return TvActivationCode::create([
            'code' => $code,
            'device_label' => $deviceLabel,
            'expires_at' => now()->addSeconds(self::TTL_SECONDS),
        ]);
    }

    /**
     * @return array{code: string, token: string}|null
     */
    public function activateForUser(string $code, User $user): ?array
    {
        return DB::transaction(function () use ($code, $user) {
            $normalized = strtoupper(trim($code));
            $record = TvActivationCode::query()
                ->where('code', $normalized)
                ->lockForUpdate()
                ->first();

            if (! $record || $record->isExpired() || $record->isActivated()) {
                return null;
            }

            $token = $user->createToken('tv_device');
            $plainToken = $token->plainTextToken;

            $record->update([
                'user_id' => $user->id,
                'personal_access_token_id' => $token->accessToken->id,
                'activated_at' => now(),
            ]);

            return [
                'code' => $record->code,
                'token' => $plainToken,
            ];
        });
    }

    public function deleteCode(string $code): void
    {
        $normalized = strtoupper(trim($code));
        TvActivationCode::query()->where('code', $normalized)->delete();
    }

    public function purgeExpiredUnactivatedCodes(): int
    {
        return TvActivationCode::query()
            ->whereNull('activated_at')
            ->where('expires_at', '<', now()->subHour())
            ->delete();
    }

    /**
     * @return array<string, mixed>
     */
    public function pollStatus(string $code): array
    {
        $normalized = strtoupper(trim($code));
        $record = TvActivationCode::query()
            ->where('code', $normalized)
            ->with('user.roles')
            ->first();

        if (! $record) {
            return ['status' => 'not_found'];
        }

        if ($record->isExpired() && ! $record->isActivated()) {
            return ['status' => 'expired'];
        }

        if (! $record->isActivated()) {
            return [
                'status' => 'pending',
                'expires_at' => $record->expires_at->toIso8601String(),
            ];
        }

        return [
            'status' => 'activated',
            'user' => $record->user,
        ];
    }

    private function generateUniqueCode(): string
    {
        do {
            $left = strtoupper(Str::random(4));
            $right = strtoupper(Str::random(4));
            $code = $left . '-' . $right;
        } while (TvActivationCode::query()->where('code', $code)->exists());

        return $code;
    }
}
