<?php

namespace App\Support;

use App\Rules\NullableImageOrUrl;

class CinemaValidation
{
    /**
     * @return array<int, mixed>
     */
    public static function nullableImageOrUrl(int $maxLength = 2048): array
    {
        return ['nullable', 'string', 'max:' . $maxLength, new NullableImageOrUrl()];
    }
}
