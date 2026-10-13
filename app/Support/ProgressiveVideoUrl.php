<?php

namespace App\Support;

final class ProgressiveVideoUrl
{
    /**
     * Coerce host-only paths and accept progressive video file URLs.
     */
    public static function normalize(?string $input): ?string
    {
        $trimmed = trim((string) $input);

        if ($trimmed === '') {
            return null;
        }

        if (! preg_match('#^[a-z][a-z0-9+.-]*://#i', $trimmed)) {
            if (str_starts_with($trimmed, '//')) {
                $trimmed = 'https:'.$trimmed;
            } elseif (preg_match('#^(?:[a-z0-9.-]+\.[a-z]{2,}|localhost)(?:[:/]|$)#i', $trimmed) === 1) {
                $trimmed = 'https://'.ltrim($trimmed, '/');
            }
        }

        $path = (string) (parse_url($trimmed, PHP_URL_PATH) ?? '');

        // cinema.af CDN commonly serves .mkv as progressive sources alongside .mp4.
        if ($path === '' || preg_match('/\.(mp4|m4v|webm|mov|mkv|avi|ts)$/i', $path) !== 1) {
            return null;
        }

        return $trimmed;
    }

    public static function looksLike(?string $input): bool
    {
        return self::normalize($input) !== null;
    }
}
