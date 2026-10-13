<?php

namespace App\Http\Controllers;

use App\Models\Movie;
use App\Models\Series;
use App\Services\Cinema\AdminReferenceOptionsService;
use App\Services\Cinema\EnsureTmdbReferenceDataService;
use App\Services\Cinema\GenreCatalogService;
use App\Support\CatalogGenreManifest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class FilterOptionsController extends Controller
{
    public function __construct(
        private readonly EnsureTmdbReferenceDataService $referenceData,
        private readonly GenreCatalogService $genreCatalogService,
        private readonly AdminReferenceOptionsService $adminReferenceOptions,
    ) {}

    public function __invoke(): JsonResponse
    {
        $payload = Cache::remember('filter_options_v3', 86400, function (): array {
            $this->referenceData->ensureCountriesLoaded();

            return [
                'catalog_genres' => CatalogGenreManifest::orderedForApi('movie'),
                'movie_genres' => CatalogGenreManifest::orderedForApi('movie'),
                'tv_genres' => CatalogGenreManifest::orderedForApi('series'),
                'countries' => $this->adminReferenceOptions->countries(),
                'languages' => $this->adminReferenceOptions->languages(),
                'release_years' => $this->releaseYears(),
                'age_ratings' => ['0', '7', '13', '16', '17', '18'],
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
                    ['value' => 'trending', 'label' => 'جدید و محبوب'],
                    ['value' => 'popular_hybrid', 'label' => 'محبوب در سینما'],
                ],
                'genre_id_aliases' => $this->genreCatalogService->buildGenreIdAliasMap(),
            ];
        });

        if ($payload['countries'] === []) {
            Cache::forget('filter_options_v3');
            $this->referenceData->ensureCountriesLoaded();
            $this->adminReferenceOptions->invalidate();
            $payload['countries'] = $this->adminReferenceOptions->countries();
        }

        return response()
            ->json(['success' => true, 'data' => $payload])
            ->header('Cache-Control', 'public, max-age=86400');
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
