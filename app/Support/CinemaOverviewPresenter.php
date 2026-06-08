<?php

namespace App\Support;

final class CinemaOverviewPresenter
{
    /**
     * Prefer Persian copy for UI; fall back to the canonical English synopsis.
     */
    public static function display(?string $english, ?string $persian): ?string
    {
        $persianText = trim((string) ($persian ?? ''));

        if ($persianText !== '') {
            return $persianText;
        }

        $englishText = trim((string) ($english ?? ''));

        return $englishText !== '' ? $englishText : null;
    }
}
