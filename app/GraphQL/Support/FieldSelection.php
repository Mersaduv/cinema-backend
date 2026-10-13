<?php

namespace App\GraphQL\Support;

use GraphQL\Type\Definition\ResolveInfo;

/**
 * Helpers for building lean GraphQL payloads from ResolveInfo selections.
 */
final class FieldSelection
{
    /**
     * @return array<string, mixed>
     */
    public static function tree(ResolveInfo $resolveInfo, int $depth = 2): array
    {
        return $resolveInfo->getFieldSelection($depth);
    }

    /**
     * Stable short fingerprint for cache keys (order-independent).
     *
     * @param  array<string, mixed>  $selection
     */
    public static function fingerprint(array $selection): string
    {
        $normalized = self::normalize($selection);

        return substr(hash('sha1', json_encode($normalized, JSON_UNESCAPED_UNICODE)), 0, 16);
    }

    public static function wants(array $selection, string $path): bool
    {
        $segments = explode('.', $path);
        $cursor = $selection;

        foreach ($segments as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                return false;
            }

            $cursor = $cursor[$segment];
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $selection
     * @return array<string, mixed>
     */
    private static function normalize(array $selection): array
    {
        ksort($selection);

        foreach ($selection as $key => $value) {
            if (is_array($value)) {
                $selection[$key] = self::normalize($value);
            } else {
                $selection[$key] = true;
            }
        }

        return $selection;
    }
}
