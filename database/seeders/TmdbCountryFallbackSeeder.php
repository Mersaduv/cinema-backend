<?php

namespace Database\Seeders;

use App\Models\ProductionCountry;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

class TmdbCountryFallbackSeeder extends Seeder
{
    /**
     * Common production countries (Persian labels) when TMDB configuration sync is unavailable.
     *
     * @var array<int, array{iso: string, name: string}>
     */
    private const COUNTRIES = [
        ['iso' => 'IR', 'name' => 'ایران'],
        ['iso' => 'US', 'name' => 'ایالات متحده آمریکا'],
        ['iso' => 'GB', 'name' => 'بریتانیا'],
        ['iso' => 'FR', 'name' => 'فرانسه'],
        ['iso' => 'DE', 'name' => 'آلمان'],
        ['iso' => 'IT', 'name' => 'ایتالیا'],
        ['iso' => 'ES', 'name' => 'اسپانیا'],
        ['iso' => 'TR', 'name' => 'ترکیه'],
        ['iso' => 'IN', 'name' => 'هند'],
        ['iso' => 'JP', 'name' => 'ژاپن'],
        ['iso' => 'KR', 'name' => 'کره جنوبی'],
        ['iso' => 'CN', 'name' => 'چین'],
        ['iso' => 'CA', 'name' => 'کانادا'],
        ['iso' => 'AU', 'name' => 'استرالیا'],
        ['iso' => 'BR', 'name' => 'برزیل'],
        ['iso' => 'MX', 'name' => 'مکزیک'],
        ['iso' => 'RU', 'name' => 'روسیه'],
        ['iso' => 'SE', 'name' => 'سوئد'],
        ['iso' => 'NO', 'name' => 'نروژ'],
        ['iso' => 'DK', 'name' => 'دانمارک'],
        ['iso' => 'NL', 'name' => 'هلند'],
        ['iso' => 'BE', 'name' => 'بلژیک'],
        ['iso' => 'CH', 'name' => 'سوئیس'],
        ['iso' => 'AT', 'name' => 'اتریش'],
        ['iso' => 'PL', 'name' => 'لهستان'],
        ['iso' => 'GR', 'name' => 'یونان'],
        ['iso' => 'PT', 'name' => 'پرتغال'],
        ['iso' => 'AR', 'name' => 'آرژانتین'],
        ['iso' => 'CO', 'name' => 'کلمبیا'],
        ['iso' => 'ZA', 'name' => 'آفریقای جنوبی'],
        ['iso' => 'EG', 'name' => 'مصر'],
        ['iso' => 'SA', 'name' => 'عربستان سعودی'],
        ['iso' => 'AE', 'name' => 'امارات متحده عربی'],
        ['iso' => 'IL', 'name' => 'اسرائیل'],
        ['iso' => 'TH', 'name' => 'تایلند'],
        ['iso' => 'PH', 'name' => 'فیلیپین'],
        ['iso' => 'ID', 'name' => 'اندونزی'],
        ['iso' => 'MY', 'name' => 'مالزی'],
        ['iso' => 'SG', 'name' => 'سنگاپور'],
        ['iso' => 'NZ', 'name' => 'نیوزیلند'],
        ['iso' => 'IE', 'name' => 'ایرلند'],
        ['iso' => 'FI', 'name' => 'فنلاند'],
        ['iso' => 'CZ', 'name' => 'جمهوری چک'],
        ['iso' => 'HU', 'name' => 'مجارستان'],
        ['iso' => 'RO', 'name' => 'رومانی'],
        ['iso' => 'UA', 'name' => 'اوکراین'],
        ['iso' => 'HK', 'name' => 'هنگ‌کنگ'],
        ['iso' => 'TW', 'name' => 'تایوان'],
    ];

    public function run(): void
    {
        foreach (self::COUNTRIES as $country) {
            $iso = strtoupper($country['iso']);
            ProductionCountry::query()->updateOrCreate(
                ['iso_3166_1' => $iso],
                [
                    'name' => $country['name'],
                    'native_name' => $country['name'],
                    'flag_emoji' => $this->flagForIso($iso),
                ],
            );
        }

        Cache::forget('filter_options');
    }

    private function flagForIso(string $iso): ?string
    {
        if (strlen($iso) !== 2) {
            return null;
        }

        return mb_chr(ord($iso[0]) - 65 + 0x1F1E6).mb_chr(ord($iso[1]) - 65 + 0x1F1E6);
    }
}
