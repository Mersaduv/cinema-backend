<?php

namespace App\GraphQL\Support;

class DrmConfigHelper
{
    /**
     * @return array{scheme: string, licenseUrl: ?string}|null
     */
    public static function fromColumns(?string $scheme, ?string $licenseUrl): ?array
    {
        $scheme = strtolower(trim((string) ($scheme ?? 'none')));

        if ($scheme === 'none' || ! filled($licenseUrl)) {
            return null;
        }

        return [
            'scheme' => $scheme,
            'licenseUrl' => trim((string) $licenseUrl),
        ];
    }
}
