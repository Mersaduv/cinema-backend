<?php

namespace App\Services\Cinema;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class ContentPreviewTokenService
{
    public function issue(Model $entity, string $metadataKey = 'preview_token'): array
    {
        $metadata = is_array($entity->metadata) ? $entity->metadata : [];
        $token = (string) Str::uuid();
        $expiresAt = now()->addMinutes(30);

        $metadata[$metadataKey] = [
            'token' => $token,
            'expires_at' => $expiresAt->toIso8601String(),
        ];

        $entity->forceFill(['metadata' => $metadata])->save();

        return [
            'token' => $token,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    /**
     * @return array{valid: bool, consumed: bool}
     */
    public function validateAndConsume(Model $entity, ?string $token, string $metadataKey = 'preview_token'): array
    {
        if (! filled($token)) {
            return ['valid' => false, 'consumed' => false];
        }

        $metadata = is_array($entity->metadata) ? $entity->metadata : [];
        $stored = data_get($metadata, $metadataKey);

        if (! is_array($stored)) {
            return ['valid' => false, 'consumed' => false];
        }

        $storedToken = (string) ($stored['token'] ?? '');
        $expiresAt = isset($stored['expires_at']) ? strtotime((string) $stored['expires_at']) : false;

        if ($storedToken === '' || ! hash_equals($storedToken, (string) $token)) {
            return ['valid' => false, 'consumed' => false];
        }

        if ($expiresAt === false || $expiresAt < time()) {
            return ['valid' => false, 'consumed' => false];
        }

        unset($metadata[$metadataKey]);
        $entity->forceFill(['metadata' => $metadata])->save();

        return ['valid' => true, 'consumed' => true];
    }
}
