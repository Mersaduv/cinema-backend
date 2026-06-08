<?php

namespace Tests\Unit;

use App\Services\TmdbService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TmdbServiceTest extends TestCase
{
    private function fakeTmdbGenreList(Request $request): ?\Illuminate\Http\Client\Response
    {
        $url = $request->url();

        if (str_contains($url, '/genre/movie/list')) {
            return Http::response([
                'genres' => [
                    ['id' => 12, 'name' => 'ماجراجویی'],
                ],
            ], 200);
        }

        if (str_contains($url, '/genre/tv/list')) {
            return Http::response([
                'genres' => [
                    ['id' => 18, 'name' => 'درام'],
                    ['id' => 10759, 'name' => 'اکشن و ماجراجویی'],
                ],
            ], 200);
        }

        return null;
    }

    public function test_fetch_movie_falls_back_to_english_when_localized_fields_are_missing(): void
    {
        config()->set('services.tmdb.access_token', 'test-token');
        config()->set('services.tmdb.api_key', '');
        config()->set('services.tmdb.base_url', 'https://api.themoviedb.org/3');
        config()->set('services.tmdb.image_base_url', 'https://image.tmdb.org/t/p');
        config()->set('services.tmdb.default_language', 'fa-IR');
        config()->set('services.tmdb.verify_ssl', false);

        Http::fake(function (Request $request) {
            $genreResponse = $this->fakeTmdbGenreList($request);
            if ($genreResponse) {
                return $genreResponse;
            }

            $language = $request->data()['language'] ?? null;

            if ($language === 'fa-IR') {
                return Http::response([
                    'id' => 1226863,
                    'title' => 'Localized Title',
                    'original_title' => 'The Fallback Movie',
                    'overview' => '',
                    'poster_path' => '/poster.jpg',
                    'backdrop_path' => '/backdrop.jpg',
                    'release_date' => '2026-04-01',
                    'runtime' => 111,
                    'status' => 'Released',
                    'budget' => 150000000,
                    'revenue' => 420000000,
                    'vote_average' => 7.4,
                    'original_language' => 'en',
                    'genres' => [
                        ['id' => 12, 'name' => 'Adventure'],
                    ],
                    'spoken_languages' => [
                        ['english_name' => 'English'],
                    ],
                    'production_countries' => [
                        ['name' => 'United States of America', 'iso_3166_1' => 'US'],
                    ],
                    'videos' => [
                        'results' => [],
                    ],
                    'credits' => [
                        'cast' => [
                            [
                                'name' => 'Actor Local',
                                'character' => 'Hero',
                                'profile_path' => '/actor.jpg',
                            ],
                        ],
                        'crew' => [
                            ['name' => 'Director One', 'job' => 'Director'],
                            ['name' => 'Writer One', 'job' => 'Writer'],
                            ['name' => 'Writer Two', 'job' => 'Screenplay'],
                        ],
                    ],
                    'external_ids' => [
                        'imdb_id' => 'tt1234567',
                    ],
                    'keywords' => [
                        'keywords' => [
                            ['name' => 'space'],
                        ],
                    ],
                    'release_dates' => [
                        'results' => [
                            [
                                'iso_3166_1' => 'US',
                                'release_dates' => [
                                    ['certification' => 'PG-13'],
                                ],
                            ],
                        ],
                    ],
                ], 200);
            }

            return Http::response([
                'id' => 1226863,
                'title' => 'The Fallback Movie',
                'original_title' => 'The Fallback Movie',
                'overview' => 'English fallback overview.',
                'poster_path' => '/poster.jpg',
                'backdrop_path' => '/backdrop.jpg',
                'release_date' => '2026-04-01',
                'runtime' => 111,
                'status' => 'Released',
                'budget' => 150000000,
                'revenue' => 420000000,
                'vote_average' => 7.4,
                'original_language' => 'en',
                'genres' => [
                    ['id' => 12, 'name' => 'Adventure'],
                ],
                'spoken_languages' => [
                    ['english_name' => 'English'],
                ],
                'production_countries' => [
                    ['name' => 'United States of America', 'iso_3166_1' => 'US'],
                ],
                'videos' => [
                    'results' => [
                        [
                            'site' => 'YouTube',
                            'type' => 'Trailer',
                            'official' => true,
                            'key' => 'abc123',
                        ],
                    ],
                ],
                'credits' => [
                    'cast' => [
                        [
                            'name' => 'Actor One',
                            'character' => 'Hero',
                            'profile_path' => '/actor.jpg',
                        ],
                    ],
                    'crew' => [
                        ['name' => 'Director One', 'job' => 'Director'],
                        ['name' => 'Writer One', 'job' => 'Writer'],
                        ['name' => 'Writer Two', 'job' => 'Screenplay'],
                    ],
                ],
                'external_ids' => [
                    'imdb_id' => 'tt1234567',
                ],
                'keywords' => [
                    'keywords' => [
                        ['name' => 'space'],
                    ],
                ],
                'release_dates' => [
                    'results' => [
                        [
                            'iso_3166_1' => 'US',
                            'release_dates' => [
                                ['certification' => 'PG-13'],
                            ],
                        ],
                    ],
                ],
            ], 200);
        });

        $payload = app(TmdbService::class)->fetchMovie(1226863, 'fa-IR');

        $this->assertSame('Localized Title', $payload['title']);
        $this->assertSame('The Fallback Movie', $payload['original_title']);
        $this->assertSame('English fallback overview.', $payload['synopsis']);
        $this->assertSame('https://www.youtube.com/watch?v=abc123', $payload['trailer_url']);
        $this->assertSame('tt1234567', $payload['imdb_id']);
        $this->assertSame('PG-13', $payload['age_rating']);
        $this->assertSame(['Director One'], $payload['directors']);
        $this->assertSame(['Writer One', 'Writer Two'], $payload['writers']);
        $this->assertSame('Released', $payload['production_status']);
        $this->assertSame(150000000, $payload['budget']);
        $this->assertSame(420000000, $payload['revenue']);
        $this->assertSame(['ماجراجویی'], $payload['genres']);
        $this->assertSame(['space'], $payload['tags']);
        $this->assertSame(['English'], $payload['languages']);
        $this->assertSame(['United States of America'], $payload['countries']);
        $this->assertSame(['Actor Local'], $payload['cast_members']);
        $this->assertSame('Actor Local', $payload['actors'][0]['name']);
        $this->assertSame('https://image.tmdb.org/t/p/w300/actor.jpg', $payload['actors'][0]['profile_url']);

        Http::assertSentCount(3);
    }

    public function test_fetch_tv_returns_series_with_seasons_and_episodes(): void
    {
        config()->set('services.tmdb.access_token', 'test-token');
        config()->set('services.tmdb.api_key', '');
        config()->set('services.tmdb.base_url', 'https://api.themoviedb.org/3');
        config()->set('services.tmdb.image_base_url', 'https://image.tmdb.org/t/p');
        config()->set('services.tmdb.default_language', 'fa-IR');
        config()->set('services.tmdb.verify_ssl', false);

        Http::fake(function (Request $request) {
            $genreResponse = $this->fakeTmdbGenreList($request);
            if ($genreResponse) {
                return $genreResponse;
            }

            $url = $request->url();
            $language = $request->data()['language'] ?? null;

            if (str_contains($url, '/tv/124364/season/1/translations')) {
                return Http::response([
                    'translations' => [
                        [
                            'iso_639_1' => 'fa',
                            'data' => ['overview' => 'توضیح فصل به فارسی'],
                        ],
                    ],
                ], 200);
            }

            if (str_contains($url, '/tv/124364/season/1/episode/1/translations')) {
                return Http::response([
                    'translations' => [
                        [
                            'iso_639_1' => 'fa',
                            'data' => ['overview' => 'خلاصه قسمت به فارسی'],
                        ],
                    ],
                ], 200);
            }

            if (str_contains($url, '/tv/124364/season/1')) {
                if ($language === 'fa-IR') {
                    return Http::response([
                        'season_number' => 1,
                        'name' => 'ÙØµÙ„ ÛŒÚ©',
                        'overview' => '',
                        'air_date' => '2022-02-20',
                        'poster_path' => '/season.jpg',
                        'episodes' => [
                            [
                                'episode_number' => 1,
                                'name' => 'قسمت 1',
                                'overview' => '',
                                'air_date' => '2022-02-20',
                                'still_path' => '/still.jpg',
                                'runtime' => 52,
                            ],
                        ],
                    ], 200);
                }

                return Http::response([
                    'season_number' => 1,
                    'name' => 'Season 1',
                    'overview' => 'Season overview',
                    'air_date' => '2022-02-20',
                    'poster_path' => '/season.jpg',
                    'episodes' => [
                        [
                            'episode_number' => 1,
                            'name' => 'Long Day\'s Journey Into Night',
                            'overview' => 'Episode overview',
                            'air_date' => '2022-02-20',
                            'still_path' => '/still.jpg',
                            'runtime' => 52,
                        ],
                    ],
                ], 200);
            }

            if ($language === 'fa-IR') {
                return Http::response([
                    'id' => 124364,
                    'name' => 'From',
                    'original_name' => 'From',
                    'overview' => '',
                    'poster_path' => '/poster.jpg',
                    'backdrop_path' => '/backdrop.jpg',
                    'first_air_date' => '2022-02-20',
                    'last_air_date' => '2024-05-26',
                    'status' => 'Returning Series',
                    'type' => 'Scripted',
                    'number_of_seasons' => 1,
                    'number_of_episodes' => 1,
                    'episode_run_time' => [52],
                    'vote_average' => 7.7,
                    'original_language' => 'en',
                    'origin_country' => ['US'],
                    'networks' => [
                        ['name' => 'MGM+'],
                    ],
                    'created_by' => [
                        ['name' => 'John Griffin'],
                    ],
                    'videos' => [
                        'results' => [],
                    ],
                    'aggregate_credits' => [
                        'cast' => [
                            [
                                'name' => 'Harold Perrineau',
                                'roles' => [
                                    ['character' => 'Boyd Stevens'],
                                ],
                                'profile_path' => '/actor.jpg',
                            ],
                        ],
                        'crew' => [
                            [
                                'name' => 'Director One',
                                'jobs' => [
                                    ['job' => 'Director'],
                                ],
                            ],
                            [
                                'name' => 'Writer One',
                                'jobs' => [
                                    ['job' => 'Writer'],
                                ],
                            ],
                        ],
                    ],
                    'external_ids' => [
                        'imdb_id' => 'tt9813792',
                        'tvdb_id' => 414505,
                    ],
                    'keywords' => [
                        'results' => [
                            ['name' => 'mystery'],
                        ],
                    ],
                    'content_ratings' => [
                        'results' => [
                            ['iso_3166_1' => 'US', 'rating' => 'TV-MA'],
                        ],
                    ],
                    'seasons' => [
                        [
                            'season_number' => 0,
                            'name' => 'Specials',
                            'overview' => 'Special episodes',
                            'air_date' => '2022-01-01',
                            'episode_count' => 2,
                            'poster_path' => '/specials.jpg',
                        ],
                        [
                            'season_number' => 1,
                            'name' => 'Season 1',
                            'overview' => '',
                            'air_date' => '2022-02-20',
                            'episode_count' => 1,
                            'poster_path' => '/season.jpg',
                        ],
                    ],
                ], 200);
            }

            return Http::response([
                'id' => 124364,
                'name' => 'From',
                'original_name' => 'From',
                'overview' => 'Series overview',
                'poster_path' => '/poster.jpg',
                'backdrop_path' => '/backdrop.jpg',
                'first_air_date' => '2022-02-20',
                'last_air_date' => '2024-05-26',
                'status' => 'Returning Series',
                'type' => 'Scripted',
                'number_of_seasons' => 1,
                'number_of_episodes' => 1,
                'episode_run_time' => [52],
                'vote_average' => 7.7,
                'original_language' => 'en',
                'origin_country' => ['US'],
                'networks' => [
                    ['name' => 'MGM+'],
                ],
                'created_by' => [
                    ['name' => 'John Griffin'],
                ],
                'videos' => [
                    'results' => [
                        [
                            'site' => 'YouTube',
                            'type' => 'Trailer',
                            'official' => true,
                            'key' => 'series123',
                        ],
                    ],
                ],
                'aggregate_credits' => [
                    'cast' => [
                        [
                            'name' => 'Harold Perrineau',
                            'roles' => [
                                ['character' => 'Boyd Stevens'],
                            ],
                            'profile_path' => '/actor.jpg',
                        ],
                    ],
                    'crew' => [
                        [
                            'name' => 'Director One',
                            'jobs' => [
                                ['job' => 'Director'],
                            ],
                        ],
                        [
                            'name' => 'Writer One',
                            'jobs' => [
                                ['job' => 'Writer'],
                            ],
                        ],
                    ],
                ],
                'external_ids' => [
                    'imdb_id' => 'tt9813792',
                    'tvdb_id' => 414505,
                ],
                'keywords' => [
                    'results' => [
                        ['name' => 'mystery'],
                    ],
                ],
                'content_ratings' => [
                    'results' => [
                        ['iso_3166_1' => 'US', 'rating' => 'TV-MA'],
                    ],
                ],
                'seasons' => [
                    [
                        'season_number' => 0,
                        'name' => 'Specials',
                        'overview' => 'Special episodes',
                        'air_date' => '2022-01-01',
                        'episode_count' => 2,
                        'poster_path' => '/specials.jpg',
                    ],
                    [
                        'season_number' => 1,
                        'name' => 'Season 1',
                        'overview' => 'Season overview',
                        'air_date' => '2022-02-20',
                        'episode_count' => 1,
                        'poster_path' => '/season.jpg',
                    ],
                ],
            ], 200);
        });

        $payload = app(TmdbService::class)->fetchTv(124364, 'fa-IR');

        $this->assertSame('From', $payload['title']);
        $this->assertSame('Series overview', $payload['synopsis']);
        $this->assertSame('tt9813792', $payload['imdb_id']);
        $this->assertSame('414505', $payload['tvdb_id']);
        $this->assertSame('R', $payload['age_rating']);
        $this->assertSame('Returning Series', $payload['production_status']);
        $this->assertSame('Scripted', $payload['show_type']);
        $this->assertSame('MGM+', $payload['network']);
        $this->assertSame(['John Griffin'], $payload['creators']);
        $this->assertSame(['Director One'], $payload['directors']);
        $this->assertSame(['Writer One'], $payload['writers']);
        $this->assertSame('https://www.youtube.com/watch?v=series123', $payload['trailer_url']);
        $this->assertSame('Harold Perrineau', $payload['actors'][0]['name']);
        $this->assertSame('Boyd Stevens', $payload['actors'][0]['character']);
        $this->assertCount(1, $payload['seasons']);
        $this->assertSame(1, $payload['seasons'][0]['season_number']);
        $this->assertSame('ÙØµÙ„ ÛŒÚ©', $payload['seasons'][0]['name']);
        $this->assertSame(
            "Long Day's Journey Into Night",
            $payload['seasons'][0]['episodes'][0]['title'],
        );
        $this->assertSame('توضیح فصل به فارسی', $payload['seasons'][0]['synopsis']);
        $this->assertSame('Season overview', $payload['seasons'][0]['synopsis_en']);
        $this->assertSame('خلاصه قسمت به فارسی', $payload['seasons'][0]['episodes'][0]['synopsis']);
        $this->assertSame('Episode overview', $payload['seasons'][0]['episodes'][0]['synopsis_en']);
        $this->assertSame('https://image.tmdb.org/t/p/w780/still.jpg', $payload['seasons'][0]['episodes'][0]['still_url']);
    }

    public function test_fetch_tv_stores_english_synopsis_and_omits_persian_when_unavailable(): void
    {
        config()->set('services.tmdb.access_token', 'test-token');
        config()->set('services.tmdb.api_key', '');
        config()->set('services.tmdb.base_url', 'https://api.themoviedb.org/3');
        config()->set('services.tmdb.image_base_url', 'https://image.tmdb.org/t/p');
        config()->set('services.tmdb.default_language', 'fa-IR');
        config()->set('services.tmdb.verify_ssl', false);

        Http::fake(function (Request $request) {
            $genreResponse = $this->fakeTmdbGenreList($request);
            if ($genreResponse) {
                return $genreResponse;
            }

            $url = $request->url();
            $language = $request->data()['language'] ?? null;

            if (str_contains($url, '/tv/999/season/1/translations')) {
                return Http::response(['translations' => []], 200);
            }

            if (str_contains($url, '/tv/999/season/1/episode/1/translations')) {
                return Http::response(['translations' => []], 200);
            }

            if (str_contains($url, '/tv/999/season/1')) {
                if ($language === 'fa-IR') {
                    return Http::response([
                        'season_number' => 1,
                        'name' => 'فصل 1',
                        'overview' => '',
                        'air_date' => '2024-01-01',
                        'poster_path' => '/season.jpg',
                        'episodes' => [
                            [
                                'episode_number' => 1,
                                'name' => 'قسمت 1',
                                'overview' => '',
                                'air_date' => '2024-01-01',
                                'still_path' => '/still.jpg',
                                'runtime' => 45,
                            ],
                        ],
                    ], 200);
                }

                return Http::response([
                    'season_number' => 1,
                    'name' => 'Season 1',
                    'overview' => 'English season overview.',
                    'air_date' => '2024-01-01',
                    'poster_path' => '/season.jpg',
                    'episodes' => [
                        [
                            'episode_number' => 1,
                            'name' => 'Pilot',
                            'overview' => 'English episode overview.',
                            'air_date' => '2024-01-01',
                            'still_path' => '/still.jpg',
                            'runtime' => 45,
                        ],
                    ],
                ], 200);
            }

            if ($language === 'fa-IR') {
                return Http::response([
                    'id' => 999,
                    'name' => 'Test Show',
                    'original_name' => 'Test Show',
                    'overview' => '',
                    'poster_path' => '/poster.jpg',
                    'backdrop_path' => '/backdrop.jpg',
                    'first_air_date' => '2024-01-01',
                    'status' => 'Returning Series',
                    'number_of_seasons' => 1,
                    'number_of_episodes' => 1,
                    'episode_run_time' => [45],
                    'vote_average' => 8,
                    'original_language' => 'en',
                    'origin_country' => ['US'],
                    'genres' => [],
                    'spoken_languages' => [],
                    'networks' => [],
                    'created_by' => [],
                    'videos' => ['results' => []],
                    'aggregate_credits' => ['cast' => [], 'crew' => []],
                    'external_ids' => [],
                    'keywords' => ['results' => []],
                    'content_ratings' => ['results' => []],
                    'seasons' => [
                        [
                            'season_number' => 1,
                            'name' => 'Season 1',
                            'overview' => '',
                            'air_date' => '2024-01-01',
                            'episode_count' => 1,
                            'poster_path' => '/season.jpg',
                        ],
                    ],
                ], 200);
            }

            return Http::response([
                'id' => 999,
                'name' => 'Test Show',
                'original_name' => 'Test Show',
                'overview' => 'English series overview.',
                'poster_path' => '/poster.jpg',
                'backdrop_path' => '/backdrop.jpg',
                'first_air_date' => '2024-01-01',
                'status' => 'Returning Series',
                'number_of_seasons' => 1,
                'number_of_episodes' => 1,
                'episode_run_time' => [45],
                'vote_average' => 8,
                'original_language' => 'en',
                'origin_country' => ['US'],
                'genres' => [],
                'spoken_languages' => [],
                'networks' => [],
                'created_by' => [],
                'videos' => ['results' => []],
                'aggregate_credits' => ['cast' => [], 'crew' => []],
                'external_ids' => [],
                'keywords' => ['results' => []],
                'content_ratings' => ['results' => []],
                'seasons' => [
                    [
                        'season_number' => 1,
                        'name' => 'Season 1',
                        'overview' => 'English season overview from series list.',
                        'air_date' => '2024-01-01',
                        'episode_count' => 1,
                        'poster_path' => '/season.jpg',
                    ],
                ],
            ], 200);
        });

        $payload = app(TmdbService::class)->fetchTv(999, 'fa-IR');

        $this->assertNull($payload['seasons'][0]['synopsis']);
        $this->assertSame('English season overview.', $payload['seasons'][0]['synopsis_en']);
        $this->assertNull($payload['seasons'][0]['episodes'][0]['synopsis']);
        $this->assertSame('English episode overview.', $payload['seasons'][0]['episodes'][0]['synopsis_en']);
    }

    public function test_fetch_tv_auto_translates_season_and_episode_when_persian_missing(): void
    {
        config()->set('services.tmdb.access_token', 'test-token');
        config()->set('services.tmdb.api_key', '');
        config()->set('services.tmdb.base_url', 'https://api.themoviedb.org/3');
        config()->set('services.tmdb.image_base_url', 'https://image.tmdb.org/t/p');
        config()->set('services.tmdb.default_language', 'fa-IR');
        config()->set('services.tmdb.verify_ssl', false);
        config()->set('services.overview_translation.enabled', true);
        config()->set('services.overview_translation.driver', 'mymemory');

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'api.mymemory.translated.net')) {
                $english = (string) ($request->data()['q'] ?? '');

                return Http::response([
                    'responseData' => [
                        'translatedText' => match (true) {
                            str_contains($english, 'season') => 'خلاصهٔ فصل ترجمه‌شده.',
                            default => 'خلاصهٔ قسمت ترجمه‌شده.',
                        },
                    ],
                ], 200);
            }

            $genreResponse = $this->fakeTmdbGenreList($request);
            if ($genreResponse) {
                return $genreResponse;
            }

            $url = $request->url();
            $language = $request->data()['language'] ?? null;

            if (str_contains($url, '/tv/888/season/1/translations')) {
                return Http::response(['translations' => []], 200);
            }

            if (str_contains($url, '/tv/888/season/1/episode/1/translations')) {
                return Http::response(['translations' => []], 200);
            }

            if (str_contains($url, '/tv/888/season/1')) {
                if ($language === 'fa-IR') {
                    return Http::response([
                        'season_number' => 1,
                        'name' => 'فصل 1',
                        'overview' => '',
                        'air_date' => '2024-01-01',
                        'poster_path' => '/season.jpg',
                        'episodes' => [
                            [
                                'episode_number' => 1,
                                'name' => 'قسمت 1',
                                'overview' => '',
                                'air_date' => '2024-01-01',
                                'still_path' => '/still.jpg',
                                'runtime' => 45,
                            ],
                        ],
                    ], 200);
                }

                return Http::response([
                    'season_number' => 1,
                    'name' => 'Season 1',
                    'overview' => 'English season overview.',
                    'air_date' => '2024-01-01',
                    'poster_path' => '/season.jpg',
                    'episodes' => [
                        [
                            'episode_number' => 1,
                            'name' => 'Pilot',
                            'overview' => 'English episode overview.',
                            'air_date' => '2024-01-01',
                            'still_path' => '/still.jpg',
                            'runtime' => 45,
                        ],
                    ],
                ], 200);
            }

            if ($language === 'fa-IR') {
                return Http::response([
                    'id' => 888,
                    'name' => 'Test Show',
                    'original_name' => 'Test Show',
                    'overview' => '',
                    'poster_path' => '/poster.jpg',
                    'backdrop_path' => '/backdrop.jpg',
                    'first_air_date' => '2024-01-01',
                    'status' => 'Returning Series',
                    'number_of_seasons' => 1,
                    'number_of_episodes' => 1,
                    'episode_run_time' => [45],
                    'vote_average' => 8,
                    'original_language' => 'en',
                    'origin_country' => ['US'],
                    'genres' => [],
                    'spoken_languages' => [],
                    'networks' => [],
                    'created_by' => [],
                    'videos' => ['results' => []],
                    'aggregate_credits' => ['cast' => [], 'crew' => []],
                    'external_ids' => [],
                    'keywords' => ['results' => []],
                    'content_ratings' => ['results' => []],
                    'seasons' => [
                        [
                            'season_number' => 1,
                            'name' => 'Season 1',
                            'overview' => '',
                            'air_date' => '2024-01-01',
                            'episode_count' => 1,
                            'poster_path' => '/season.jpg',
                        ],
                    ],
                ], 200);
            }

            return Http::response([
                'id' => 888,
                'name' => 'Test Show',
                'original_name' => 'Test Show',
                'overview' => 'English series overview.',
                'poster_path' => '/poster.jpg',
                'backdrop_path' => '/backdrop.jpg',
                'first_air_date' => '2024-01-01',
                'status' => 'Returning Series',
                'number_of_seasons' => 1,
                'number_of_episodes' => 1,
                'episode_run_time' => [45],
                'vote_average' => 8,
                'original_language' => 'en',
                'origin_country' => ['US'],
                'genres' => [],
                'spoken_languages' => [],
                'networks' => [],
                'created_by' => [],
                'videos' => ['results' => []],
                'aggregate_credits' => ['cast' => [], 'crew' => []],
                'external_ids' => [],
                'keywords' => ['results' => []],
                'content_ratings' => ['results' => []],
                'seasons' => [
                    [
                        'season_number' => 1,
                        'name' => 'Season 1',
                        'overview' => 'English season overview.',
                        'air_date' => '2024-01-01',
                        'episode_count' => 1,
                        'poster_path' => '/season.jpg',
                    ],
                ],
            ], 200);
        });

        $payload = app(TmdbService::class)->fetchTv(888, 'fa-IR');

        $this->assertSame('خلاصهٔ فصل ترجمه‌شده.', $payload['seasons'][0]['synopsis']);
        $this->assertSame('English season overview.', $payload['seasons'][0]['synopsis_en']);
        $this->assertSame('خلاصهٔ قسمت ترجمه‌شده.', $payload['seasons'][0]['episodes'][0]['synopsis']);
        $this->assertSame('English episode overview.', $payload['seasons'][0]['episodes'][0]['synopsis_en']);
    }

    public function test_fetch_tv_stores_english_and_omits_persian_for_mislabeled_fa_payload(): void
    {
        config()->set('services.tmdb.access_token', 'test-token');
        config()->set('services.tmdb.api_key', '');
        config()->set('services.tmdb.base_url', 'https://api.themoviedb.org/3');
        config()->set('services.tmdb.image_base_url', 'https://image.tmdb.org/t/p');
        config()->set('services.tmdb.default_language', 'fa-IR');
        config()->set('services.tmdb.verify_ssl', false);

        Http::fake(function (Request $request) {
            $genreResponse = $this->fakeTmdbGenreList($request);
            if ($genreResponse) {
                return $genreResponse;
            }

            $url = $request->url();
            $language = $request->data()['language'] ?? null;

            if (str_contains($url, '/tv/1001/season/1/translations')) {
                return Http::response(['translations' => []], 200);
            }

            if (str_contains($url, '/tv/1001/season/1/episode/1/translations')) {
                return Http::response(['translations' => []], 200);
            }

            if (str_contains($url, '/tv/1001/season/1')) {
                return Http::response([
                    'season_number' => 1,
                    'name' => 'فصل 1',
                    'overview' => 'This is an English-only season overview from a mislabeled response.',
                    'air_date' => '2024-01-01',
                    'poster_path' => '/season.jpg',
                    'episodes' => [
                        [
                            'episode_number' => 1,
                            'name' => 'قسمت 1',
                            'overview' => 'This is an English-only episode overview from a mislabeled response.',
                            'air_date' => '2024-01-01',
                            'still_path' => '/still.jpg',
                            'runtime' => 45,
                        ],
                    ],
                ], 200);
            }

            return Http::response([
                'id' => 1001,
                'name' => 'Test Show',
                'original_name' => 'Test Show',
                'overview' => '',
                'poster_path' => '/poster.jpg',
                'backdrop_path' => '/backdrop.jpg',
                'first_air_date' => '2024-01-01',
                'status' => 'Returning Series',
                'number_of_seasons' => 1,
                'number_of_episodes' => 1,
                'episode_run_time' => [45],
                'vote_average' => 8,
                'original_language' => 'en',
                'origin_country' => ['US'],
                'genres' => [],
                'spoken_languages' => [],
                'networks' => [],
                'created_by' => [],
                'videos' => ['results' => []],
                'aggregate_credits' => ['cast' => [], 'crew' => []],
                'external_ids' => [],
                'keywords' => ['results' => []],
                'content_ratings' => ['results' => []],
                'seasons' => [
                    [
                        'season_number' => 1,
                        'name' => 'فصل 1',
                        'overview' => '',
                        'air_date' => '2024-01-01',
                        'episode_count' => 1,
                        'poster_path' => '/season.jpg',
                    ],
                ],
            ], 200);
        });

        $payload = app(TmdbService::class)->fetchTv(1001, 'fa-IR');

        $this->assertNull($payload['seasons'][0]['synopsis']);
        $this->assertStringContainsString('English-only season', (string) $payload['seasons'][0]['synopsis_en']);
        $this->assertNull($payload['seasons'][0]['episodes'][0]['synopsis']);
        $this->assertStringContainsString('English-only episode', (string) $payload['seasons'][0]['episodes'][0]['synopsis_en']);
    }

    public function test_fetch_tv_uses_english_episode_titles_when_fa_names_are_generic(): void
    {
        config()->set('services.tmdb.access_token', 'test-token');
        config()->set('services.tmdb.api_key', '');
        config()->set('services.tmdb.base_url', 'https://api.themoviedb.org/3');
        config()->set('services.tmdb.image_base_url', 'https://image.tmdb.org/t/p');
        config()->set('services.tmdb.default_language', 'fa-IR');
        config()->set('services.tmdb.verify_ssl', false);

        Http::fake(function (Request $request) {
            $genreResponse = $this->fakeTmdbGenreList($request);
            if ($genreResponse) {
                return $genreResponse;
            }

            $url = $request->url();
            $language = $request->data()['language'] ?? null;

            if (str_contains($url, '/tv/106379/season/1')) {
                if ($language === 'fa-IR') {
                    return Http::response([
                        'season_number' => 1,
                        'name' => 'فصل 1',
                        'overview' => 'خلاصه فصل به فارسی',
                        'air_date' => '2024-04-10',
                        'poster_path' => '/season.jpg',
                        'episodes' => [
                            [
                                'episode_number' => 1,
                                'name' => 'قسمت 1',
                                'overview' => 'خلاصه قسمت',
                                'air_date' => '2024-04-10',
                                'still_path' => '/still.jpg',
                                'runtime' => 60,
                            ],
                        ],
                    ], 200);
                }

                return Http::response([
                    'season_number' => 1,
                    'name' => 'Season 1',
                    'overview' => 'Season overview',
                    'air_date' => '2024-04-10',
                    'poster_path' => '/season.jpg',
                    'episodes' => [
                        [
                            'episode_number' => 1,
                            'name' => 'The End',
                            'overview' => 'Episode overview',
                            'air_date' => '2024-04-10',
                            'still_path' => '/still.jpg',
                            'runtime' => 60,
                        ],
                    ],
                ], 200);
            }

            if (str_contains($url, '/tv/106379') && !str_contains($url, '/season/')) {
                return Http::response([
                    'id' => 106379,
                    'name' => $language === 'fa-IR' ? 'Fallout' : 'Fallout',
                    'original_name' => 'Fallout',
                    'overview' => $language === 'fa-IR' ? 'خلاصه سریال' : 'Series overview',
                    'poster_path' => '/poster.jpg',
                    'backdrop_path' => '/backdrop.jpg',
                    'first_air_date' => '2024-04-10',
                    'status' => 'Returning Series',
                    'number_of_seasons' => 1,
                    'number_of_episodes' => 1,
                    'episode_run_time' => [60],
                    'vote_average' => 8.0,
                    'original_language' => 'en',
                    'videos' => [
                        'results' => $language === 'en-US'
                            ? [
                                [
                                    'site' => 'YouTube',
                                    'type' => 'Trailer',
                                    'official' => true,
                                    'key' => 'fallout123',
                                ],
                            ]
                            : [],
                    ],
                    'aggregate_credits' => ['cast' => [], 'crew' => []],
                    'external_ids' => ['imdb_id' => 'tt12637874'],
                    'keywords' => ['results' => []],
                    'content_ratings' => ['results' => []],
                    'seasons' => [
                        [
                            'season_number' => 1,
                            'name' => $language === 'fa-IR' ? 'فصل 1' : 'Season 1',
                            'overview' => $language === 'fa-IR' ? 'خلاصه فصل' : 'Season overview',
                            'air_date' => '2024-04-10',
                            'episode_count' => 1,
                            'poster_path' => '/season.jpg',
                        ],
                    ],
                ], 200);
            }

            return Http::response([], 404);
        });

        $payload = app(TmdbService::class)->fetchTv(106379, 'fa-IR');

        $this->assertSame('The End', $payload['seasons'][0]['episodes'][0]['title']);
    }

    public function test_fetch_tv_season_for_series_returns_single_season_payload(): void
    {
        config()->set('services.tmdb.access_token', 'test-token');
        config()->set('services.tmdb.api_key', '');
        config()->set('services.tmdb.base_url', 'https://api.themoviedb.org/3');
        config()->set('services.tmdb.image_base_url', 'https://image.tmdb.org/t/p');
        config()->set('services.tmdb.default_language', 'fa-IR');
        config()->set('services.tmdb.verify_ssl', false);

        Http::fake(function (Request $request) {
            $genreResponse = $this->fakeTmdbGenreList($request);
            if ($genreResponse) {
                return $genreResponse;
            }

            $url = $request->url();
            $language = $request->data()['language'] ?? null;

            if (str_contains($url, '/tv/106379/season/2')) {
                return Http::response([
                    'season_number' => 2,
                    'name' => 'Season 2',
                    'overview' => 'Season 2 overview',
                    'air_date' => '2024-04-17',
                    'poster_path' => '/season2.jpg',
                    'episodes' => [
                        [
                            'episode_number' => 1,
                            'name' => 'Episode Two Pilot',
                            'overview' => 'Episode overview',
                            'air_date' => '2024-04-17',
                            'still_path' => '/still2.jpg',
                            'runtime' => 58,
                        ],
                    ],
                ], 200);
            }

            if (str_contains($url, '/tv/106379') && !str_contains($url, '/season/')) {
                return Http::response([
                    'id' => 106379,
                    'name' => 'Fallout',
                    'overview' => 'Series overview',
                    'episode_run_time' => [58],
                    'seasons' => [
                        [
                            'season_number' => 2,
                            'name' => 'Season 2',
                            'episode_count' => 1,
                        ],
                    ],
                ], 200);
            }

            return Http::response([], 404);
        });

        $season = app(TmdbService::class)->fetchTvSeasonForSeries(106379, 2, 'fa-IR');

        $this->assertSame(2, $season['season_number']);
        $this->assertSame('Episode Two Pilot', $season['episodes'][0]['title']);
        $this->assertSame(1, $season['episodes'][0]['episode_number']);
    }
}
