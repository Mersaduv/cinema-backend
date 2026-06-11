<?php

namespace App\Services\Cinema;

use App\Models\ProductionCountry;
use Illuminate\Support\Facades\Schema;

class CountryCatalogService
{
    /** @var array<string, string> */
    private const LABEL_TO_ISO = [
        'us' => 'US',
        'usa' => 'US',
        'united states' => 'US',
        'united states of america' => 'US',
        'آمریکا' => 'US',
        'ایالات متحده' => 'US',
        'ایالات متحده آمریکا' => 'US',
        'gb' => 'GB',
        'uk' => 'GB',
        'united kingdom' => 'GB',
        'great britain' => 'GB',
        'بریتانیا' => 'GB',
        'انگلستان' => 'GB',
        'انگلیس' => 'GB',
        'kr' => 'KR',
        'south korea' => 'KR',
        'کره جنوبی' => 'KR',
        'کره' => 'KR',
        'ir' => 'IR',
        'iran' => 'IR',
        'ایران' => 'IR',
        'fr' => 'FR',
        'france' => 'FR',
        'فرانسه' => 'FR',
        'de' => 'DE',
        'germany' => 'DE',
        'آلمان' => 'DE',
        'tr' => 'TR',
        'turkey' => 'TR',
        'ترکیه' => 'TR',
        'jp' => 'JP',
        'japan' => 'JP',
        'ژاپن' => 'JP',
        'in' => 'IN',
        'india' => 'IN',
        'هند' => 'IN',
        'cn' => 'CN',
        'china' => 'CN',
        'چین' => 'CN',
    ];

    public function resolveIso(?string $label): ?string
    {
        if ($label === null) {
            return null;
        }

        $label = trim($label);
        if ($label === '') {
            return null;
        }

        if (strlen($label) === 2 && ctype_alpha($label)) {
            return strtoupper($label);
        }

        $aliasKey = $this->normalizeLabelKey($label);
        if (isset(self::LABEL_TO_ISO[$aliasKey])) {
            return self::LABEL_TO_ISO[$aliasKey];
        }

        $country = ProductionCountry::query()
            ->where('iso_3166_1', strtoupper($label))
            ->orWhere('name', $label)
            ->orWhere('native_name', $label)
            ->when(
                $this->hasEnglishNameColumn(),
                fn ($query) => $query->orWhere('english_name', $label),
            )
            ->first();

        if ($country) {
            return $country->iso_3166_1;
        }

        if (class_exists(\Locale::class) && method_exists(\Locale::class, 'getDisplayRegion')) {
            foreach (['fa', 'en'] as $locale) {
                foreach (self::LABEL_TO_ISO as $iso) {
                    try {
                        $display = trim((string) \Locale::getDisplayRegion('und_'.$iso, $locale));
                        if ($display !== '' && $this->normalizeLabelKey($display) === $aliasKey) {
                            return $iso;
                        }
                    } catch (\Throwable) {
                        // ignore intl failures
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $labels
     * @return array<int, string>
     */
    public function resolveIsos(array $labels): array
    {
        return collect($labels)
            ->map(fn ($label) => is_string($label) ? $this->resolveIso($label) : null)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $isos
     * @return array<int, string>
     */
    public function labelsForIsos(array $isos): array
    {
        $labels = [];

        foreach ($isos as $iso) {
            $iso = strtoupper($iso);
            $labels[] = $iso;

            foreach (self::LABEL_TO_ISO as $label => $mappedIso) {
                if ($mappedIso === $iso) {
                    $labels[] = $label;
                }
            }

            $country = ProductionCountry::query()
                ->where('iso_3166_1', $iso)
                ->first();

            if ($country) {
                $labels[] = $country->name;
                if ($country->native_name) {
                    $labels[] = $country->native_name;
                }
                if ($this->hasEnglishNameColumn() && filled($country->english_name ?? null)) {
                    $labels[] = (string) $country->english_name;
                }
            }

            if (class_exists(\Locale::class) && method_exists(\Locale::class, 'getDisplayRegion')) {
                try {
                    $display = (string) \Locale::getDisplayRegion('und_'.$iso, 'fa');
                    if (trim($display) !== '' && strtoupper(trim($display)) !== $iso) {
                        $labels[] = $display;
                    }
                } catch (\Throwable) {
                    // ignore
                }
            }
        }

        return collect($labels)
            ->map(fn (string $value) => trim($value))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeLabelKey(string $label): string
    {
        $normalized = mb_strtolower(trim($label));

        return preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
    }

    private function hasEnglishNameColumn(): bool
    {
        static $hasColumn = null;

        if ($hasColumn === null) {
            $hasColumn = Schema::hasColumn('production_countries', 'english_name');
        }

        return $hasColumn;
    }
}
