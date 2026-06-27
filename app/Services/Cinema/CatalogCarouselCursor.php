<?php

namespace App\Services\Cinema;

class CatalogCarouselCursor
{
    /**
     * @return array{id: int, sort: string|int|float|null}|null
     */
    public static function decode(?string $cursor): ?array
    {
        if ($cursor === null || trim($cursor) === '') {
            return null;
        }

        $decoded = base64_decode($cursor, true);

        if ($decoded === false) {
            return null;
        }

        $payload = json_decode($decoded, true);

        if (! is_array($payload) || ! isset($payload['id'])) {
            return null;
        }

        return [
            'id' => (int) $payload['id'],
            'sort' => $payload['sort'] ?? null,
        ];
    }

    public static function encode(int $id, mixed $sortValue): string
    {
        $payload = json_encode([
            'id' => $id,
            'sort' => $sortValue,
        ], JSON_THROW_ON_ERROR);

        return base64_encode($payload);
    }

    public static function decodeId(?string $cursor): ?int
    {
        $payload = self::decode($cursor);

        return $payload !== null ? $payload['id'] : null;
    }
}
