<?php

namespace Tests\Feature;

use App\Models\Movie;
use App\Models\Series;
use App\Models\SeriesCast;
use App\Models\SeriesEpisode;
use App\Models\SeriesSeason;
use App\Models\Person;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GraphqlPublicApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_movies_query_returns_published_movie_cards(): void
    {
        Movie::query()->create([
            'title' => 'GraphQL Movie',
            'slug' => 'graphql-movie',
            'media_folder' => 'movies/graphql-movie',
            'publish_status' => 'published',
            'published_at' => now(),
            'metadata' => ['tmdb' => ['genres' => ['Action']]],
        ]);

        $response = $this->postJson('/graphql', [
            'query' => <<<'GQL'
                {
                  movies(first: 5) {
                    data {
                      slug
                      title
                    }
                    paginatorInfo {
                      total
                    }
                  }
                }
            GQL,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.movies.data.0.slug', 'graphql-movie')
            ->assertJsonMissingPath('errors');
    }

    public function test_movies_list_query_matches_frontend_shape_without_complexity_error(): void
    {
        Movie::query()->create([
            'title' => 'GraphQL Movie List',
            'slug' => 'graphql-movie-list',
            'media_folder' => 'movies/graphql-movie-list',
            'publish_status' => 'published',
            'published_at' => now(),
            'synopsis' => 'خلاصه فیلم',
            'has_subtitle' => true,
            'has_dubbed_audio' => false,
            'metadata' => [
                'tmdb' => [
                    'genres' => ['Action'],
                    'rating' => 7.5,
                    'backdrop_url' => 'https://image.tmdb.org/t/p/w780/backdrop.jpg',
                ],
            ],
        ]);

        $response = $this->postJson('/graphql', [
            'query' => <<<'GQL'
                query GetMoviesList($page: Int, $first: Int!, $genre: String, $search: String) {
                  moviesList(page: $page, first: $first, genre: $genre, search: $search) {
                    data {
                      id
                      slug
                      title
                      synopsis
                      release_year
                      has_subtitle
                      has_dubbed_audio
                      list_meta {
                        poster_url
                        backdrop_url
                        genres
                        rating
                      }
                    }
                  }
                }
            GQL,
            'variables' => [
                'page' => 1,
                'first' => 48,
                'genre' => null,
                'search' => null,
            ],
        ]);

        $response->assertOk();

        if ($response->json('errors')) {
            $this->fail(json_encode($response->json('errors'), JSON_UNESCAPED_UNICODE));
        }

        $response
            ->assertJsonPath('data.moviesList.data.0.slug', 'graphql-movie-list')
            ->assertJsonPath('data.moviesList.data.0.synopsis', 'خلاصه فیلم')
            ->assertJsonPath('data.moviesList.data.0.list_meta.rating', 7.5);
    }

    public function test_series_list_query_matches_frontend_shape_without_complexity_error(): void
    {
        Series::query()->create([
            'title' => 'GraphQL Series',
            'slug' => 'graphql-series',
            'publish_status' => 'published',
            'published_at' => now(),
            'synopsis' => 'خلاصه تست',
            'metadata' => ['tmdb' => ['genres' => ['Drama'], 'rating' => 8.1]],
        ]);

        $response = $this->postJson('/graphql', [
            'query' => <<<'GQL'
                query GetSeriesList($page: Int, $first: Int!, $search: String) {
                  seriesList(page: $page, first: $first, search: $search) {
                    data {
                      id
                      slug
                      title
                      synopsis
                      release_year
                      poster_url
                      backdrop_url
                      status
                      number_of_seasons
                      number_of_episodes
                      has_subtitle
                      has_dubbed
                      list_meta {
                        status_label_fa
                        genres
                        rating
                        detail_backdrop_url
                      }
                    }
                  }
                }
            GQL,
                'variables' => [
                'page' => 1,
                'first' => 24,
                'search' => null,
            ],
        ]);

        $response->assertOk();

        if ($response->json('errors')) {
            $this->fail(json_encode($response->json('errors'), JSON_UNESCAPED_UNICODE));
        }

        $response
            ->assertJsonPath('data.seriesList.data.0.slug', 'graphql-series')
            ->assertJsonPath('data.seriesList.data.0.synopsis', 'خلاصه تست');
    }

    public function test_series_overview_query_matches_frontend_shape_without_complexity_error(): void
    {
        $series = Series::query()->create([
            'title' => 'Overview Series',
            'slug' => 'overview-series',
            'publish_status' => 'published',
            'published_at' => now(),
            'metadata' => [
                'tmdb' => [
                    'creators' => ['Creator One'],
                    'directors' => ['Director One'],
                    'writers' => ['Writer One'],
                    'genres' => ['Drama'],
                ],
            ],
        ]);

        $person = Person::query()->create([
            'name' => 'Actor One',
            'slug' => 'actor-one',
        ]); // table: persons

        SeriesCast::query()->create([
            'series_id' => $series->id,
            'person_id' => $person->id,
            'character_name' => 'Hero',
            'cast_order' => 1,
        ]);

        $season = SeriesSeason::query()->create([
            'series_id' => $series->id,
            'season_number' => 1,
            'name' => 'Season 1',
        ]);

        SeriesEpisode::query()->create([
            'series_id' => $series->id,
            'series_season_id' => $season->id,
            'season_number' => 1,
            'episode_number' => 1,
            'title' => 'Episode 1',
            'slug' => 'overview-series-s01e01',
            'subtitle_url' => 'https://cdn.example.com/sub.vtt',
            'publish_status' => 'published',
        ]);

        $response = $this->postJson('/graphql', [
            'query' => $this->frontendSeriesOverviewQuery(),
            'variables' => ['slug' => 'overview-series'],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.seriesOverview.slug', 'overview-series')
            ->assertJsonPath('data.seriesOverview.details.creators.0', 'Creator One')
            ->assertJsonPath('data.seriesOverview.cast.0.name', 'Actor One')
            ->assertJsonMissingPath('errors');
    }

    private function frontendSeriesOverviewQuery(): string
    {
        return <<<'GQL'
            query GetSeriesOverview($slug: String!) {
              seriesOverview(slug: $slug) {
                id
                slug
                title
                original_title
                synopsis
                release_year
                first_air_date
                last_air_date
                status
                status_label_fa
                episode_runtime_minutes
                number_of_seasons
                number_of_episodes
                network
                poster_url
                backdrop_url
                trailer_url
                age_rating
                has_subtitle
                has_dubbed
                total_seasons
                seasons {
                  id
                  season_number
                  title
                }
                cast {
                  name
                  slug
                  character_name
                  profile_url
                  order
                }
                crew {
                  name
                  slug
                  job
                  department
                  profile_url
                }
                details {
                  backdrop_url
                  rating
                  original_language
                  creators
                  directors
                  writers
                  genres
                  languages
                  countries
                  actors {
                    name
                    character
                    profile_url
                  }
                  gallery
                }
              }
            }
        GQL;
    }

    public function test_watch_movie_query_returns_playback_payload(): void
    {
        Movie::query()->create([
            'title' => 'Watch Me',
            'slug' => 'watch-me',
            'media_folder' => 'movies/watch-me',
            'publish_status' => 'published',
            'published_at' => now(),
            'metadata' => [
                'playback' => [
                    'hls_url' => 'https://cinema.af/movies/watch-me/master.m3u8',
                ],
            ],
        ]);

        $response = $this->postJson('/graphql', [
            'query' => <<<'GQL'
                query ($slug: String!) {
                  watchMovie(slug: $slug) {
                    hls_url
                  }
                }
            GQL,
            'variables' => ['slug' => 'watch-me'],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.watchMovie.hls_url', 'https://cinema.af/movies/watch-me/master.m3u8')
            ->assertJsonMissingPath('errors');
    }

    public function test_watch_movie_query_hides_unverified_dash_url(): void
    {
        Movie::query()->create([
            'title' => 'Sinners',
            'slug' => 'sinners',
            'media_folder' => 'movies/sinners',
            'publish_status' => 'published',
            'published_at' => now(),
            'metadata' => [
                'playback' => [
                    'hls_url' => 'http://cinema.af/content2/movies2/sinners/master.m3u8',
                    'dash_url' => 'http://cinema.af/content2/movies2/sinners/manifest.mpd',
                    'dash_verified' => false,
                ],
            ],
        ]);

        $response = $this->postJson('/graphql', [
            'query' => <<<'GQL'
                query ($slug: String!) {
                  watchMovie(slug: $slug) {
                    hls_url
                    dash_url
                  }
                }
            GQL,
            'variables' => ['slug' => 'sinners'],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.watchMovie.hls_url', 'http://cinema.af/content2/movies2/sinners/master.m3u8')
            ->assertJsonPath('data.watchMovie.dash_url', null)
            ->assertJsonMissingPath('errors');
    }

    public function test_watch_movie_query_omits_hls_playlist_from_dubbed_audio_url(): void
    {
        Movie::query()->create([
            'title' => 'Sinners Dub',
            'slug' => 'sinners-dub',
            'media_folder' => 'movies/sinners-dub',
            'publish_status' => 'published',
            'published_at' => now(),
            'dubbed_source_path' => 'http://cinema.af/content2/sinners/audio/fa/index.m3u8',
            'has_dubbed_audio' => true,
            'metadata' => [
                'playback' => [
                    'hls_url' => 'http://cinema.af/content2/sinners/master.m3u8',
                ],
            ],
        ]);

        $response = $this->postJson('/graphql', [
            'query' => <<<'GQL'
                query ($slug: String!) {
                  watchMovie(slug: $slug) {
                    dubbed_audio_url
                    audio_tracks {
                      name
                      language
                      type
                    }
                  }
                }
            GQL,
            'variables' => ['slug' => 'sinners-dub'],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.watchMovie.dubbed_audio_url', null)
            ->assertJsonMissingPath('errors');
    }

    public function test_watch_movie_query_returns_progressive_url_without_hls(): void
    {
        Movie::query()->create([
            'title' => 'Progressive Watch',
            'slug' => 'progressive-watch',
            'media_folder' => 'movies/progressive-watch',
            'publish_status' => 'published',
            'published_at' => now(),
            'metadata' => [
                'playback' => [
                    'protocol' => 'mp4',
                    'progressive_url' => 'https://cdn.example.com/movies/progressive-watch/video.mp4',
                ],
            ],
        ]);

        $response = $this->postJson('/graphql', [
            'query' => <<<'GQL'
                query ($slug: String!) {
                  watchMovie(slug: $slug) {
                    hls_url
                    progressive_url
                  }
                }
            GQL,
            'variables' => ['slug' => 'progressive-watch'],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.watchMovie.hls_url', null)
            ->assertJsonPath(
                'data.watchMovie.progressive_url',
                'https://cdn.example.com/movies/progressive-watch/video.mp4'
            )
            ->assertJsonMissingPath('errors');
    }
}
