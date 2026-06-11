<?php

namespace App\Support;

class ReviewValidation
{
    public const BODY_MIN = 3;

    public const BODY_MAX = 2000;

    /**
     * @return array<string, mixed>
     */
    public static function submitRules(): array
    {
        return [
            'reviewable_type' => ['required', 'in:movie,series'],
            'slug' => ['required', 'string', 'max:255'],
            'rating' => ['required', 'integer', 'between:1,10'],
            'body' => ['required', 'string', 'min:' . self::BODY_MIN, 'max:' . self::BODY_MAX],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function updateRules(): array
    {
        return [
            'rating' => ['sometimes', 'integer', 'between:1,10'],
            'body' => ['required', 'string', 'min:' . self::BODY_MIN, 'max:' . self::BODY_MAX],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function adminUpdateRules(): array
    {
        return [
            'rating' => ['sometimes', 'integer', 'between:1,10'],
            'body' => ['sometimes', 'string', 'min:1', 'max:' . self::BODY_MAX],
            'admin_note' => ['nullable', 'string', 'max:500'],
            'censor' => ['sometimes', 'boolean'],
        ];
    }

    public static function sanitizeBody(?string $body): ?string
    {
        if (! filled($body)) {
            return null;
        }

        return trim(strip_tags($body));
    }
}
