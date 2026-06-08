<?php

namespace App\Http\Controllers;

use App\Models\Movie;
use App\Models\ProductionCountry;
use App\Models\Series;
use App\Models\SpokenLanguage;
use App\Support\CatalogGenreManifest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class FilterOptionsController extends Controller
{
    /** @var array<int, string> */
    private const POPULAR_COUNTRY_ISOS = [
        'IR', 'US', 'GB', 'FR', 'DE', 'TR', 'IN', 'JP', 'KR', 'CN', 'CA', 'AU',
        'IT', 'ES', 'RU', 'AE', 'SA', 'EG', 'BR', 'MX', 'NL', 'SE', 'CH', 'AT',
    ];

    public function __invoke(): JsonResponse
    {
        $payload = Cache::remember('filter_options', 86400, function (): array {
            return [
                'catalog_genres' => CatalogGenreManifest::orderedForApi('movie'),
                'movie_genres' => CatalogGenreManifest::orderedForApi('movie'),
                'tv_genres' => CatalogGenreManifest::orderedForApi('series'),
                'countries' => $this->sortedCountries(),
                'languages' => SpokenLanguage::query()
                    ->orderBy('name')
                    ->get()
                    ->map(fn (SpokenLanguage $language) => [
                        'iso' => $language->iso_639_1,
                        'name' => $language->name,
                    ])
                    ->values()
                    ->all(),
                'release_years' => $this->releaseYears(),
                'age_ratings' => ['G', 'PG', 'PG-13', 'R', 'NC-17', 'TV-MA', 'TV-14', 'TV-PG', 'TV-Y'],
                'content_types' => [
                    ['value' => 'movie', 'label' => 'فیلم'],
                    ['value' => 'series', 'label' => 'سریال'],
                ],
                'sort_options' => [
                    ['value' => 'newest', 'label' => 'جدیدترین'],
                    ['value' => 'oldest', 'label' => 'قدیمی‌ترین'],
                    ['value' => 'title_asc', 'label' => 'عنوان (الف-ی)'],
                    ['value' => 'title_desc', 'label' => 'عنوان (ی-الف)'],
                    ['value' => 'top_rated', 'label' => 'بهترین امتیاز'],
                    ['value' => 'popular', 'label' => 'محبوب‌ترین'],
                ],
            ];
        });

        return response()
            ->json(['success' => true, 'data' => $payload])
            ->header('Cache-Control', 'public, max-age=86400');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sortedCountries(): array
    {
        $rank = array_flip(self::POPULAR_COUNTRY_ISOS);

        return ProductionCountry::query()
            ->get()
            ->sortBy(fn (ProductionCountry $country) => $rank[$country->iso_3166_1] ?? 9999)
            ->values()
            ->map(fn (ProductionCountry $country) => [
                'iso' => $country->iso_3166_1,
                'name' => $country->name,
                'flag' => $country->flag_emoji,
            ])
            ->all();
    }

    /**
     * @return array<int, int>
     */
    private function releaseYears(): array
    {
        $movieYears = Movie::query()
            ->where('publish_status', 'published')
            ->whereNotNull('release_year')
            ->pluck('release_year');

        $seriesYears = Series::query()
            ->where('publish_status', 'published')
            ->whereNotNull('release_year')
            ->pluck('release_year');

        return $movieYears
            ->merge($seriesYears)
            ->unique()
            ->sortDesc()
            ->values()
            ->map(fn ($year) => (int) $year)
            ->all();
    }
}
