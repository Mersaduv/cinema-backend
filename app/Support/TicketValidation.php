<?php

namespace App\Support;

class TicketValidation
{
    public const DESCRIPTION_MIN = 5;

    public const DESCRIPTION_MAX = 5000;

    /** @var array<string, string> */
    public const CATEGORIES = [
        'technical' => 'مشکل فنی',
        'content' => 'مشکل محتوا',
        'suggestion' => 'پیشنهاد',
        'criticism' => 'انتقاد',
        'account' => 'حساب کاربری',
        'other' => 'سایر',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function submitRules(): array
    {
        return [
            'category' => ['required', 'string', 'in:' . implode(',', array_keys(self::CATEGORIES))],
            'description' => [
                'required',
                'string',
                'min:' . self::DESCRIPTION_MIN,
                'max:' . self::DESCRIPTION_MAX,
            ],
            'image_directory' => ['nullable', 'string', 'max:500'],
            'image_filename' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function messageRules(): array
    {
        return [
            'body' => ['nullable', 'string', 'max:' . self::DESCRIPTION_MAX],
            'image_directory' => ['nullable', 'string', 'max:500'],
            'image_filename' => ['nullable', 'string', 'max:255'],
        ];
    }

    public static function normalizeMessageBody(string $body, ?string $attachmentPath = null): string
    {
        $sanitized = self::sanitizeDescription($body);

        if (mb_strlen($sanitized) >= self::DESCRIPTION_MIN) {
            return $sanitized;
        }

        if (! filled($attachmentPath)) {
            throw new \InvalidArgumentException('متن پیام خیلی کوتاه است.');
        }

        $extension = strtolower(pathinfo((string) $attachmentPath, PATHINFO_EXTENSION));

        return in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'], true)
            ? 'پیوست تصویر'
            : 'پیوست فایل';
    }

    public static function categoryLabel(string $slug): ?string
    {
        return self::CATEGORIES[$slug] ?? null;
    }

    public static function sanitizeDescription(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }

    public static function subjectFromDescription(string $description): string
    {
        $line = strtok($description, "\n") ?: $description;

        return mb_strlen($line) > 200 ? mb_substr($line, 0, 200) : $line;
    }
}
