<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class NullableImageOrUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $value = trim((string) $value);
        $prefix = '/' . trim((string) config('cinema_images.public_prefix', '/images'), '/');

        if ($value === $prefix || str_starts_with($value, $prefix . '/')) {
            return;
        }

        if (filter_var($value, FILTER_VALIDATE_URL) !== false) {
            return;
        }

        $fail('The :attribute must be a valid URL or managed image path.');
    }
}
