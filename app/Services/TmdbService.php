<?php

namespace App\Services;

use App\Services\Concerns\InteractsWithTmdbHttp;
use App\Support\CinemaSynopsisHelper;
use App\Support\OutboundHttpClient;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

class TmdbService
{
    use InteractsWithTmdbHttp;
    private const APPEND_TO_RESPONSE = 'videos,credits,external_ids,keywords,release_dates,images';
    private const TV_APPEND_TO_RESPONSE = 'videos,aggregate_credits,external_ids,keywords,content_ratings,images';

    /** @var array<string, array<int, string>> */
    private array $genreNameMapCache = [];

    public function __construct(
        private readonly CinemaSynopsisHelper $synopsisHelper,
    ) {
    }

    private function displayLanguageFa(?string $iso6391, ?string $name, ?string $englishName): ?string
    {
        $iso = strtolower(trim((string) ($iso6391 ?? '')));
        $candidateName = trim((string) ($name ?? ''));
        $candidateEnglish = trim((string) ($englishName ?? ''));

        if ($iso !== '' && class_exists(\Locale::class) && method_exists(\Locale::class, 'getDisplayLanguage')) {
            try {
                $display = (string) \Locale::getDisplayLanguage($iso, 'fa');
                if (trim($display) !== '' && strtolower(trim($display)) !== $iso) {
                    return $display;
                }
            } catch (\Throwable) {
                // ignore intl failures
            }
        }

        return $candidateName !== '' ? $candidateName : ($candidateEnglish !== '' ? $candidateEnglish : null);
    }

    private function displayCountryFa(?string $iso3166_1, ?string $name): ?string
    {
        $iso = strtoupper(trim((string) ($iso3166_1 ?? '')));
        $candidateName = trim((string) ($name ?? ''));
        $manual = [
            'US' => 'آمریکا',
            'GB' => 'بریتانیا',
            'KR' => 'کره جنوبی',
        ];

        if (isset($manual[$iso])) {
            return $manual[$iso];
        }

        if ($iso !== '' && class_exists(\Locale::class) && method_exists(\Locale::class, 'getDisplayRegion')) {
            try {
                $display = (string) \Locale::getDisplayRegion('und_'.$iso, 'fa');
                if (trim($display) !== '' && strtoupper(trim($display)) !== $iso) {
                    return $display;
                }
            } catch (\Throwable) {
                // ignore intl failures
            }
        }

        return $candidateName !== '' ? $candidateName : null;
    }

    /**
     * Prefer ISO codes so admin taxonomy selects (and catalog filters) can match exactly.
     * Display names stay available via reference options / Locale on the client.
     *
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $spokenLanguages
     * @return array<int, string>
     */
    private function normalizeSpokenLanguagesFa(\Illuminate\Support\Collection $spokenLanguages): array
    {
        $isos = [];

        foreach ($spokenLanguages as $item) {
            if (! is_array($item)) {
                continue;
            }

            $iso = strtolower(trim((string) ($item['iso_639_1'] ?? '')));
            if ($iso === '' || isset($isos[$iso])) {
                continue;
            }

            $isos[$iso] = $iso;
        }

        if ($isos !== []) {
            return array_values($isos);
        }

        // Legacy fallback: display labels when ISO is missing from TMDB payload.
        $fallback = [];
        foreach ($spokenLanguages as $item) {
            if (! is_array($item)) {
                continue;
            }

            $name = $this->displayLanguageFa(
                $item['iso_639_1'] ?? null,
                $item['name'] ?? null,
                $item['english_name'] ?? null,
            );

            if (! $name) {
                continue;
            }

            $fallback[$name] = $name;
        }

        return array_values($fallback);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>|string>  $productionCountries
     * @return array<int, string>
     */
    private function normalizeCountryCodes(\Illuminate\Support\Collection $productionCountries): array
    {
        $isos = [];

        foreach ($productionCountries as $row) {
            if (is_string($row)) {
                $iso = strtoupper(trim($row));
            } elseif (is_array($row)) {
                $iso = strtoupper(trim((string) ($row['iso_3166_1'] ?? '')));
            } else {
                continue;
            }

            if ($iso === '' || isset($isos[$iso])) {
                continue;
            }

            $isos[$iso] = $iso;
        }

        return array_values($isos);
    }

    public function search(string $query, ?string $language = null): array
    {
        $query = trim($query);

        if ($query === '') {
            throw new InvalidArgumentException('A TMDB search query is required.');
        }

        $language = $this->resolveLanguage($language);

        if (preg_match('/^tt\d{5,}$/i', $query) === 1) {
            $response = $this->request("/find/{$query}", [
                'external_source' => 'imdb_id',
                'language' => $language,
            ]);

            return collect($response['movie_results'] ?? [])
                ->map(fn (array $item) => $this->normalizeSearchResult($item))
                ->filter(fn (array $item) => $item['tmdb_id'] !== null)
                ->values()
                ->all();
        }

        $response = $this->request('/search/movie', [
            'query' => $query,
            'language' => $language,
            'include_adult' => 'false',
            'page' => 1,
        ]);

        return collect($response['results'] ?? [])
            ->map(fn (array $item) => $this->normalizeSearchResult($item))
            ->filter(fn (array $item) => $item['tmdb_id'] !== null)
            ->take(10)
            ->values()
            ->all();
    }

    public function searchTv(string $query, ?string $language = null): array
    {
        $query = trim($query);

        if ($query === '') {
            throw new InvalidArgumentException('A TMDB search query is required.');
        }

        $language = $this->resolveLanguage($language);

        $response = $this->request('/search/tv', [
            'query' => $query,
            'language' => $language,
            'include_adult' => 'false',
            'page' => 1,
        ]);

        return collect($response['results'] ?? [])
            ->map(fn (array $item) => $this->normalizeTvSearchResult($item))
            ->filter(fn (array $item) => $item['tmdb_id'] !== null)
            ->take(10)
            ->values()
            ->all();
    }

    public function fetchMovie(int $tmdbId, ?string $language = null): array
    {
        if ($tmdbId <= 0) {
            throw new InvalidArgumentException('A valid TMDB ID is required.');
        }

        $language = $this->resolveLanguage($language);
        $bundles = $this->fetchPrimaryAndEnglishParallel("/movie/{$tmdbId}", $language, [
            'append_to_response' => self::APPEND_TO_RESPONSE,
        ]);
        $payload = $bundles['primary'];
        $fallbackPayload = $bundles['fallback'];

        $cast = collect($payload['credits']['cast'] ?? []);
        $crew = collect($payload['credits']['crew'] ?? []);

        if ($cast->isEmpty() && $fallbackPayload) {
            $cast = collect($fallbackPayload['credits']['cast'] ?? []);
        }

        if ($crew->isEmpty() && $fallbackPayload) {
            $crew = collect($fallbackPayload['credits']['crew'] ?? []);
        }

        $englishCreditsPayload = $this->resolveEnglishCreditsPayload('movie', $tmdbId, $language, $fallbackPayload);
        $englishCastById = collect($englishCreditsPayload['credits']['cast'] ?? [])->keyBy('id');
        $englishCrewById = collect($englishCreditsPayload['credits']['crew'] ?? [])->keyBy('id');

        $keywords = collect($payload['keywords']['keywords'] ?? $payload['keywords']['results'] ?? []);

        if ($keywords->isEmpty() && $fallbackPayload) {
            $keywords = collect($fallbackPayload['keywords']['keywords'] ?? $fallbackPayload['keywords']['results'] ?? []);
        }

        $genres = collect($payload['genres'] ?? []);
        $spokenLanguages = collect($payload['spoken_languages'] ?? []);
        $productionCountries = collect($payload['production_countries'] ?? []);

        if ($fallbackPayload) {
            if ($genres->isEmpty()) {
                $genres = collect($this->fetchLocalizedGenres('movie', $tmdbId));
            }

            if ($spokenLanguages->isEmpty()) {
                $spokenLanguages = collect($fallbackPayload['spoken_languages'] ?? []);
            }

            if ($productionCountries->isEmpty()) {
                $productionCountries = collect($fallbackPayload['production_countries'] ?? []);
            }
        }

        $localizedSynopsis = $this->localizeMainSynopsis(
            "/movie/{$tmdbId}",
            $payload,
            $fallbackPayload,
        );

        return [
            'tmdb_id' => $payload['id'] ?? $fallbackPayload['id'] ?? null,
            'imdb_id' => $this->firstFilledString(
                $payload['external_ids']['imdb_id'] ?? null,
                $payload['imdb_id'] ?? null,
                $fallbackPayload['external_ids']['imdb_id'] ?? null,
                $fallbackPayload['imdb_id'] ?? null
            ),
            'title' => $this->firstFilledString(
                $payload['title'] ?? null,
                $fallbackPayload['title'] ?? null,
                $payload['original_title'] ?? null,
                $fallbackPayload['original_title'] ?? null
            ),
            'original_title' => $this->firstFilledString(
                $payload['original_title'] ?? null,
                $fallbackPayload['original_title'] ?? null,
                $payload['title'] ?? null,
                $fallbackPayload['title'] ?? null
            ),
            'original_language' => $payload['original_language'] ?? $fallbackPayload['original_language'] ?? null,
            'synopsis' => $localizedSynopsis['synopsis'],
            'synopsis_en' => $localizedSynopsis['synopsis_en'],
            'poster_url' => $this->buildImageUrl($payload['poster_path'] ?? $fallbackPayload['poster_path'] ?? null, 'w780'),
            'backdrop_url' => $this->buildImageUrl($payload['backdrop_path'] ?? $fallbackPayload['backdrop_path'] ?? null, 'original'),
            'trailer_url' => $this->resolveTrailerUrl(
                $payload['videos']['results'] ?? [],
                $fallbackPayload['videos']['results'] ?? []
            ),
            'genres' => $this->resolveGenreLabels($genres->all(), 'movie', 'fa-IR'),
            'tags' => $keywords->pluck('name')->filter()->take(12)->values()->all(),
            'languages' => $this->normalizeSpokenLanguagesFa($spokenLanguages),
            'countries' => $this->normalizeCountryCodes($productionCountries),
            'release_year' => $this->extractReleaseYear(
                $payload['release_date'] ?? $fallbackPayload['release_date'] ?? null
            ),
            'rating' => isset($payload['vote_average'])
                ? round((float) $payload['vote_average'], 1)
                : (isset($fallbackPayload['vote_average']) ? round((float) $fallbackPayload['vote_average'], 1) : null),
            'age_rating' => $this->resolveAgeRating($payload, $fallbackPayload),
            'runtime_minutes' => $payload['runtime'] ?? $fallbackPayload['runtime'] ?? null,
            'directors' => $this->resolveCrewNames($crew, ['Director'], $englishCrewById),
            'writers' => $this->resolveCrewNames($crew, ['Writer', 'Screenplay', 'Story', 'Author', 'Novel'], $englishCrewById),
            'production_status' => $this->firstFilledString(
                $payload['status'] ?? null,
                $fallbackPayload['status'] ?? null
            ),
            'budget' => isset($payload['budget'])
                ? (int) $payload['budget']
                : (isset($fallbackPayload['budget']) ? (int) $fallbackPayload['budget'] : null),
            'revenue' => isset($payload['revenue'])
                ? (int) $payload['revenue']
                : (isset($fallbackPayload['revenue']) ? (int) $fallbackPayload['revenue'] : null),
            'cast_members' => $cast
                ->map(fn (array $item) => $this->resolveEnglishPersonName($item, $englishCastById))
                ->filter()
                ->take(15)
                ->values()
                ->all(),
            'actors' => $cast->take(15)->map(fn (array $item) => [
                'name' => $this->resolveEnglishPersonName($item, $englishCastById) ?: null,
                'character' => $item['character'] ?? null,
                'profile_url' => $this->buildImageUrl($item['profile_path'] ?? null, 'w300'),
            ])->values()->all(),
            'overview' => $localizedSynopsis['synopsis'] ?? $this->firstFilledString(
                $payload['overview'] ?? null,
                $fallbackPayload['overview'] ?? null
            ),
            'release_date' => $this->firstFilledString(
                $payload['release_date'] ?? null,
                $fallbackPayload['release_date'] ?? null
            ),
            'vote_average' => isset($payload['vote_average'])
                ? round((float) $payload['vote_average'], 3)
                : (isset($fallbackPayload['vote_average']) ? round((float) $fallbackPayload['vote_average'], 3) : null),
            'vote_count' => (int) ($payload['vote_count'] ?? $fallbackPayload['vote_count'] ?? 0),
            'popularity' => isset($payload['popularity'])
                ? round((float) $payload['popularity'], 3)
                : (isset($fallbackPayload['popularity']) ? round((float) $fallbackPayload['popularity'], 3) : null),
            'cast' => $cast->sortBy(fn (array $row) => $row['order'] ?? 999)->take(20)->values()->map(fn (array $item) => [
                'tmdb_person_id' => (int) ($item['id'] ?? 0),
                'name' => $this->resolveEnglishPersonName($item, $englishCastById),
                'character' => (string) ($item['character'] ?? ''),
                'profile_url' => $this->buildImageUrl($item['profile_path'] ?? null, 'w500'),
                'credit_id' => (string) ($item['credit_id'] ?? ''),
                'order' => (int) ($item['order'] ?? 0),
                'known_for_department' => (string) ($item['known_for_department'] ?? 'Acting'),
            ])->filter(fn (array $row) => $row['name'] !== '')->values()->all(),
            'crew' => $crew->filter(fn (array $row) => $this->movieCrewMatchesDisplayFilter($row))->values()->map(function (array $item) use ($englishCrewById) {
                $name = $this->resolveEnglishPersonName($item, $englishCrewById);

                if ($name === '') {
                    return null;
                }

                return [
                    'tmdb_person_id' => (int) ($item['id'] ?? 0),
                    'name' => $name,
                    'job' => trim((string) ($item['job'] ?? '')),
                    'department' => trim((string) ($item['department'] ?? '')),
                    'profile_url' => $this->buildImageUrl($item['profile_path'] ?? null, 'w500'),
                    'credit_id' => (string) ($item['credit_id'] ?? ''),
                ];
            })->filter()->values()->all(),
            'production_companies' => collect($payload['production_companies'] ?? $fallbackPayload['production_companies'] ?? [])
                ->map(fn (array $row) => ['name' => (string) ($row['name'] ?? '')])
                ->filter(fn (array $row) => $row['name'] !== '')
                ->values()
                ->all(),
            'production_countries' => $productionCountries
                ->map(fn (array $row) => [
                    'iso_3166_1' => (string) ($row['iso_3166_1'] ?? ''),
                    'name' => $this->displayCountryFa(
                        $row['iso_3166_1'] ?? null,
                        $row['name'] ?? null,
                    ) ?? '',
                ])
                ->filter(fn (array $row) => $row['name'] !== '')
                ->values()
                ->all(),
            'spoken_languages' => $spokenLanguages
                ->map(fn (array $item) => [
                    'iso_639_1' => (string) ($item['iso_639_1'] ?? ''),
                    'name' => $this->displayLanguageFa(
                        $item['iso_639_1'] ?? null,
                        $item['name'] ?? null,
                        $item['english_name'] ?? null,
                    ) ?? '',
                ])
                ->filter(fn (array $row) => $row['name'] !== '')
                ->values()
                ->all(),
            'belongs_to_collection' => $this->mapBelongsToCollection(
                $payload['belongs_to_collection'] ?? $fallbackPayload['belongs_to_collection'] ?? null
            ),
        ];
    }

    /**
     * Lightweight TMDB lookup — only belongs_to_collection (no credits/images append).
     *
     * @return ?array<string, mixed>
     */
    public function fetchMovieBelongsToCollection(int $tmdbId, ?string $language = null): ?array
    {
        if ($tmdbId <= 0) {
            return null;
        }

        $language = $this->resolveLanguage($language);
        $payload = $this->request("/movie/{$tmdbId}", [
            'language' => $language,
        ]);

        return $this->mapBelongsToCollection($payload['belongs_to_collection'] ?? null);
    }

    public function fetchCollection(int $tmdbCollectionId, ?string $language = null): array
    {
        if ($tmdbCollectionId <= 0) {
            throw new InvalidArgumentException('A valid TMDB collection ID is required.');
        }

        $language = $this->resolveLanguage($language);
        $payload = $this->request("/collection/{$tmdbCollectionId}", [
            'language' => $language,
        ]);

        return [
            'tmdb_collection_id' => (int) ($payload['id'] ?? $tmdbCollectionId),
            'name' => (string) ($payload['name'] ?? ''),
            'description' => filled($payload['overview'] ?? null) ? (string) $payload['overview'] : null,
            'poster_url' => $this->buildImageUrl($payload['poster_path'] ?? null, 'w780'),
            'backdrop_url' => $this->buildImageUrl($payload['backdrop_path'] ?? null, 'original'),
            'parts' => collect($payload['parts'] ?? [])
                ->map(fn (array $part) => [
                    'tmdb_id' => (int) ($part['id'] ?? 0),
                    'title' => (string) ($part['title'] ?? ''),
                    'release_year' => $this->extractReleaseYear($part['release_date'] ?? null),
                    'poster_url' => $this->buildImageUrl($part['poster_path'] ?? null, 'w342'),
                ])
                ->filter(fn (array $row) => $row['tmdb_id'] > 0)
                ->values()
                ->all(),
        ];
    }

    /**
     * @return ?array<string, mixed>
     */
    private function mapBelongsToCollection(mixed $raw): ?array
    {
        if (! is_array($raw) || empty($raw['id'])) {
            return null;
        }

        return [
            'tmdb_collection_id' => (int) $raw['id'],
            'name' => (string) ($raw['name'] ?? ''),
            'poster_url' => $this->buildImageUrl($raw['poster_path'] ?? null, 'w780'),
            'backdrop_url' => $this->buildImageUrl($raw['backdrop_path'] ?? null, 'original'),
        ];
    }

    public function fetchTv(int $tmdbId, ?string $language = null, bool $includeSeasons = true): array
    {
        if ($tmdbId <= 0) {
            throw new InvalidArgumentException('A valid TMDB TV ID is required.');
        }

        $language = $this->resolveLanguage($language);
        $bundles = $this->fetchPrimaryAndEnglishParallel("/tv/{$tmdbId}", $language, [
            'append_to_response' => self::TV_APPEND_TO_RESPONSE,
        ]);
        $payload = $bundles['primary'];
        $fallbackPayload = $bundles['fallback'];

        $aggregateCredits = $payload['aggregate_credits'] ?? [];

        if ($aggregateCredits === [] && $fallbackPayload) {
            $aggregateCredits = $fallbackPayload['aggregate_credits'] ?? [];
        }

        $cast = collect($aggregateCredits['cast'] ?? []);
        $crew = collect($aggregateCredits['crew'] ?? []);
        $englishCreditsPayload = $this->resolveEnglishCreditsPayload('tv', $tmdbId, $language, $fallbackPayload);
        $englishCastById = collect($englishCreditsPayload['aggregate_credits']['cast'] ?? [])->keyBy('id');
        $englishCrewById = collect($englishCreditsPayload['aggregate_credits']['crew'] ?? [])->keyBy('id');
        $englishCreatedByById = collect($englishCreditsPayload['created_by'] ?? [])->keyBy('id');
        $keywords = collect($payload['keywords']['results'] ?? $payload['keywords'] ?? []);
        $genres = collect($payload['genres'] ?? []);
        $languages = collect($payload['spoken_languages'] ?? []);
        $countries = collect($payload['origin_country'] ?? []);
        $networks = collect($payload['networks'] ?? []);
        $seasons = collect($payload['seasons'] ?? []);

        if ($fallbackPayload) {
            if ($keywords->isEmpty()) {
                $keywords = collect($fallbackPayload['keywords']['results'] ?? $fallbackPayload['keywords'] ?? []);
            }

            if ($genres->isEmpty()) {
                $genres = collect($this->fetchLocalizedGenres('tv', $tmdbId));
            }

            if ($languages->isEmpty()) {
                $languages = collect($fallbackPayload['spoken_languages'] ?? []);
            }

            if ($countries->isEmpty()) {
                $countries = collect($fallbackPayload['origin_country'] ?? []);
            }

            if ($networks->isEmpty()) {
                $networks = collect($fallbackPayload['networks'] ?? []);
            }

            if ($seasons->isEmpty()) {
                $seasons = collect($fallbackPayload['seasons'] ?? []);
            }
        }

        $seasonList = $seasons
            ->filter(
                fn (array $season) => isset($season['season_number'])
                    && (int) ($season['season_number'] ?? 0) > 0
            )
            ->sortBy(fn (array $season) => (int) ($season['season_number'] ?? 0))
            ->values();

        $seasonNumbers = $seasonList
            ->map(fn (array $season) => (int) ($season['season_number'] ?? 0))
            ->all();

        if ($includeSeasons) {
            $seasonDetails = $this->buildTvSeasonDetailsParallel(
                $tmdbId,
                $seasonList->all(),
                $language,
                $payload,
                $fallbackPayload,
            );
        } else {
            $seasonDetails = [];
        }

        $numberOfEpisodes = $includeSeasons
            ? collect($seasonDetails)->sum(fn (array $season) => count($season['episodes'] ?? []))
            : (int) ($payload['number_of_episodes'] ?? $fallbackPayload['number_of_episodes'] ?? 0);

        $localizedSynopsis = $this->localizeMainSynopsis(
            "/tv/{$tmdbId}",
            $payload,
            $fallbackPayload,
        );

        return [
            'tmdb_id' => $payload['id'] ?? $fallbackPayload['id'] ?? null,
            'tmdb_query' => (string) ($payload['id'] ?? $fallbackPayload['id'] ?? ''),
            'imdb_id' => $this->firstFilledString(
                $payload['external_ids']['imdb_id'] ?? null,
                $fallbackPayload['external_ids']['imdb_id'] ?? null,
            ),
            'tvdb_id' => $this->firstFilledString(
                isset($payload['external_ids']['tvdb_id']) ? (string) $payload['external_ids']['tvdb_id'] : null,
                isset($fallbackPayload['external_ids']['tvdb_id']) ? (string) $fallbackPayload['external_ids']['tvdb_id'] : null,
            ),
            'title' => $this->firstFilledString(
                $payload['name'] ?? null,
                $fallbackPayload['name'] ?? null,
                $payload['original_name'] ?? null,
                $fallbackPayload['original_name'] ?? null,
            ),
            'original_title' => $this->firstFilledString(
                $payload['original_name'] ?? null,
                $fallbackPayload['original_name'] ?? null,
                $payload['name'] ?? null,
                $fallbackPayload['name'] ?? null,
            ),
            'original_language' => $payload['original_language'] ?? $fallbackPayload['original_language'] ?? null,
            'synopsis' => $localizedSynopsis['synopsis'],
            'synopsis_en' => $localizedSynopsis['synopsis_en'],
            'poster_url' => $this->buildImageUrl($payload['poster_path'] ?? $fallbackPayload['poster_path'] ?? null, 'w780'),
            'backdrop_url' => $this->buildImageUrl($payload['backdrop_path'] ?? $fallbackPayload['backdrop_path'] ?? null, 'original'),
            'trailer_url' => $this->resolveTrailerUrl(
                $payload['videos']['results'] ?? [],
                $fallbackPayload['videos']['results'] ?? [],
            ),
            'genres' => $this->resolveGenreLabels($genres->all(), 'tv', 'fa-IR'),
            'tags' => $keywords->pluck('name')->filter()->take(12)->values()->all(),
            'languages' => $this->normalizeSpokenLanguagesFa($languages),
            'countries' => $this->normalizeCountryCodes($countries),
            'release_year' => $this->extractReleaseYear(
                $payload['first_air_date'] ?? $fallbackPayload['first_air_date'] ?? null,
            ),
            'first_air_date' => $payload['first_air_date'] ?? $fallbackPayload['first_air_date'] ?? null,
            'last_air_date' => $payload['last_air_date'] ?? $fallbackPayload['last_air_date'] ?? null,
            'rating' => isset($payload['vote_average'])
                ? round((float) $payload['vote_average'], 1)
                : (isset($fallbackPayload['vote_average']) ? round((float) $fallbackPayload['vote_average'], 1) : null),
            'age_rating' => $this->resolveTvAgeRating($payload, $fallbackPayload),
            'episode_runtime_minutes' => $payload['episode_run_time'][0]
                ?? $fallbackPayload['episode_run_time'][0]
                ?? null,
            'creators' => collect($payload['created_by'] ?? $fallbackPayload['created_by'] ?? [])
                ->map(fn (array $creator) => $this->resolveEnglishPersonName($creator, $englishCreatedByById))
                ->filter()
                ->values()
                ->take(8)
                ->all(),
            'directors' => $this->resolveAggregateCrewNames($crew, ['Director'], $englishCrewById),
            'writers' => $this->resolveAggregateCrewNames($crew, ['Writer', 'Screenplay', 'Story', 'Author', 'Novel'], $englishCrewById),
            'production_status' => $this->firstFilledString(
                $payload['status'] ?? null,
                $fallbackPayload['status'] ?? null,
            ),
            'show_type' => $this->firstFilledString(
                $payload['type'] ?? null,
                $fallbackPayload['type'] ?? null,
            ),
            'network' => $networks->pluck('name')->filter()->first(),
            'homepage_url' => $this->firstFilledString(
                $payload['homepage'] ?? null,
                $fallbackPayload['homepage'] ?? null,
            ),
            'number_of_seasons' => $payload['number_of_seasons']
                ?? $fallbackPayload['number_of_seasons']
                ?? count($seasonDetails),
            'number_of_episodes' => $payload['number_of_episodes']
                ?? $fallbackPayload['number_of_episodes']
                ?? $numberOfEpisodes,
            'vote_average' => isset($payload['vote_average'])
                ? round((float) $payload['vote_average'], 3)
                : (isset($fallbackPayload['vote_average']) ? round((float) $fallbackPayload['vote_average'], 3) : null),
            'vote_count' => (int) ($payload['vote_count'] ?? $fallbackPayload['vote_count'] ?? 0),
            'popularity' => isset($payload['popularity'])
                ? round((float) $payload['popularity'], 3)
                : (isset($fallbackPayload['popularity']) ? round((float) $fallbackPayload['popularity'], 3) : null),
            'networks' => $networks
                ->map(fn (array $n) => ['name' => trim((string) ($n['name'] ?? ''))])
                ->filter(fn (array $n) => $n['name'] !== '')
                ->values()
                ->all(),
            'created_by' => collect($payload['created_by'] ?? $fallbackPayload['created_by'] ?? [])
                ->map(function (array $creator) use ($englishCreatedByById) {
                    $name = $this->resolveEnglishPersonName($creator, $englishCreatedByById);

                    if ($name === '') {
                        return null;
                    }

                    return [
                        'tmdb_person_id' => (int) ($creator['id'] ?? 0),
                        'name' => $name,
                    ];
                })
                ->filter()
                ->values()
                ->all(),
            'cast' => $cast->sortBy(fn (array $row) => $row['order'] ?? 999)->take(20)->values()->map(function (array $item) use ($englishCastById) {
                $roles = collect($item['roles'] ?? []);
                $character = $roles->pluck('character')->filter()->first();
                $name = $this->resolveEnglishPersonName($item, $englishCastById);

                if ($name === '') {
                    return null;
                }

                return [
                    'tmdb_person_id' => (int) ($item['id'] ?? 0),
                    'name' => $name,
                    'character' => filled($character) ? (string) $character : (string) ($item['character'] ?? ''),
                    'profile_url' => $this->buildImageUrl($item['profile_path'] ?? null, 'w500'),
                    'credit_id' => (string) ($item['credit_id'] ?? ''),
                    'order' => (int) ($item['order'] ?? 0),
                    'known_for_department' => (string) ($item['known_for_department'] ?? 'Acting'),
                    'episode_count' => (int) ($item['total_episode_count'] ?? 0),
                ];
            })->filter()->values()->all(),
            'crew' => $crew->flatMap(function (array $item) use ($englishCrewById) {
                $department = trim((string) ($item['department'] ?? ''));
                $jobs = collect($item['jobs'] ?? []);

                $match = $jobs->first(function (array $jobRow) use ($department) {
                    $job = trim((string) ($jobRow['job'] ?? ''));

                    return $this->movieCrewMatchesDisplayFilter([
                        'department' => $department,
                        'job' => $job,
                    ]);
                });

                if (!$match) {
                    return [];
                }

                $job = trim((string) ($match['job'] ?? ''));
                $name = $this->resolveEnglishPersonName($item, $englishCrewById);

                if ($name === '') {
                    return [];
                }

                return [[
                    'tmdb_person_id' => (int) ($item['id'] ?? 0),
                    'name' => $name,
                    'job' => $job,
                    'department' => $department,
                    'profile_url' => $this->buildImageUrl($item['profile_path'] ?? null, 'w500'),
                    'credit_id' => (string) ($item['credit_id'] ?? ''),
                ]];
            })->values()->all(),
            'actors' => $cast->take(15)->map(function (array $item) use ($englishCastById) {
                $name = $this->resolveEnglishPersonName($item, $englishCastById);

                return [
                    'name' => $name !== '' ? $name : null,
                    'character' => collect($item['roles'] ?? [])->pluck('character')->filter()->first(),
                    'profile_url' => $this->buildImageUrl($item['profile_path'] ?? null, 'w300'),
                ];
            })->values()->all(),
            'season_numbers' => $seasonNumbers,
            'seasons' => $seasonDetails,
            'season_errors' => $includeSeasons
                ? collect($seasonDetails)->pluck('_error', 'season_number')->filter()->all()
                : [],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $seasonListItems
     * @return array<int, array<string, mixed>>
     */
    private function buildTvSeasonDetailsParallel(
        int $tmdbId,
        array $seasonListItems,
        string $language,
        array $payload,
        ?array $fallbackPayload,
    ): array {
        if ($seasonListItems === []) {
            return [];
        }

        $prefetchRequests = [];
        foreach ($seasonListItems as $season) {
            $seasonNumber = (int) ($season['season_number'] ?? 0);

            if ($seasonNumber <= 0) {
                continue;
            }

            $prefetchRequests["season_{$seasonNumber}_primary"] = [
                "/tv/{$tmdbId}/season/{$seasonNumber}",
                ['language' => $language],
            ];

            if (strtolower($language) !== 'en-us') {
                $prefetchRequests["season_{$seasonNumber}_en"] = [
                    "/tv/{$tmdbId}/season/{$seasonNumber}",
                    ['language' => 'en-US'],
                ];
            }
        }

        if ($prefetchRequests !== []) {
            $this->requestParallelKeyed($prefetchRequests);
        }

        $seasonDetails = [];

        foreach ($seasonListItems as $season) {
            $seasonNumber = (int) ($season['season_number'] ?? 0);

            if ($seasonNumber <= 0) {
                continue;
            }

            try {
                $seasonDetails[] = $this->buildTvSeasonDetailPayload(
                    $tmdbId,
                    $seasonNumber,
                    $language,
                    $payload,
                    $fallbackPayload,
                    $season,
                    $this->resolveSeasonListItem($fallbackPayload, $seasonNumber),
                );
            } catch (\Throwable $exception) {
                $seasonDetails[] = [
                    'season_number' => $seasonNumber,
                    'name' => 'Season '.$seasonNumber,
                    'synopsis' => null,
                    'synopsis_en' => null,
                    'air_date' => null,
                    'episode_count' => 0,
                    'poster_url' => null,
                    'episodes' => [],
                    '_error' => $exception->getMessage(),
                ];
            }
        }

        return $seasonDetails;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function movieCrewMatchesDisplayFilter(array $item): bool
    {
        $job = trim((string) ($item['job'] ?? ''));
        $department = trim((string) ($item['department'] ?? ''));

        if ($job === 'Original Music Composer') {
            return true;
        }

        return match ($department) {
            'Directing' => in_array($job, ['Director', 'Co-Director'], true),
            'Writing' => in_array($job, ['Screenplay', 'Writer', 'Story', 'Novel'], true),
            'Production' => in_array($job, ['Producer', 'Executive Producer'], true),
            'Camera' => $job === 'Director of Photography',
            'Music' => $job === 'Original Music Composer',
            default => false,
        };
    }

    public function fetchPersonFromApi(int $tmdbPersonId): array
    {
        if ($tmdbPersonId <= 0) {
            throw new InvalidArgumentException('A valid TMDB person ID is required.');
        }

        try {
            return $this->request("/person/{$tmdbPersonId}", [
                'language' => 'en-US',
                'append_to_response' => 'external_ids',
            ]);
        } catch (\Throwable) {
            return [
                'id' => $tmdbPersonId,
                'name' => '',
            ];
        }
    }

    /**
     * @param  array<int, int>  $tmdbPersonIds
     * @return array<int, array<string, mixed>>
     */
    public function fetchPersonsFromApiParallel(array $tmdbPersonIds): array
    {
        $tmdbPersonIds = array_values(array_unique(array_filter(
            array_map(static fn ($id) => (int) $id, $tmdbPersonIds),
            static fn (int $id) => $id > 0,
        )));

        if ($tmdbPersonIds === []) {
            return [];
        }

        $keyedRequests = [];

        foreach ($tmdbPersonIds as $tmdbPersonId) {
            $keyedRequests["person_{$tmdbPersonId}"] = [
                "/person/{$tmdbPersonId}",
                [
                    'language' => 'en-US',
                    'append_to_response' => 'external_ids',
                ],
            ];
        }

        $responses = $this->requestParallelKeyed($keyedRequests);
        $results = [];

        foreach ($tmdbPersonIds as $tmdbPersonId) {
            $body = $responses["person_{$tmdbPersonId}"] ?? [];
            $results[$tmdbPersonId] = is_array($body) && $body !== []
                ? $body
                : ['id' => $tmdbPersonId, 'name' => ''];
        }

        return $results;
    }

    protected function request(string $path, array $query = []): array
    {
        $credentialSets = $this->tmdbCredentialSets();
        $baseUrl = rtrim((string) config('services.tmdb.base_url'), '/');

        if ($credentialSets === []) {
            throw new RuntimeException('TMDB credentials are not configured.');
        }

        $lastException = null;

        foreach ($credentialSets as $index => $creds) {
            try {
                $requestQuery = $query;
                $request = $this->configureHttpClient(
                    Http::acceptJson()->timeout(20)
                );

                if ($creds['access_token'] !== '') {
                    $request = $request->withToken($creds['access_token']);
                } elseif ($creds['api_key'] !== '') {
                    $requestQuery['api_key'] = $creds['api_key'];
                } else {
                    continue;
                }

                $response = $request->get($baseUrl.$path, $requestQuery);
                $response->throw();

                return $response->json() ?? [];
            } catch (\Throwable $exception) {
                $lastException = $exception;

                if ($index < count($credentialSets) - 1) {
                    usleep(200_000 * ($index + 1));

                    continue;
                }
            }
        }

        if ($lastException instanceof \Throwable) {
            throw $lastException;
        }

        throw new RuntimeException('TMDB request failed with all configured credentials.');
    }

    private function configureHttpClient(\Illuminate\Http\Client\PendingRequest $request): \Illuminate\Http\Client\PendingRequest
    {
        return OutboundHttpClient::configure($request);
    }

    private function normalizeSearchResult(array $item): array
    {
        return [
            'tmdb_id' => $item['id'] ?? null,
            'title' => $item['title'] ?? null,
            'original_title' => $item['original_title'] ?? null,
            'synopsis' => $item['overview'] ?? null,
            'release_year' => $this->extractReleaseYear($item['release_date'] ?? null),
            'rating' => isset($item['vote_average']) ? round((float) $item['vote_average'], 1) : null,
            'poster_url' => $this->buildImageUrl($item['poster_path'] ?? null, 'w500'),
            'backdrop_url' => $this->buildImageUrl($item['backdrop_path'] ?? null, 'original'),
            'media_type' => 'movie',
        ];
    }

    private function normalizeTvSearchResult(array $item): array
    {
        return [
            'tmdb_id' => $item['id'] ?? null,
            'title' => $item['name'] ?? null,
            'original_title' => $item['original_name'] ?? null,
            'synopsis' => $item['overview'] ?? null,
            'release_year' => $this->extractReleaseYear($item['first_air_date'] ?? null),
            'rating' => isset($item['vote_average']) ? round((float) $item['vote_average'], 1) : null,
            'poster_url' => $this->buildImageUrl($item['poster_path'] ?? null, 'w500'),
            'backdrop_url' => $this->buildImageUrl($item['backdrop_path'] ?? null, 'original'),
            'media_type' => 'tv',
        ];
    }

    private function normalizeDetailedMovieResult(array $item): array
    {
        return [
            'tmdb_id' => $item['tmdb_id'] ?? null,
            'title' => $item['title'] ?? null,
            'original_title' => $item['original_title'] ?? null,
            'synopsis' => $item['synopsis'] ?? null,
            'release_year' => $item['release_year'] ?? null,
            'rating' => $item['rating'] ?? null,
            'poster_url' => $item['poster_url'] ?? null,
            'backdrop_url' => $item['backdrop_url'] ?? null,
        ];
    }

    public function fetchTvSeasonForSeries(int $tmdbId, int $seasonNumber, ?string $language = null): array
    {
        if ($tmdbId <= 0 || $seasonNumber <= 0) {
            throw new InvalidArgumentException('A valid TMDB TV ID and season number are required.');
        }

        $language = $this->resolveLanguage($language);
        $bundles = $this->fetchPrimaryAndEnglishParallel("/tv/{$tmdbId}", $language);
        $payload = $bundles['primary'];
        $fallbackPayload = $bundles['fallback'];
        $seasonListItem = collect($payload['seasons'] ?? [])
            ->firstWhere('season_number', $seasonNumber);

        if ($seasonListItem === null && $fallbackPayload) {
            $seasonListItem = collect($fallbackPayload['seasons'] ?? [])
                ->firstWhere('season_number', $seasonNumber);
        }

        return $this->buildTvSeasonDetailPayload(
            $tmdbId,
            $seasonNumber,
            $language,
            $payload,
            $fallbackPayload,
            is_array($seasonListItem) ? $seasonListItem : null,
            $this->resolveSeasonListItem($fallbackPayload, $seasonNumber),
        );
    }

    private function buildTvSeasonDetailPayload(
        int $tmdbId,
        int $seasonNumber,
        string $language,
        array $payload,
        ?array $fallbackPayload,
        ?array $seasonListItem,
        ?array $englishSeasonListItem = null,
    ): array {
        $seasonMeta = is_array($seasonListItem) ? $seasonListItem : [];
        $englishSeasonMeta = is_array($englishSeasonListItem) ? $englishSeasonListItem : [];
        $details = $this->fetchTvSeason($tmdbId, $seasonNumber, $language);

        if (
            ! isset($details['season_number'])
            && collect($details['episodes'] ?? [])->isEmpty()
            && blank($details['name'] ?? null)
        ) {
            throw new RuntimeException("TMDB season {$seasonNumber} returned an empty payload.");
        }

        $englishSeasonOverview = $details['_english_overview'] ?? null;
        $englishEpisodeOverviews = is_array($details['_english_episode_overviews'] ?? null)
            ? $details['_english_episode_overviews']
            : [];

        $seasonPersianOverview = $this->isPersianOverview($details['overview'] ?? null)
            ? trim((string) ($details['overview'] ?? ''))
            : $this->fetchPersianOverviewFromTranslations(
                "/tv/{$tmdbId}/season/{$seasonNumber}",
            );

        $episodesNeedingPersianOverview = collect($details['episodes'] ?? [])
            ->filter(function (array $episode) {
                if (! isset($episode['episode_number']) || (int) ($episode['episode_number'] ?? 0) <= 0) {
                    return false;
                }

                return ! $this->isPersianOverview($episode['overview'] ?? null);
            })
            ->map(fn (array $episode) => (int) ($episode['episode_number'] ?? 0))
            ->values()
            ->all();

        $persianEpisodeOverviews = $this->fetchPersianEpisodeOverviewsParallel(
            $tmdbId,
            $seasonNumber,
            $episodesNeedingPersianOverview,
        );

        $episodes = collect($details['episodes'] ?? [])
            ->filter(
                fn (array $episode) => isset($episode['episode_number'])
                    && (int) ($episode['episode_number'] ?? 0) > 0
            )
            ->sortBy(fn (array $episode) => (int) ($episode['episode_number'] ?? 0))
            ->values()
            ->map(function (array $episode) use (
                $seasonNumber,
                $payload,
                $fallbackPayload,
                $persianEpisodeOverviews,
                $englishEpisodeOverviews,
            ) {
                $episodeNumber = (int) ($episode['episode_number'] ?? 0);

                $episodeSynopsis = $this->synopsisHelper->localizeSeasonOrEpisode(
                    $this->resolvePersianOverview(
                        $episode['overview'] ?? null,
                        $persianEpisodeOverviews[$episodeNumber] ?? null,
                    ),
                    $this->firstFilledString(
                        $englishEpisodeOverviews[$episodeNumber] ?? null,
                        $this->isPersianOverview($episode['overview'] ?? null)
                            ? null
                            : ($episode['overview'] ?? null),
                    ),
                );

                return [
                    'season_number' => $seasonNumber,
                    'episode_number' => $episodeNumber,
                    'title' => $this->resolveEpisodeTitle($episode['name'] ?? null),
                    'synopsis' => $episodeSynopsis['synopsis'],
                    'synopsis_en' => $episodeSynopsis['synopsis_en'],
                    'air_date' => $episode['air_date'] ?? null,
                    'duration_minutes' => $episode['runtime']
                        ?? $payload['episode_run_time'][0]
                        ?? $fallbackPayload['episode_run_time'][0]
                        ?? null,
                    'still_url' => $this->buildImageUrl($episode['still_path'] ?? null, 'w780'),
                    'source_method' => 'hls',
                    'video_url' => null,
                    'embed_url' => null,
                    'hls_url' => null,
                    'subtitle_url' => null,
                    'dubbed_audio_url' => null,
                    'publish_status' => 'draft',
                ];
            })
            ->all();

        $seasonSynopsis = $this->synopsisHelper->localizeSeasonOrEpisode(
            $this->resolvePersianOverview(
                $details['overview'] ?? null,
                $seasonPersianOverview,
                $seasonMeta['overview'] ?? null,
            ),
            $this->firstFilledString(
                $englishSeasonOverview,
                $englishSeasonMeta['overview'] ?? null,
                $this->isPersianOverview($details['overview'] ?? null)
                    ? null
                    : ($details['overview'] ?? null),
            ),
        );

        return [
            'season_number' => $seasonNumber,
            'name' => $this->firstFilledString(
                $details['name'] ?? null,
                $seasonMeta['name'] ?? null,
                'Season ' . $seasonNumber,
            ),
            'synopsis' => $seasonSynopsis['synopsis'],
            'synopsis_en' => $seasonSynopsis['synopsis_en'],
            'air_date' => $details['air_date'] ?? ($seasonMeta['air_date'] ?? null),
            'episode_count' => $details['episode_count']
                ?? ($seasonMeta['episode_count'] ?? count($episodes)),
            'poster_url' => $this->buildImageUrl(
                $details['poster_path'] ?? $seasonMeta['poster_path'] ?? null,
                'w780'
            ),
            'episodes' => $episodes,
        ];
    }

    private function fetchTvSeason(int $seriesId, int $seasonNumber, string $language): array
    {
        $bundles = $this->fetchPrimaryAndEnglishParallel(
            "/tv/{$seriesId}/season/{$seasonNumber}",
            $language,
        );
        $payload = $bundles['primary'];
        $fallbackPayload = $bundles['fallback'];

        if ($fallbackPayload === null || ! $this->shouldFetchEnglishSeasonFallback($payload, $language)) {
            $payload['_english_overview'] = $this->isPersianOverview($payload['overview'] ?? null)
                ? null
                : ($payload['overview'] ?? null);
            $payload['_english_episode_overviews'] = collect($payload['episodes'] ?? [])
                ->filter(fn (array $episode) => isset($episode['episode_number']))
                ->mapWithKeys(function (array $episode) {
                    $episodeNumber = (int) ($episode['episode_number'] ?? 0);
                    $overview = $episode['overview'] ?? null;

                    return [
                        $episodeNumber => $this->isPersianOverview($overview) ? null : $overview,
                    ];
                })
                ->all();

            return $payload;
        }

        if (blank($payload['name'] ?? null)) {
            $payload['name'] = $fallbackPayload['name'] ?? null;
        }

        $payload['_english_overview'] = $fallbackPayload['overview'] ?? null;
        $payload['_english_episode_overviews'] = collect($fallbackPayload['episodes'] ?? [])
            ->filter(fn (array $episode) => isset($episode['episode_number']))
            ->mapWithKeys(
                fn (array $episode) => [
                    (int) ($episode['episode_number'] ?? 0) => $this->nullableTrim($episode['overview'] ?? null),
                ]
            )
            ->all();

        if (blank($payload['air_date'] ?? null)) {
            $payload['air_date'] = $fallbackPayload['air_date'] ?? null;
        }

        if (blank($payload['poster_path'] ?? null)) {
            $payload['poster_path'] = $fallbackPayload['poster_path'] ?? null;
        }

        if (!isset($payload['season_number'])) {
            $payload['season_number'] = $fallbackPayload['season_number'] ?? null;
        }

        if (!isset($payload['episode_count'])) {
            $payload['episode_count'] = $fallbackPayload['episode_count'] ?? null;
        }

        $primaryEpisodes = collect($payload['episodes'] ?? []);
        $fallbackEpisodes = collect($fallbackPayload['episodes'] ?? []);

        if ($primaryEpisodes->isEmpty()) {
            $primaryEpisodes = $fallbackEpisodes;
        }

        $fallbackEpisodesByKey = $fallbackEpisodes->keyBy(
            fn (array $episode, int $index) => $this->tmdbEpisodeMergeKey($episode, $index)
        );
        $primaryKeys = [];

        $mergedEpisodes = $primaryEpisodes
            ->map(function (array $episode, int $index) use ($fallbackEpisodesByKey, &$primaryKeys) {
                $mergeKey = $this->tmdbEpisodeMergeKey($episode, $index);
                $primaryKeys[] = $mergeKey;
                $fallbackEpisode = $fallbackEpisodesByKey->get($mergeKey);

                if ($fallbackEpisode && !isset($episode['episode_number'])) {
                    $episode['episode_number'] = $fallbackEpisode['episode_number'] ?? null;
                }

                $primaryName = trim((string) ($episode['name'] ?? ''));
                $fallbackName = trim((string) ($fallbackEpisode['name'] ?? ''));

                if ($fallbackEpisode && ($primaryName === '' || $this->isGenericEpisodeTitle($primaryName))) {
                    if ($fallbackName !== '' && !$this->isGenericEpisodeTitle($fallbackName)) {
                        $episode['name'] = $fallbackName;
                    }
                }

                if ($fallbackEpisode && blank($episode['still_path'] ?? null)) {
                    $episode['still_path'] = $fallbackEpisode['still_path'] ?? null;
                }

                if ($fallbackEpisode && blank($episode['air_date'] ?? null)) {
                    $episode['air_date'] = $fallbackEpisode['air_date'] ?? null;
                }

                if ($fallbackEpisode && !isset($episode['runtime'])) {
                    $episode['runtime'] = $fallbackEpisode['runtime'] ?? null;
                }

                return $episode;
            })
            ->values();

        $missingFallbackEpisodes = $fallbackEpisodes
            ->reject(function (array $episode, int $index) use ($primaryKeys) {
                return in_array($this->tmdbEpisodeMergeKey($episode, $index), $primaryKeys, true);
            })
            ->values();

        $payload['episodes'] = $mergedEpisodes
            ->concat($missingFallbackEpisodes)
            ->sortBy(fn (array $episode) => (int) ($episode['episode_number'] ?? 0))
            ->values()
            ->all();

        if (!isset($payload['_english_overview'])) {
            $payload['_english_overview'] = null;
            $payload['_english_episode_overviews'] = [];
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>|null  $seriesPayload
     * @return array<string, mixed>|null
     */
    private function resolveSeasonListItem(?array $seriesPayload, int $seasonNumber): ?array
    {
        if (!$seriesPayload) {
            return null;
        }

        $season = collect($seriesPayload['seasons'] ?? [])
            ->firstWhere('season_number', $seasonNumber);

        return is_array($season) ? $season : null;
    }

    private function nullableTrim(?string $value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));

        return $trimmed === '' ? null : $trimmed;
    }

    private function tmdbEpisodeMergeKey(array $episode, int $index): string
    {
        if (isset($episode['episode_number'])) {
            return 'episode-' . (int) $episode['episode_number'];
        }

        return 'index-' . $index;
    }

    private function resolveTrailerUrl(array $videos, array $fallbackVideos = []): ?string
    {
        $videoCollection = collect($videos);

        if ($videoCollection->isEmpty() && $fallbackVideos !== []) {
            $videoCollection = collect($fallbackVideos);
        }

        $videoCollection = $videoCollection
            ->filter(fn (array $item) => ($item['site'] ?? null) === 'YouTube' && filled($item['key'] ?? null))
            ->values();

        $preferred = $videoCollection->first(
            fn (array $item) => ($item['official'] ?? false) === true && ($item['type'] ?? null) === 'Trailer'
        )
            ?? $videoCollection->first(fn (array $item) => ($item['type'] ?? null) === 'Trailer')
            ?? $videoCollection->first(fn (array $item) => ($item['type'] ?? null) === 'Teaser')
            ?? $videoCollection->first();

        if (!$preferred) {
            return null;
        }

        return 'https://www.youtube.com/watch?v=' . $preferred['key'];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $crew
     * @param  array<int, string>  $jobs
     * @return array<int, string>
     */
    private function resolveCrewNames($crew, array $jobs, $englishCrewById = null): array
    {
        return $crew
            ->filter(function (array $item) use ($jobs) {
                $job = trim((string) ($item['job'] ?? ''));

                return $job !== '' && in_array($job, $jobs, true);
            })
            ->map(function (array $item) use ($englishCrewById) {
                return $this->resolveEnglishPersonName($item, $englishCrewById);
            })
            ->filter(fn (string $name) => $name !== '')
            ->unique()
            ->values()
            ->take(8)
            ->all();
    }

    private function resolveAgeRating(array $payload, ?array $fallbackPayload = null): ?string
    {
        $results = collect($payload['release_dates']['results'] ?? []);

        if ($results->isEmpty() && $fallbackPayload) {
            $results = collect($fallbackPayload['release_dates']['results'] ?? []);
        }

        $preferredMarkets = [
            'US',
            collect($payload['production_countries'] ?? [])->pluck('iso_3166_1')->filter()->first(),
            collect($fallbackPayload['production_countries'] ?? [])->pluck('iso_3166_1')->filter()->first(),
            'GB',
        ];

        foreach ($preferredMarkets as $market) {
            if (!$market) {
                continue;
            }

            $marketResult = $results->firstWhere('iso_3166_1', $market);
            $certification = collect($marketResult['release_dates'] ?? [])
                ->pluck('certification')
                ->filter()
                ->first();

            if (filled($certification)) {
                return $this->normalizeTvContentRating(trim((string) $certification));
            }
        }

        foreach ($results as $market) {
            $certification = collect($market['release_dates'] ?? [])
                ->pluck('certification')
                ->filter()
                ->first();

            if (filled($certification)) {
                return $this->normalizeTvContentRating(trim((string) $certification));
            }
        }

        return null;
    }

    private function resolveTvAgeRating(array $payload, ?array $fallbackPayload = null): ?string
    {
        $results = collect($payload['content_ratings']['results'] ?? []);

        if ($results->isEmpty() && $fallbackPayload) {
            $results = collect($fallbackPayload['content_ratings']['results'] ?? []);
        }

        foreach (['US', 'GB'] as $market) {
            $rating = $results->firstWhere('iso_3166_1', $market)['rating'] ?? null;

            if (filled($rating)) {
                return $this->normalizeTvContentRating(trim((string) $rating));
            }
        }

        $fallback = $results->pluck('rating')->filter()->first();

        return filled($fallback) ? $this->normalizeTvContentRating(trim((string) $fallback)) : null;
    }

    private function normalizeTvContentRating(string $raw): string
    {
        $map = [
            'TV-Y'   => '0',
            'TV-Y7'  => '0',
            'TV-G'   => '0',
            'G'      => '0',
            'TV-PG'  => '7',
            'PG'     => '7',
            'NR'     => '7',
            'TV-14'  => '13',
            'PG-13'  => '13',
            '12'     => '13',
            '12A'    => '13',
            '15'     => '16',
            '16'     => '16',
            '16+'    => '16',
            'TV-MA'  => '17',
            'R'      => '17',
            '18'     => '18',
            '18+'    => '18',
            'NC-17'  => '18',
            'X'      => '18',
            'R18'    => '18',
        ];

        $key = strtoupper(trim($raw));
        if (isset($map[$key])) {
            return $map[$key];
        }

        if (isset($map[$raw])) {
            return $map[$raw];
        }

        if (preg_match('/^(\d{1,2})\+?$/', trim($raw), $matches) === 1) {
            return $matches[1];
        }

        return $raw;
    }

    private function shouldFetchEnglishTvFallback(array $payload, string $language): bool
    {
        if (strtolower($language) === 'en-us') {
            return false;
        }

        return blank($payload['name'] ?? null)
            || blank($payload['overview'] ?? null)
            || $this->resolveTrailerUrl($payload['videos']['results'] ?? []) === null;
    }

    private function shouldFetchEnglishSeasonFallback(array $payload, string $language): bool
    {
        if (strtolower($language) === 'en-us') {
            return false;
        }

        if (blank($payload['name'] ?? null) || blank($payload['overview'] ?? null)) {
            return true;
        }

        foreach ($payload['episodes'] ?? [] as $episode) {
            $name = trim((string) ($episode['name'] ?? ''));

            if ($name === '' || $this->isGenericEpisodeTitle($name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $crew
     * @param  array<int, string>  $jobs
     * @return array<int, string>
     */
    private function resolveAggregateCrewNames($crew, array $jobs, $englishCrewById = null): array
    {
        return $crew
            ->filter(function (array $item) use ($jobs) {
                return collect($item['jobs'] ?? [])
                    ->pluck('job')
                    ->contains(fn ($job) => is_string($job) && in_array(trim($job), $jobs, true));
            })
            ->map(function (array $item) use ($englishCrewById) {
                return $this->resolveEnglishPersonName($item, $englishCrewById);
            })
            ->filter(fn (string $name) => $name !== '')
            ->unique()
            ->values()
            ->take(8)
            ->all();
    }

    private function shouldFetchEnglishFallback(array $payload, string $language): bool
    {
        if (strtolower($language) === 'en-us') {
            return false;
        }

        return blank($payload['title'] ?? null)
            || blank($payload['overview'] ?? null)
            || $this->resolveTrailerUrl($payload['videos']['results'] ?? []) === null;
    }

    private function firstFilledString(?string ...$values): ?string
    {
        foreach ($values as $value) {
            if (filled($value)) {
                return trim((string) $value);
            }
        }

        return null;
    }

    /**
     * @param  array<int, string|null>  $values
     */
    /**
     * @param  array<string, mixed>|null  $payload
     * @param  array<string, mixed>|null  $fallbackPayload
     * @return array{synopsis: ?string, synopsis_en: ?string}
     */
    private function localizeMainSynopsis(
        string $translationsBasePath,
        ?array $payload,
        ?array $fallbackPayload,
    ): array {
        $primaryOverview = is_array($payload) ? ($payload['overview'] ?? null) : null;
        $fallbackOverview = is_array($fallbackPayload) ? ($fallbackPayload['overview'] ?? null) : null;
        $persianFromTranslations = $this->fetchPersianOverviewFromTranslations($translationsBasePath);

        return $this->synopsisHelper->localizeContentSynopsis(
            $this->resolvePersianOverview(
                $persianFromTranslations,
                $primaryOverview,
            ),
            $this->firstFilledString(
                $fallbackOverview,
                $this->isPersianOverview($primaryOverview) ? null : $primaryOverview,
            ),
        );
    }

    private function resolvePersianOverview(?string ...$values): ?string
    {
        foreach ($values as $value) {
            if ($this->isPersianOverview($value)) {
                return trim((string) $value);
            }
        }

        return null;
    }

    private function isPersianOverview(?string $value): bool
    {
        if (CinemaSynopsisHelper::hasPersianScript($value)) {
            return true;
        }

        $trimmed = trim((string) ($value ?? ''));

        if ($trimmed === '' || $this->isPredominantlyLatin($trimmed)) {
            return false;
        }

        return preg_match(
            '/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u',
            $trimmed,
        ) === 1;
    }

    private function isPredominantlyLatin(string $text): bool
    {
        $latinLetters = preg_match_all('/[A-Za-z]/', $text) ?: 0;
        $rtlLetters = preg_match_all(
            '/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u',
            $text,
        ) ?: 0;

        if ($rtlLetters > 0) {
            return false;
        }

        return $latinLetters >= 12;
    }

    private function resolveEpisodeTitle(?string ...$values): ?string
    {
        foreach ($values as $value) {
            $trimmed = trim((string) ($value ?? ''));

            if ($trimmed === '' || $this->isGenericEpisodeTitle($trimmed)) {
                continue;
            }

            return $trimmed;
        }

        return null;
    }

    private function isGenericEpisodeTitle(string $title): bool
    {
        $normalized = preg_replace(
            '/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u',
            '',
            trim($title),
        ) ?? trim($title);

        return preg_match(
            '/^(?:episode|اپیزود|قسمت)\s*#?\s*[\d۰-۹٠-٩]+$/iu',
            $normalized,
        ) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveEnglishCreditsPayload(
        string $mediaType,
        int $mediaId,
        string $language,
        ?array $existingEnglishPayload,
    ): array {
        if (strtolower($language) === 'en-us') {
            return $mediaType === 'tv'
                ? ['aggregate_credits' => ['cast' => [], 'crew' => []], 'created_by' => []]
                : ['credits' => ['cast' => [], 'crew' => []]];
        }

        if (is_array($existingEnglishPayload)) {
            return $existingEnglishPayload;
        }

        $append = $mediaType === 'tv' ? 'aggregate_credits' : 'credits';

        return $this->request("/{$mediaType}/{$mediaId}", [
            'language' => 'en-US',
            'append_to_response' => $append,
        ]);
    }

    /**
     * @param  array<string, mixed>  $person
     * @param  \Illuminate\Support\Collection<int|string, array<string, mixed>>|null  $englishById
     */
    private function resolveEnglishPersonName(array $person, $englishById = null): string
    {
        $personId = (int) ($person['id'] ?? 0);
        $englishName = null;

        if ($englishById && $personId > 0) {
            $englishRow = $englishById->get($personId);
            $englishName = is_array($englishRow) ? ($englishRow['name'] ?? null) : null;
        }

        return $this->resolvePersonName(
            $englishName,
            $person['name'] ?? null,
            $person['original_name'] ?? null,
        );
    }

    private function resolvePersonName(?string ...$values): string
    {
        foreach ($values as $value) {
            $trimmed = trim((string) ($value ?? ''));

            if ($trimmed !== '' && !$this->containsPersianScript($trimmed)) {
                return $trimmed;
            }
        }

        foreach ($values as $value) {
            $trimmed = trim((string) ($value ?? ''));

            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return '';
    }

    private function containsPersianScript(string $text): bool
    {
        return preg_match(
            '/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u',
            $text,
        ) === 1;
    }

    private function buildImageUrl(?string $path, string $size): ?string
    {
        if (!$path) {
            return null;
        }

        return rtrim((string) config('services.tmdb.image_base_url'), '/')
            . '/'
            . trim($size, '/')
            . '/'
            . ltrim($path, '/');
    }

    private function extractReleaseYear(?string $releaseDate): ?int
    {
        if (!$releaseDate || strlen($releaseDate) < 4) {
            return null;
        }

        $year = (int) substr($releaseDate, 0, 4);

        return $year > 0 ? $year : null;
    }

    private function fetchLocalizedGenres(string $mediaType, int $tmdbId): array
    {
        $response = $this->request("/{$mediaType}/{$tmdbId}", [
            'language' => 'fa-IR',
        ]);

        return $response['genres'] ?? [];
    }

    /**
     * @param  array<int, string>  $labels
     * @return array<int, string>
     */
    public function localizeGenreNames(array $labels, string $mediaType = 'tv'): array
    {
        if ($labels === []) {
            return [];
        }

        $englishToPersian = $this->englishToPersianGenreMap();

        return collect($labels)
            ->map(function (string $label) use ($englishToPersian) {
                $trimmed = trim($label);

                if ($trimmed === '') {
                    return null;
                }

                if (preg_match('/\p{Arabic}/u', $trimmed) === 1) {
                    return $trimmed;
                }

                $normalized = strtolower($trimmed);

                if (isset($englishToPersian[$normalized])) {
                    return $englishToPersian[$normalized];
                }

                return $this->persianGenreFallbackByEnglish($trimmed);
            })
            ->filter(fn (?string $name) => filled($name))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function englishToPersianGenreMap(): array
    {
        return \Illuminate\Support\Facades\Cache::remember('tmdb_genre_en_to_fa_map_v1', 86400, function (): array {
            $cache = [];

            foreach (['movie', 'tv'] as $mediaType) {
                $faMap = $this->genreNameMap($mediaType, 'fa-IR');
                $enMap = $this->genreNameMap($mediaType, 'en-US');

                foreach ($enMap as $id => $englishName) {
                    $persian = $faMap[$id] ?? null;

                    if (! filled($persian)) {
                        continue;
                    }

                    $cache[strtolower($englishName)] = $persian;
                }
            }

            return $cache;
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $genreItems
     * @return array<int, string>
     */
    private function resolveGenreLabels(array $genreItems, string $mediaType, string $language): array
    {
        if ($genreItems === []) {
            return [];
        }

        $map = $this->genreNameMap($mediaType, $this->genreMapLanguage($language));

        return collect($genreItems)
            ->map(function (array $item) use ($map) {
                $id = (int) ($item['id'] ?? 0);

                if ($id > 0 && isset($map[$id])) {
                    return $map[$id];
                }

                return trim((string) ($item['name'] ?? ''));
            })
            ->filter(fn (string $name) => $name !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function genreNameMap(string $mediaType, string $language): array
    {
        $cacheKey = "{$mediaType}:{$language}";

        if (isset($this->genreNameMapCache[$cacheKey])) {
            return $this->genreNameMapCache[$cacheKey];
        }

        try {
            $response = $this->request("/genre/{$mediaType}/list", [
                'language' => $language,
            ]);
        } catch (\Throwable) {
            $this->genreNameMapCache[$cacheKey] = [];

            return [];
        }

        $map = collect($response['genres'] ?? [])
            ->mapWithKeys(fn (array $genre) => [
                (int) ($genre['id'] ?? 0) => trim((string) ($genre['name'] ?? '')),
            ])
            ->filter(fn (string $name, int $id) => $id > 0 && $name !== '')
            ->all();

        $this->genreNameMapCache[$cacheKey] = $map;

        return $map;
    }

    private function genreMapLanguage(string $language): string
    {
        return 'fa-IR';
    }

    /**
     * @return array<string, string>
     */
    private function persianGenreFallbackByEnglish(string $label): string
    {
        $fallback = [
            'action' => 'اکشن',
            'adventure' => 'ماجراجویی',
            'animation' => 'انیمیشن',
            'comedy' => 'کمدی',
            'crime' => 'جنایی',
            'documentary' => 'مستند',
            'drama' => 'درام',
            'family' => 'خانوادگی',
            'fantasy' => 'فانتزی',
            'history' => 'تاریخی',
            'horror' => 'ترسناک',
            'music' => 'موزیک',
            'mystery' => 'معمایی',
            'romance' => 'عاشقانه',
            'sci-fi' => 'علمی تخیلی',
            'science fiction' => 'علمی تخیلی',
            'sci fi' => 'علمی تخیلی',
            'sci-fi & fantasy' => 'علمی تخیلی و فانتزی',
            'tv movie' => 'فیلم تلویزیونی',
            'thriller' => 'هیجان‌انگیز',
            'war' => 'جنگی',
            'western' => 'وسترن',
            'action & adventure' => 'اکشن و ماجراجویی',
            'kids' => 'کودک',
            'news' => 'خبری',
            'reality' => 'واقع‌نما',
            'sci-fi & fantasy' => 'علمی تخیلی و فانتزی',
            'talk' => 'گفت‌وگو',
            'war & politics' => 'جنگ و سیاست',
        ];

        return $fallback[strtolower(trim($label))] ?? $label;
    }

    private function fetchPersianOverviewFromTranslations(string $basePath): ?string
    {
        try {
            $payload = $this->request("{$basePath}/translations");
        } catch (\Throwable) {
            return null;
        }

        return $this->extractPersianOverviewFromTranslationsPayload($payload);
    }

    private function extractPersianOverviewFromTranslationsPayload(array $payload): ?string
    {
        foreach ($payload['translations'] ?? [] as $translation) {
            if (!is_array($translation)) {
                continue;
            }

            if (!$this->isPersianTranslation($translation)) {
                continue;
            }

            $overview = trim((string) ($translation['data']['overview'] ?? ''));

            if ($overview !== '') {
                return $overview;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $translation
     */
    private function isPersianTranslation(array $translation): bool
    {
        $iso = strtolower(trim((string) ($translation['iso_639_1'] ?? '')));

        return $iso === 'fa' || str_starts_with($iso, 'fa-');
    }

    /**
     * @param  array<int, int>  $episodeNumbers
     * @return array<int, string>
     */
    private function fetchPersianEpisodeOverviewsParallel(
        int $seriesId,
        int $seasonNumber,
        array $episodeNumbers,
    ): array {
        if ($episodeNumbers === []) {
            return [];
        }

        $accessToken = trim((string) config('services.tmdb.access_token'));
        $apiKey = trim((string) config('services.tmdb.api_key'));
        $baseUrl = rtrim((string) config('services.tmdb.base_url'), '/');
        $results = [];

        foreach (array_chunk($episodeNumbers, 8) as $episodeChunk) {
            $responses = Http::pool(function (\Illuminate\Http\Client\Pool $pool) use (
                $episodeChunk,
                $seriesId,
                $seasonNumber,
                $accessToken,
                $apiKey,
                $baseUrl,
            ) {
                foreach ($episodeChunk as $episodeNumber) {
                    $url = "{$baseUrl}/tv/{$seriesId}/season/{$seasonNumber}/episode/{$episodeNumber}/translations";
                    $request = $this->configureHttpClient(
                        $pool->as("ep_{$episodeNumber}")->acceptJson()->timeout(20)
                    );

                    if ($accessToken !== '') {
                        $request->withToken($accessToken)->get($url);
                    } else {
                        $request->get($url, ['api_key' => $apiKey]);
                    }
                }
            });

            foreach ($episodeChunk as $episodeNumber) {
                $response = $responses["ep_{$episodeNumber}"] ?? null;

                if (! $response || ! $response->successful()) {
                    continue;
                }

                $overview = $this->extractPersianOverviewFromTranslationsPayload($response->json() ?? []);

                if ($overview) {
                    $results[$episodeNumber] = $overview;
                }
            }
        }

        return $results;
    }

    private function resolveLanguage(?string $language): string
    {
        return trim((string) ($language ?: config('services.tmdb.default_language', 'fa-IR'))) ?: 'fa-IR';
    }

    /**
     * Lightweight TMDB detail fetch for popularity refresh / backfill.
     *
     * @return array{popularity: ?float, vote_count: ?int, vote_average: ?float}
     */
    public function fetchPopularitySnapshot(int $tmdbId, string $mediaType = 'movie'): array
    {
        if ($tmdbId <= 0) {
            return ['popularity' => null, 'vote_count' => null, 'vote_average' => null];
        }

        $path = $mediaType === 'series' ? "/tv/{$tmdbId}" : "/movie/{$tmdbId}";
        $payload = $this->requestCached($path, ['language' => 'en-US']);

        return [
            'popularity' => isset($payload['popularity'])
                ? round((float) $payload['popularity'], 3)
                : null,
            'vote_count' => isset($payload['vote_count'])
                ? max(0, (int) $payload['vote_count'])
                : null,
            'vote_average' => isset($payload['vote_average'])
                ? round((float) $payload['vote_average'], 3)
                : null,
        ];
    }

    /**
     * @return array<int, array{id: int, popularity: ?float, vote_average: ?float, vote_count: ?int}>
     */
    public function fetchTrendingMovies(string $window = 'day'): array
    {
        return $this->fetchTrendingList('/trending/movie/'.$window);
    }

    /**
     * @return array<int, array{id: int, popularity: ?float, vote_average: ?float, vote_count: ?int}>
     */
    public function fetchTrendingSeries(string $window = 'day'): array
    {
        return $this->fetchTrendingList('/trending/tv/'.$window);
    }

    /**
     * @return array<int, array{id: int, popularity: ?float, vote_average: ?float, vote_count: ?int}>
     */
    private function fetchTrendingList(string $path): array
    {
        $payload = $this->requestCached($path, ['language' => 'en-US']);
        $results = is_array($payload['results'] ?? null) ? $payload['results'] : [];

        return collect($results)
            ->map(function (array $item, int $index): ?array {
                $id = (int) ($item['id'] ?? 0);

                if ($id <= 0) {
                    return null;
                }

                return [
                    'id' => $id,
                    'rank' => $index + 1,
                    'popularity' => isset($item['popularity'])
                        ? round((float) $item['popularity'], 3)
                        : null,
                    'vote_average' => isset($item['vote_average'])
                        ? round((float) $item['vote_average'], 3)
                        : null,
                    'vote_count' => isset($item['vote_count'])
                        ? max(0, (int) $item['vote_count'])
                        : null,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
