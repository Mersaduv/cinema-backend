<?php

namespace App\Support;

final class AgeRating
{
    /**
     * Map legacy letter / TV ratings to a pure minimum age number string.
     */
    public static function normalize(?string $value): ?string
    {
        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return null;
        }

        $map = [
            'TV-Y' => '0',
            'TV-Y7' => '0',
            'TV-G' => '0',
            'G' => '0',
            'TV-PG' => '7',
            'PG' => '7',
            'NR' => '7',
            'TV-14' => '13',
            'PG-13' => '13',
            '12' => '13',
            '12A' => '13',
            '15' => '16',
            '16' => '16',
            '16+' => '16',
            'TV-MA' => '17',
            'R' => '17',
            '18' => '18',
            '18+' => '18',
            'NC-17' => '18',
            'X' => '18',
            'R18' => '18',
        ];

        $key = strtoupper($trimmed);
        if (isset($map[$key])) {
            return $map[$key];
        }

        if (isset($map[$trimmed])) {
            return $map[$trimmed];
        }

        if (preg_match('/^(\d{1,2})\+?$/', $trimmed, $matches) === 1) {
            return $matches[1];
        }

        return $trimmed;
    }
}
