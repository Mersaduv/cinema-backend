<?php

namespace App\Support;

use Illuminate\Http\Client\PendingRequest;

final class OutboundHttpClient
{
    public static function configure(PendingRequest $request): PendingRequest
    {
        $caBundle = self::resolveCaBundle();

        if ($caBundle !== '') {
            return $request->withOptions([
                'verify' => $caBundle,
            ]);
        }

        return $request->withOptions([
            'verify' => self::resolveVerifySsl(),
        ]);
    }

    private static function resolveCaBundle(): string
    {
        foreach (['overview_translation', 'tmdb'] as $service) {
            $bundle = trim((string) config("services.{$service}.ca_bundle", ''));

            if ($bundle !== '') {
                return $bundle;
            }
        }

        return '';
    }

    private static function resolveVerifySsl(): bool
    {
        $overviewVerify = config('services.overview_translation.verify_ssl');

        if ($overviewVerify !== null) {
            return (bool) $overviewVerify;
        }

        return (bool) config('services.tmdb.verify_ssl', true);
    }
}
