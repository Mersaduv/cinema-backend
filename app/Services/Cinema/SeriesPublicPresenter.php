<?php

namespace App\Services\Cinema;

use App\Models\Person;
use App\Models\Series;
use App\Models\SeriesCast;
use App\Models\SeriesCrew;
use App\Models\SeriesEpisode;
use App\Models\SeriesSeason;
use App\Support\ContentMetadataOptions;
use App\Services\TmdbService;

/**
 * Single source of truth for public series payloads (REST + GraphQL).
 */
class SeriesPublicPresenter
{
    public function __construct(
        private readonly TmdbService $tmdbService,
        private readonly GenreCatalogService $genreCatalogService,
        private readonly ImagePresentationService $imagePresentationService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function listMeta(Series $series): array
    {
        $metadata = is_array($series->metadata) ? $series->metadata : [];
        $posterImage = $this->imagePresentationService->seriesPosterImage($metadata);
        $backdropImage = $this->imagePresentationService->seriesBackdropImage($metadata);

        return [
            'status_label_fa' => $this->statusLabelFa($series->status),
            'poster_url' => $this->imagePresentationService->resolveSeriesPosterUrl($series->poster_url, $metadata),
            'poster_blur_url' => data_get($posterImage, 'blur'),
            'poster_srcset' => data_get($posterImage, 'srcset_string'),
            'poster_image' => $posterImage,
            'backdrop_url' => $this->imagePresentationService->resolveSeriesBackdropUrl($series->backdrop_url, $metadata),
            'backdrop_blur_url' => data_get($backdropImage, 'blur'),
            'backdrop_srcset' => data_get($backdropImage, 'srcset_string'),
            'backdrop_image' => $backdropImage,
            'genres' => $this->genres($series),
            'rating' => $this->rating($series),
            'detail_backdrop_url' => $this->detailBackdropUrl($series),
        ];
    }

    /**
     * Full overview for detail page — built once per request (no GraphQL @hasMany fan-out).
     *
     * @return array<string, mixed>
     */
    public function overview(Series $series): array
    {
        $metadata = is_array($series->metadata) ? $series->metadata : [];
        $details = $this->details($series);
        $posterImage = $this->imagePresentationService->seriesPosterImage($metadata);
        $backdropImage = $this->imagePresentationService->seriesBackdropImage($metadata);

        return [
            'id' => (string) $series->id,
            'slug' => $series->slug,
            'title' => $series->title,
            'original_title' => $series->original_title,
            'synopsis' => $series->synopsis,
            'first_air_date' => $series->first_air_date?->toDateString(),
            'last_air_date' => $series->last_air_date?->toDateString(),
            'release_year' => $series->release_year,
            'status' => $series->status,
            'status_label_fa' => $this->statusLabelFa($series->status),
            'episode_runtime_minutes' => $series->episode_runtime_minutes,
            'number_of_seasons' => $series->number_of_seasons,
            'number_of_episodes' => $series->number_of_episodes,
            'network' => $series->network,
            'provider_name' => $series->provider_name ?: $series->network,
            'genre_tags' => collect($this->genreCatalogService->serializeGenres($series->genres))
                ->map(fn (array $genre) => [
                    'id' => (string) $genre['id'],
                    'slug' => (string) $genre['slug'],
                    'name' => (string) $genre['name'],
                ])
                ->values()
                ->all(),
            'poster_url' => $this->imagePresentationService->resolveSeriesPosterUrl($series->poster_url, $metadata),
            'poster_blur_url' => data_get($posterImage, 'blur'),
            'poster_srcset' => data_get($posterImage, 'srcset_string'),
            'poster_image' => $posterImage,
            'backdrop_url' => $this->imagePresentationService->resolveSeriesBackdropUrl($series->backdrop_url, $metadata),
            'backdrop_blur_url' => data_get($backdropImage, 'blur'),
            'backdrop_srcset' => data_get($backdropImage, 'srcset_string'),
            'backdrop_image' => $backdropImage,
            'trailer_url' => $series->trailer_url,
            'age_rating' => $series->age_rating,
            'meta_title' => data_get($metadata, 'seo.meta_title'),
            'meta_description' => data_get($metadata, 'seo.meta_description'),
            'content_warnings' => ContentMetadataOptions::filterAllowedList(
                data_get($metadata, 'content_warnings'),
                ContentMetadataOptions::CONTENT_WARNINGS,
            ),
            'quality_badges' => ContentMetadataOptions::filterAllowedList(
                data_get($metadata, 'quality_badges'),
                ContentMetadataOptions::QUALITY_BADGES,
            ),
            'has_subtitle' => $this->hasSubtitle($series),
            'has_dubbed' => $this->hasDubbed($series),
            'total_seasons' => $series->relationLoaded('seasons')
                ? ($series->seasons->count() ?: $series->number_of_seasons)
                : $series->number_of_seasons,
            'cast' => $this->castRows($series),
            'crew' => $this->crewRows($series),
            'seasons' => $series->seasons
                ->map(fn (SeriesSeason $season) => [
                    'id' => (string) $season->id,
                    'season_number' => $season->season_number,
                    'title' => $season->name,
                ])
                ->values()
                ->all(),
            'details' => $details,
        ];
    }

    /**
     * @return list<string>
     */
    public function genres(Series $series): array
    {
        $series->loadMissing('genres');

        if ($series->genres->isNotEmpty()) {
            return $series->genres->pluck('name')->values()->all();
        }

        $metadata = is_array($series->metadata) ? $series->metadata : [];

        return $this->tmdbService->localizeGenreNames(
            data_get($metadata, 'tmdb.genres', []),
            'tv',
        );
    }

    public function rating(Series $series): mixed
    {
        $metadata = is_array($series->metadata) ? $series->metadata : [];

        return data_get($metadata, 'tmdb.rating');
    }

    public function detailBackdropUrl(Series $series): ?string
    {
        $metadata = is_array($series->metadata) ? $series->metadata : [];

        return $series->backdrop_url ?: data_get($metadata, 'tmdb.backdrop_url');
    }

    /**
     * @return array<string, mixed>
     */
    public function details(Series $series): array
    {
        $metadata = is_array($series->metadata) ? $series->metadata : [];

        return [
            'backdrop_url' => $series->backdrop_url ?: data_get($metadata, 'tmdb.backdrop_url'),
            'rating' => data_get($metadata, 'tmdb.rating'),
            'original_language' => data_get($metadata, 'tmdb.original_language'),
            'creators' => array_values(data_get($metadata, 'tmdb.creators', []) ?: []),
            'directors' => array_values(data_get($metadata, 'tmdb.directors', []) ?: []),
            'writers' => array_values(data_get($metadata, 'tmdb.writers', []) ?: []),
            'genres' => $this->genres($series),
            'languages' => array_values(data_get($metadata, 'tmdb.languages', []) ?: []),
            'countries' => array_values(data_get($metadata, 'tmdb.countries', []) ?: []),
            'network' => $series->provider_name ?: $series->network ?: data_get($metadata, 'tmdb.network'),
            'actors' => $this->actorRows($series, $metadata),
            'gallery' => $this->gallery($series, $metadata),
        ];
    }

    public function statusLabelFa(?string $status): ?string
    {
        return match ($status) {
            'Returning Series' => 'در حال پخش',
            'Ended' => 'پایان یافته',
            'Canceled' => 'لغو شده',
            'In Production' => 'در حال تولید',
            'Pilot' => 'پایلوت',
            default => $status,
        };
    }

    public function hasSubtitle(Series $series): bool
    {
        if (isset($series->has_subtitle)) {
            return (bool) $series->has_subtitle;
        }

        return $this->seriesHasPlayableEpisodeAttribute($series, 'subtitle_url');
    }

    public function hasDubbed(Series $series): bool
    {
        if (isset($series->has_dubbed)) {
            return (bool) $series->has_dubbed;
        }

        return $this->seriesHasPlayableEpisodeAttribute($series, 'dubbed_audio_url');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function castRows(Series $series): array
    {
        $metadata = is_array($series->metadata) ? $series->metadata : [];
        $metadataActors = $this->metadataActorLookup($metadata);

        $rows = $series->relationLoaded('castMembers')
            ? $series->castMembers
            : $series->castMembers()->with('person')->get();

        return $rows
            ->sortBy('cast_order')
            ->values()
            ->map(function (SeriesCast $row) use ($metadataActors) {
                $person = $row->person;
                $profileImage = $person ? $this->imagePresentationService->personProfileImage($person) : null;
                $profileUrl = $person
                    ? $this->imagePresentationService->resolvePersonProfileUrl($person)
                    : null;

                $metadataActor = $this->matchMetadataActor($metadataActors, $row, $person);
                $metadataProfile = $this->resolveMetadataActorProfile($metadataActor);

                if ($metadataProfile !== null) {
                    $profileUrl = $metadataProfile['profile_url'] ?? $profileUrl;
                    $profileImage = $metadataProfile['profile_image'] ?? $profileImage;
                }

                return [
                    'name' => $person?->name,
                    'slug' => $person?->slug,
                    'character_name' => $row->character_name,
                    'profile_url' => $profileUrl,
                    'profile_blur_url' => data_get($profileImage, 'blur'),
                    'profile_srcset' => data_get($profileImage, 'srcset_string'),
                    'profile_image' => $profileImage,
                    'order' => $row->cast_order,
                ];
            })
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function crewRows(Series $series): array
    {
        $rows = $series->relationLoaded('crewMembers')
            ? $series->crewMembers
            : $series->crewMembers()->with('person')->get();

        return $rows
            ->map(fn (SeriesCrew $row) => [
                'name' => $row->person?->name,
                'slug' => $row->person?->slug,
                'job' => $row->job,
                'department' => $row->department,
                'profile_url' => $row->person
                    ? $this->imagePresentationService->resolvePersonProfileUrl($row->person)
                    : null,
                'profile_blur_url' => data_get($row->person ? $this->imagePresentationService->personProfileImage($row->person) : null, 'blur'),
                'profile_srcset' => data_get($row->person ? $this->imagePresentationService->personProfileImage($row->person) : null, 'srcset_string'),
                'profile_image' => $row->person ? $this->imagePresentationService->personProfileImage($row->person) : null,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return list<array<string, mixed>>
     */
    private function actorRows(Series $series, array $metadata): array
    {
        $fromCast = collect($this->castRows($series))
            ->map(fn (array $row) => [
                'name' => $row['name'] ?? null,
                'character' => $row['character_name'] ?? null,
                'profile_url' => $row['profile_url'] ?? null,
                'profile_blur_url' => $row['profile_blur_url'] ?? null,
                'profile_srcset' => $row['profile_srcset'] ?? null,
                'profile_image' => $row['profile_image'] ?? null,
            ]);

        if ($fromCast->isNotEmpty()) {
            return $fromCast->values()->all();
        }

        return collect(data_get($metadata, 'tmdb.actors', []))
            ->filter(fn ($actor) => is_array($actor))
            ->map(function (array $actor) {
                $profile = $this->resolveMetadataActorProfile($actor);

                return [
                    'name' => $actor['name'] ?? null,
                    'character' => $actor['character'] ?? null,
                    'profile_url' => $profile['profile_url'] ?? null,
                    'profile_blur_url' => data_get($profile['profile_image'] ?? null, 'blur'),
                    'profile_srcset' => data_get($profile['profile_image'] ?? null, 'srcset_string'),
                    'profile_image' => $profile['profile_image'] ?? null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, array<string, mixed>>
     */
    private function metadataActorLookup(array $metadata): array
    {
        $lookup = [];

        foreach (data_get($metadata, 'tmdb.actors', []) as $actor) {
            if (! is_array($actor)) {
                continue;
            }

            $tmdbPersonId = $actor['tmdb_person_id'] ?? null;

            if (is_numeric($tmdbPersonId) && (int) $tmdbPersonId > 0) {
                $lookup['person:' . (int) $tmdbPersonId] = $actor;
            }

            $creditId = trim((string) ($actor['credit_id'] ?? ''));

            if ($creditId !== '') {
                $lookup['credit:' . $creditId] = $actor;
            }

            $name = trim((string) ($actor['name'] ?? ''));

            if ($name !== '') {
                $lookup['name:' . mb_strtolower($name)] = $actor;
            }
        }

        return $lookup;
    }

    /**
     * @param  array<string, array<string, mixed>>  $metadataActors
     * @return ?array<string, mixed>
     */
    private function matchMetadataActor(array $metadataActors, SeriesCast $row, ?Person $person): ?array
    {
        $tmdbPersonId = (int) ($person?->tmdb_person_id ?? 0);

        if ($tmdbPersonId > 0 && isset($metadataActors['person:' . $tmdbPersonId])) {
            return $metadataActors['person:' . $tmdbPersonId];
        }

        $creditId = trim((string) ($row->tmdb_credit_id ?? ''));

        if ($creditId !== '' && isset($metadataActors['credit:' . $creditId])) {
            return $metadataActors['credit:' . $creditId];
        }

        $name = trim((string) ($person?->name ?? ''));

        if ($name !== '' && isset($metadataActors['name:' . mb_strtolower($name)])) {
            return $metadataActors['name:' . mb_strtolower($name)];
        }

        return null;
    }

    /**
     * @param  ?array<string, mixed>  $actor
     * @return ?array{profile_url: ?string, profile_image: ?array<string, mixed>}
     */
    private function resolveMetadataActorProfile(?array $actor): ?array
    {
        if ($actor === null) {
            return null;
        }

        $storedImage = is_array($actor['profile_image'] ?? null) ? $actor['profile_image'] : null;
        $fallbackUrl = $actor['profile_url'] ?? null;
        $profileImage = $this->imagePresentationService->personProfileImageFromStored(
            $storedImage,
            $this->managedImageUrl($fallbackUrl),
        );
        $profileUrl = data_get($profileImage, 'original')
            ?? $this->managedImageUrl($fallbackUrl);

        if ($profileUrl === null && $profileImage === null) {
            return null;
        }

        return [
            'profile_url' => $profileUrl,
            'profile_image' => $profileImage,
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return list<string>
     */
    private function managedImageUrl(mixed $url): ?string
    {
        if (! filled($url)) {
            return null;
        }

        $url = trim((string) $url);
        $prefix = '/' . trim((string) config('cinema_images.public_prefix', '/images'), '/');

        if (! str_starts_with($url, $prefix . '/') && $url !== $prefix) {
            return null;
        }

        return $url;
    }

    private function gallery(Series $series, array $metadata): array
    {
        return collect([
            $series->backdrop_url,
            data_get($metadata, 'tmdb.backdrop_url'),
            $series->poster_url,
            data_get($metadata, 'tmdb.poster_url'),
        ])
            ->filter(fn ($value) => filled($value))
            ->unique()
            ->values()
            ->all();
    }

    private function seriesHasPlayableEpisodeAttribute(Series $series, string $column): bool
    {
        $metadata = is_array($series->metadata) ? $series->metadata : [];
        $seriesHlsBase = data_get($metadata, 'playback.hls_base_url');

        $episodes = $series->relationLoaded('episodes')
            ? $series->episodes
            : $series->episodes()->with('season')->get();

        return $episodes->contains(function (SeriesEpisode $episode) use ($seriesHlsBase, $column) {
            $seasonMetadata = is_array($episode->season?->metadata) ? $episode->season->metadata : [];
            $seasonHlsBase = data_get($seasonMetadata, 'playback.hls_base_url');

            if (! filled($episode->hls_url) && ! filled($seriesHlsBase)) {
                return false;
            }

            return filled($episode->{$column});
        });
    }
}
