<?php

namespace App\Support;

final class ContentMetadataOptions
{
    /** @var list<string> */
    public const CONTENT_WARNINGS = [
        'violence',
        'strong_language',
        'sexual_content',
        'drug_use',
        'disturbing_scenes',
        'gambling',
        'smoking',
    ];

    /** @var list<string> */
    public const QUALITY_BADGES = [
        '4K',
        'HDR10',
        'HDR10Plus',
        'DolbyVision',
        'DolbyAtmos',
        'DTS',
        'Surround51',
        'Surround71',
    ];

    /**
     * @param  list<string>|null  $values
     * @param  list<string>  $allowed
     * @return list<string>
     */
    public static function filterAllowedList(?array $values, array $allowed): array
    {
        return collect($values ?? [])
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn (string $value) => $value !== '' && in_array($value, $allowed, true))
            ->unique()
            ->values()
            ->all();
    }
}
