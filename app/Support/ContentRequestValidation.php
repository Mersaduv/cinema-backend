<?php

namespace App\Support;

class ContentRequestValidation
{
  public const TITLE_MIN = 2;

  public const TITLE_MAX = 200;

  public const DESCRIPTION_MIN = 10;

  public const DESCRIPTION_MAX = 2000;

  /**
   * @return array<string, mixed>
   */
  public static function submitRules(): array
  {
    $genreSlugs = collect(CatalogGenreManifest::CATALOG_ROWS)
      ->pluck('slug')
      ->unique()
      ->values()
      ->all();

    return [
      'title' => ['required', 'string', 'min:' . self::TITLE_MIN, 'max:' . self::TITLE_MAX],
      'genre_slug' => ['required', 'string', 'in:' . implode(',', $genreSlugs)],
      'description' => [
        'required',
        'string',
        'min:' . self::DESCRIPTION_MIN,
        'max:' . self::DESCRIPTION_MAX,
      ],
    ];
  }

  public static function sanitizeDescription(string $value): string
  {
    return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
  }

  public static function resolveGenreName(string $slug): ?string
  {
    foreach (CatalogGenreManifest::CATALOG_ROWS as $row) {
      if ($row['slug'] === $slug) {
        return $row['name'];
      }
    }

    return null;
  }
}
