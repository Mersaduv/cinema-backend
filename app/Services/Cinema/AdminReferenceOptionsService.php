<?php

namespace App\Services\Cinema;

use App\Models\Genre;
use App\Models\ProductionCountry;
use App\Models\SpokenLanguage;
use App\Support\CatalogGenreManifest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class AdminReferenceOptionsService
{
    public const CACHE_KEY = 'admin_reference_options_v2';

    /** @var array<int, string> */
    private const POPULAR_COUNTRY_ISOS = [
        'IR', 'US', 'GB', 'FR', 'DE', 'TR', 'IN', 'JP', 'KR', 'CN', 'CA', 'AU',
        'IT', 'ES', 'RU', 'AE', 'SA', 'EG', 'BR', 'MX', 'NL', 'SE', 'CH', 'AT',
    ];

    /** @var array<int, string> */
    private const POPULAR_LANGUAGE_ISOS = [
        'fa', 'en', 'ar', 'tr', 'fr', 'de', 'es', 'hi', 'ja', 'ko', 'zh', 'ru',
        'it', 'pt', 'nl', 'sv', 'pl', 'id', 'th', 'vi', 'he', 'ur', 'bn', 'ms',
    ];

    /** Historical / non-current ISO codes that clutter admin lists. */
    private const OBSOLETE_COUNTRY_ISOS = [
        'AN', 'BU', 'CS', 'DD', 'NT', 'SU', 'TP', 'YD', 'YU', 'ZR',
        'XC', 'XG', 'XI', 'XK',
    ];

    /** @var array<string, array<string, mixed>> */
    private static array $memCache = [];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function genres(): array
    {
        return $this->cachedReferenceRows()['genres'];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function countries(): array
    {
        return $this->cachedReferenceRows()['countries'];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function languages(): array
    {
        return $this->cachedReferenceRows()['languages'];
    }

    public function invalidate(): void
    {
        self::$memCache = [];
        Cache::forget(self::CACHE_KEY);
        Cache::forget('admin_reference_options_v1');
    }

    /**
     * @return array{
     *     genres: array<int, array<string, mixed>>,
     *     countries: array<int, array<string, mixed>>,
     *     languages: array<int, array<string, mixed>>
     * }
     */
    private function cachedReferenceRows(): array
    {
        if (isset(self::$memCache['opts'])) {
            return self::$memCache['opts'];
        }

        $data = Cache::remember(self::CACHE_KEY, 86400, function (): array {
            CatalogGenreManifest::ensureCatalogGenresExist();

            return [
                'genres' => $this->buildGenreOptions(),
                'countries' => $this->buildCountryOptions(),
                'languages' => $this->buildLanguageOptions(),
            ];
        });

        self::$memCache['opts'] = $data;

        return $data;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildGenreOptions(): array
    {
        return collect(CatalogGenreManifest::CATALOG_ROWS)
            ->map(function (array $row) {
                $genre = $row['tmdb_id'] !== null
                    ? Genre::query()->where('tmdb_id', $row['tmdb_id'])->first()
                    : Genre::query()->where('slug', $row['slug'])->first();

                if (! $genre instanceof Genre) {
                    return null;
                }

                $name = $this->usableLabel((string) $row['name'])
                    ? trim((string) $row['name'])
                    : null;

                if ($name === null) {
                    return null;
                }

                return [
                    'id' => $genre->id,
                    'tmdb_id' => $genre->tmdb_id,
                    'name' => $name,
                    'slug' => $genre->slug,
                    'type' => $genre->type,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildCountryOptions(): array
    {
        $rank = array_flip(self::POPULAR_COUNTRY_ISOS);
        $obsolete = array_flip(self::OBSOLETE_COUNTRY_ISOS);

        return ProductionCountry::query()
            ->get(['iso_3166_1', 'name', 'native_name', 'english_name', 'flag_emoji'])
            ->map(function (ProductionCountry $country) use ($rank, $obsolete) {
                $iso = strtoupper(trim((string) $country->iso_3166_1));
                $label = $this->countryDisplayName($country);

                if ($iso === '' || $label === null) {
                    return null;
                }

                return [
                    'iso' => $iso,
                    'name' => $label,
                    'flag' => $this->usableFlag((string) ($country->flag_emoji ?? ''))
                        ? $country->flag_emoji
                        : null,
                    '_obsolete' => isset($obsolete[$iso]),
                    '_rank' => $rank[$iso] ?? 9999,
                    '_sort' => $this->sortKey($label),
                ];
            })
            ->filter(fn ($row) => is_array($row))
            ->sortBy(fn (array $row) => sprintf(
                '%d-%04d-%s',
                $row['_obsolete'] ? 1 : 0,
                (int) $row['_rank'],
                $row['_sort'],
            ))
            ->map(fn (array $row) => [
                'iso' => $row['iso'],
                'name' => $row['name'],
                'flag' => $row['flag'],
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildLanguageOptions(): array
    {
        $rank = array_flip(self::POPULAR_LANGUAGE_ISOS);

        return SpokenLanguage::query()
            ->get(['iso_639_1', 'name', 'english_name'])
            ->map(function (SpokenLanguage $language) use ($rank) {
                $iso = strtolower(trim((string) $language->iso_639_1));
                $label = $this->languageDisplayName($language);

                if ($iso === '' || $label === null) {
                    return null;
                }

                return [
                    'iso' => $iso,
                    'name' => $label,
                    '_rank' => $rank[$iso] ?? 9999,
                    '_sort' => $this->sortKey($label),
                ];
            })
            ->filter(fn ($row) => is_array($row))
            ->sortBy(fn (array $row) => sprintf(
                '%04d-%s',
                (int) $row['_rank'],
                $row['_sort'],
            ))
            ->map(fn (array $row) => [
                'iso' => $row['iso'],
                'name' => $row['name'],
            ])
            ->values()
            ->all();
    }

    private function countryDisplayName(ProductionCountry $country): ?string
    {
        foreach ([
            $country->name,
            $country->english_name,
            $country->native_name,
            $country->iso_3166_1,
        ] as $candidate) {
            if ($this->usableLabel((string) $candidate)) {
                return trim((string) $candidate);
            }
        }

        return null;
    }

    private function languageDisplayName(SpokenLanguage $language): ?string
    {
        $english = trim((string) ($language->english_name ?? ''));
        $native = trim((string) ($language->name ?? ''));

        // TMDB leaves native `name` empty for many languages — english_name is reliable.
        if ($this->usableLabel($english)) {
            if (
                $this->usableLabel($native)
                && $this->normalizeCompareKey($native) !== $this->normalizeCompareKey($english)
                && $this->isMostlyLatin($english)
            ) {
                return "{$english} ({$native})";
            }

            return $english;
        }

        if ($this->usableLabel($native)) {
            return $native;
        }

        $iso = strtolower(trim((string) $language->iso_639_1));

        return $iso !== '' ? strtoupper($iso) : null;
    }

    private function usableLabel(string $value): bool
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return false;
        }

        // Broken encodings / placeholders commonly rendered as ?????
        if (preg_match('/^[\?\x{FFFD}\s\.]+$/u', $trimmed) === 1) {
            return false;
        }

        // Majority question marks → unusable
        $qCount = substr_count($trimmed, '?');
        if ($qCount > 0 && $qCount >= (int) floor(mb_strlen($trimmed) / 2)) {
            return false;
        }

        return true;
    }

    private function usableFlag(string $flag): bool
    {
        $trimmed = trim($flag);

        if ($trimmed === '') {
            return false;
        }

        // Regional-indicator pairs only (skip broken / text fallbacks like ????)
        return preg_match('/^[\x{1F1E6}-\x{1F1FF}]{2}$/u', $trimmed) === 1;
    }

    private function sortKey(string $value): string
    {
        return Str::lower($this->normalizeCompareKey($value));
    }

    private function normalizeCompareKey(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    private function isMostlyLatin(string $value): bool
    {
        $letters = preg_replace('/[^\p{L}]/u', '', $value) ?? '';

        if ($letters === '') {
            return true;
        }

        $latin = preg_replace('/[^\p{Latin}]/u', '', $letters) ?? '';

        return mb_strlen($latin) >= (int) ceil(mb_strlen($letters) * 0.6);
    }
}
