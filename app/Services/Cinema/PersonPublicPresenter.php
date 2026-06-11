<?php

namespace App\Services\Cinema;

use App\Models\Movie;
use App\Models\Person;
use App\Models\Series;

/**
 * Single source of truth for public person payloads (REST + GraphQL).
 */
class PersonPublicPresenter
{
    public function __construct(
        private readonly ImagePresentationService $imagePresentationService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function listItem(Person $person): array
    {
        $profile = $this->profileFields($person);

        return [
            'id' => $person->id,
            'name' => $person->name,
            'slug' => $person->slug,
            'known_for_department' => $person->known_for_department,
            'profile_url' => $profile['profile_url'],
            'profile_blur_url' => $profile['profile_blur_url'],
            'profile_srcset' => $profile['profile_srcset'],
            'profile_image' => $profile['profile_image'],
            'movies_on_platform_count' => (int) ($person->movies_on_platform_count ?? 0),
            'series_on_platform_count' => (int) ($person->series_on_platform_count ?? 0),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $metadataActors
     * @return array{
     *   profile_url: ?string,
     *   profile_blur_url: ?string,
     *   profile_srcset: ?string,
     *   profile_image: ?array<string, mixed>
     * }
     */
    public function profileFields(Person $person, array $metadataActors = []): array
    {
        $profileImage = $this->imagePresentationService->personProfileImage($person);
        $profileUrl = $this->imagePresentationService->resolvePersonProfileUrl($person);

        if ($profileImage === null) {
            $metadataProfile = $this->resolveMetadataActorProfile(
                $this->matchMetadataActor($metadataActors, $person),
            );

            if ($metadataProfile !== null) {
                $profileUrl = $metadataProfile['profile_url'] ?? $profileUrl;
                $profileImage = $metadataProfile['profile_image'] ?? null;
            }
        }

        $enriched = $this->imagePresentationService->enrichListImageFields(
            $profileUrl,
            $profileImage,
        );

        return [
            'profile_url' => $enriched['url'],
            'profile_blur_url' => $enriched['blur_url'],
            'profile_srcset' => $enriched['srcset'],
            'profile_image' => $enriched['image'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $metadataActors
     * @return list<array<string, mixed>>
     */
    public function collectMetadataActorsFromCredits(Person $person): array
    {
        $actors = [];

        foreach ($person->relationLoaded('movieCast') ? $person->movieCast : [] as $row) {
            $movie = $row->movie;

            if (! $movie instanceof Movie) {
                continue;
            }

            $actors = array_merge(
                $actors,
                $this->metadataActorsFromEntity($movie->metadata, $person),
            );
        }

        foreach ($person->relationLoaded('seriesCast') ? $person->seriesCast : [] as $row) {
            $series = $row->series;

            if (! $series instanceof Series) {
                continue;
            }

            $actors = array_merge(
                $actors,
                $this->metadataActorsFromEntity($series->metadata, $person),
            );
        }

        return $actors;
    }

    /**
     * @param  mixed  $metadata
     * @return list<array<string, mixed>>
     */
    private function metadataActorsFromEntity(mixed $metadata, Person $person): array
    {
        if (! is_array($metadata)) {
            return [];
        }

        $matched = [];

        foreach (data_get($metadata, 'tmdb.actors', []) as $actor) {
            if (! is_array($actor)) {
                continue;
            }

            if ($this->metadataActorMatchesPerson($actor, $person)) {
                $matched[] = $actor;
            }
        }

        return $matched;
    }

    /**
     * @param  array<string, mixed>  $actor
     */
    private function metadataActorMatchesPerson(array $actor, Person $person): bool
    {
        $tmdbPersonId = (int) ($person->tmdb_person_id ?? 0);
        $actorTmdbId = $actor['tmdb_person_id'] ?? $actor['id'] ?? null;

        if ($tmdbPersonId > 0 && is_numeric($actorTmdbId) && (int) $actorTmdbId === $tmdbPersonId) {
            return true;
        }

        $actorName = trim((string) ($actor['name'] ?? ''));
        $personName = trim((string) $person->name);

        return $actorName !== '' && $personName !== '' && mb_strtolower($actorName) === mb_strtolower($personName);
    }

    /**
     * @param  list<array<string, mixed>>  $metadataActors
     * @return ?array<string, mixed>
     */
    private function matchMetadataActor(array $metadataActors, Person $person): ?array
    {
        foreach ($metadataActors as $actor) {
            if (! is_array($actor)) {
                continue;
            }

            if ($this->metadataActorMatchesPerson($actor, $person)) {
                return $actor;
            }
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
        $fallbackUrl = $this->managedImageUrl($actor['profile_url'] ?? null);
        $profileImage = $this->imagePresentationService->personProfileImageFromStored(
            $storedImage,
            $fallbackUrl,
        );
        $profileUrl = data_get($profileImage, 'original') ?? $fallbackUrl;

        if ($profileUrl === null && $profileImage === null) {
            return null;
        }

        return [
            'profile_url' => $profileUrl,
            'profile_image' => $profileImage,
        ];
    }

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
}
