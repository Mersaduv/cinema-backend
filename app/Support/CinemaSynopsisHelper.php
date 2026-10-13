<?php

namespace App\Support;

use App\Services\OverviewTranslationService;

/**
 * Season/episode synopsis: Persian in synopsis, English TMDB reference in synopsis_en.
 */
final class CinemaSynopsisHelper
{
    public function __construct(
        private readonly OverviewTranslationService $translation,
    ) {
    }

    public static function hasPersianScript(?string $text): bool
    {
        $value = trim((string) ($text ?? ''));

        return $value !== '' && preg_match('/[\x{0600}-\x{06FF}]/u', $value) === 1;
    }

    /**
     * Normalize DB/API values without calling external translators.
     *
     * @return array{synopsis: ?string, synopsis_en: ?string}
     */
    public function normalizeStored(
        ?string $synopsis,
        ?string $synopsisEn = null,
        ?string $legacyPersian = null,
    ): array {
        $persian = trim((string) ($synopsis ?? ''));
        $english = trim((string) ($synopsisEn ?? ''));
        $legacyFa = trim((string) ($legacyPersian ?? ''));

        if ($legacyFa !== '' && $persian !== '' && !self::hasPersianScript($persian)) {
            $english = $english !== '' ? $english : $persian;
            $persian = $legacyFa;
        } elseif ($legacyFa !== '' && $persian === '') {
            $persian = $legacyFa;
        } elseif ($persian !== '' && !self::hasPersianScript($persian)) {
            $english = $english !== '' ? $english : $persian;
            $persian = '';
        }

        return [
            'synopsis' => $persian !== '' ? $persian : null,
            'synopsis_en' => $english !== '' ? $english : null,
        ];
    }

    /**
     * Build Persian synopsis for seasons/episodes (TMDB ingest, admin save).
     *
     * @return array{synopsis: ?string, synopsis_en: ?string}
     */
    /**
     * Movie/series/season/episode synopsis: Persian in synopsis, English reference in synopsis_en.
     *
     * @return array{synopsis: ?string, synopsis_en: ?string}
     */
    public function localizeContentSynopsis(
        ?string $persianSynopsis,
        ?string $englishSynopsis,
        bool $autoTranslate = true,
    ): array {
        return $this->localizeSeasonOrEpisode($persianSynopsis, $englishSynopsis, $autoTranslate);
    }

    public function localizeSeasonOrEpisode(
        ?string $persianSynopsis,
        ?string $englishSynopsis,
        bool $autoTranslate = true,
    ): array {
        $rawPrimary = trim((string) ($persianSynopsis ?? ''));
        $rawEnglish = trim((string) ($englishSynopsis ?? ''));

        $normalized = $this->normalizeStored($persianSynopsis, $englishSynopsis);
        $persian = $normalized['synopsis'];
        $english = $normalized['synopsis_en'];

        if ($persian !== null && self::hasPersianScript($persian)) {
            return $normalized;
        }

        if ($autoTranslate && $english !== null && trim($english) !== '') {
            $translated = $this->translation->translateEnglishOverviewToPersian($english);

            if (is_string($translated) && trim($translated) !== '') {
                return [
                    'synopsis' => trim($translated),
                    'synopsis_en' => $english,
                ];
            }
        }

        // Keep the admin/editor primary field as-is when translation is unavailable,
        // so manual/out-of-TMDB text is not wiped from the synopsis column.
        if ($rawPrimary !== '') {
            return [
                'synopsis' => $rawPrimary,
                'synopsis_en' => $english !== null && $english !== ''
                    ? $english
                    : ($rawEnglish !== ''
                        ? $rawEnglish
                        : (self::hasPersianScript($rawPrimary) ? null : $rawPrimary)),
            ];
        }

        return [
            'synopsis' => $persian,
            'synopsis_en' => $english,
        ];
    }
}
