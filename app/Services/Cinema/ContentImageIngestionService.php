<?php

namespace App\Services\Cinema;

use App\Models\Collection;
use App\Models\Movie;
use App\Models\Person;
use App\Models\Series;
use App\Models\SeriesEpisode;
use App\Models\SeriesSeason;

/**
 * Orchestrates image ingestion for movies, series, seasons, episodes, and people.
 */
class ContentImageIngestionService
{
    public function __construct(
        private readonly ImageIngestionService $imageIngestionService,
        private readonly ImageSourceNormalizer $sourceNormalizer,
        private readonly ImageVariantUrlBuilder $variantUrlBuilder,
    ) {
    }

    /**
     * Decide whether a URL should be passed to ingest() on edit/save.
     * Returns null to skip (already processed, unchanged source, or non-ingestible).
     */
    public function resolveIngestUrl(
        ?string $formUrl,
        ?string $storedProcessedUrl,
        ?string $storedSourceUrl,
    ): ?string {
        if (! filled($formUrl)) {
            return null;
        }

        $formUrl = trim((string) $formUrl);

        if ($this->variantUrlBuilder->isManagedPublicUrl($formUrl)) {
            return null;
        }

        if (str_starts_with($formUrl, '/media/') || str_contains($formUrl, '/placeholders/')) {
            return null;
        }

        if (filled($storedSourceUrl) && $formUrl === trim((string) $storedSourceUrl)) {
            return null;
        }

        if (! $this->sourceNormalizer->isIngestible($formUrl)) {
            return null;
        }

        return $formUrl;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{metadata: array<string, mixed>, poster_path: ?string}
     */
    public function processMoviePublication(
        Movie $movie,
        array $validated,
        array $metadata,
        bool $deferSecondaryImages = false,
    ): array {
        $images = is_array($metadata['images'] ?? null) ? $metadata['images'] : [];
        $tmdb = is_array($metadata['tmdb'] ?? null) ? $metadata['tmdb'] : [];

        foreach ([
            'poster_url' => 'poster',
            'backdrop_url' => 'backdrop',
            'banner_url' => 'banner',
            'logo_url' => 'logo',
            'trailer_url' => null,
        ] as $field => $imageType) {
            if ($field === 'trailer_url') {
                continue;
            }

            if (! array_key_exists($field, $validated) && ! array_key_exists($field, $tmdb)) {
                continue;
            }

            $formUrl = $validated[$field] ?? $tmdb[$field] ?? null;

            if (! filled($formUrl)) {
                continue;
            }

            $storedProcessed = data_get($images, "{$imageType}.original")
                ?? data_get($tmdb, $field);
            $storedSource = data_get($tmdb, $this->sourceMetadataKey($field));
            $ingestUrl = $this->resolveIngestUrl((string) $formUrl, $storedProcessed, $storedSource);

            if ($ingestUrl === null) {
                if ($this->variantUrlBuilder->isManagedPublicUrl((string) $formUrl)) {
                    $tmdb[$field] = $formUrl;
                } elseif (isset($images[$imageType])) {
                    $tmdb[$field] = data_get($images[$imageType], 'original', data_get($tmdb, $field));
                }

                continue;
            }

            if ($deferSecondaryImages && $imageType !== 'poster') {
                $tmdb[$field] = (string) $formUrl;
                $tmdb[$this->sourceMetadataKey($field)] = $ingestUrl;

                continue;
            }

            $originalSource = $ingestUrl;

            $result = $this->imageIngestionService->ingest($ingestUrl, [
                'entity_type' => 'movie',
                'entity_id' => $movie->id,
                'image_type' => $imageType,
                'is_primary' => $imageType === 'poster',
            ]);

            $preferredUrl = $result['processed_url'] ?? (string) $formUrl;
            $tmdb[$field] = $preferredUrl;
            $tmdb[$this->sourceMetadataKey($field)] = $originalSource;

            if ($result['image']) {
                $images[$imageType] = $result['image'];
            }
        }

        $metadata['tmdb'] = $tmdb;
        $metadata['images'] = $images;

        return [
            'metadata' => $metadata,
            'poster_path' => $movie->poster_path,
        ];
    }

    public function processMovieDeferredSecondaryImages(Movie $movie): void
    {
        $metadata = is_array($movie->metadata) ? $movie->metadata : [];
        $images = is_array($metadata['images'] ?? null) ? $metadata['images'] : [];
        $tmdb = is_array($metadata['tmdb'] ?? null) ? $metadata['tmdb'] : [];
        $changed = false;

        foreach ([
            'backdrop_url' => 'backdrop',
            'banner_url' => 'banner',
            'logo_url' => 'logo',
        ] as $field => $imageType) {
            if (isset($images[$imageType])) {
                continue;
            }

            $formUrl = $tmdb[$field] ?? null;
            $storedSource = data_get($tmdb, $this->sourceMetadataKey($field));
            $ingestUrl = $this->resolveIngestUrl(
                is_string($formUrl) ? $formUrl : '',
                null,
                is_string($storedSource) ? $storedSource : null,
            );

            if ($ingestUrl === null) {
                continue;
            }

            $result = $this->imageIngestionService->ingest($ingestUrl, [
                'entity_type' => 'movie',
                'entity_id' => $movie->id,
                'image_type' => $imageType,
                'is_primary' => false,
            ]);

            $preferredUrl = $result['processed_url'] ?? (string) $formUrl;
            $tmdb[$field] = $preferredUrl;
            $tmdb[$this->sourceMetadataKey($field)] = $ingestUrl;

            if ($result['image']) {
                $images[$imageType] = $result['image'];
                $changed = true;
            }
        }

        if (! $changed) {
            return;
        }

        $metadata['tmdb'] = $tmdb;
        $metadata['images'] = $images;
        $movie->update(['metadata' => $metadata]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{
     *   poster_url: ?string,
     *   backdrop_url: ?string,
     *   metadata: array<string, mixed>
     * }
     */
    public function processSeriesPrimaryImages(
        Series $series,
        array $validated,
        array $metadata,
        bool $deferSecondaryImages = false,
    ): array {
        $images = is_array($metadata['images'] ?? null) ? $metadata['images'] : [];
        $updates = [
            'poster_url' => $series->poster_url,
            'backdrop_url' => $series->backdrop_url,
        ];

        foreach ([
            'poster_url' => 'poster',
            'backdrop_url' => 'backdrop',
            'banner_url' => 'banner',
            'logo_url' => 'logo',
        ] as $field => $imageType) {
            $formUrl = $validated[$field] ?? $updates[$field] ?? data_get($metadata, 'tmdb.' . $field);

            if (! filled($formUrl)) {
                continue;
            }

            $storedProcessed = data_get($images, "{$imageType}.original")
                ?? data_get($metadata, 'tmdb.' . $field);
            $storedSource = data_get($metadata, 'tmdb.' . $this->sourceMetadataKey($field));
            $ingestUrl = $this->resolveIngestUrl((string) $formUrl, $storedProcessed, $storedSource);

            if ($ingestUrl === null) {
                if ($this->variantUrlBuilder->isManagedPublicUrl((string) $formUrl)) {
                    $preferred = (string) $formUrl;

                    if (in_array($field, ['poster_url', 'backdrop_url'], true)) {
                        $updates[$field] = $preferred;
                    }

                    data_set($metadata, 'tmdb.' . $field, $preferred);
                } elseif (isset($images[$imageType])) {
                    $preferred = data_get($images[$imageType], 'original');

                    if (in_array($field, ['poster_url', 'backdrop_url'], true) && filled($preferred)) {
                        $updates[$field] = $preferred;
                    }

                    data_set($metadata, 'tmdb.' . $field, $preferred);
                }

                continue;
            }

            if ($deferSecondaryImages && $imageType !== 'poster') {
                if (in_array($field, ['poster_url', 'backdrop_url'], true)) {
                    $updates[$field] = (string) $formUrl;
                }

                data_set($metadata, 'tmdb.' . $field, (string) $formUrl);
                data_set($metadata, 'tmdb.' . $this->sourceMetadataKey($field), $ingestUrl);

                continue;
            }

            $originalSource = $ingestUrl;

            $result = $this->imageIngestionService->ingest($ingestUrl, [
                'entity_type' => 'series',
                'entity_id' => $series->id,
                'image_type' => $imageType,
                'is_primary' => $imageType === 'poster',
            ]);

            $preferredUrl = $result['processed_url'] ?? (string) $formUrl;

            if (in_array($field, ['poster_url', 'backdrop_url'], true)) {
                $updates[$field] = $preferredUrl;
            }

            data_set($metadata, 'tmdb.' . $field, $preferredUrl);
            data_set($metadata, 'tmdb.' . $this->sourceMetadataKey($field), $originalSource);

            if ($result['image']) {
                $images[$imageType] = $result['image'];
            }
        }

        $metadata['images'] = $images;

        return [
            'poster_url' => $updates['poster_url'],
            'backdrop_url' => $updates['backdrop_url'],
            'metadata' => $metadata,
        ];
    }

    public function processSeriesDeferredSecondaryImages(Series $series): void
    {
        $metadata = is_array($series->metadata) ? $series->metadata : [];
        $images = is_array($metadata['images'] ?? null) ? $metadata['images'] : [];
        $updates = [
            'poster_url' => $series->poster_url,
            'backdrop_url' => $series->backdrop_url,
        ];
        $changed = false;

        foreach ([
            'backdrop_url' => 'backdrop',
            'banner_url' => 'banner',
            'logo_url' => 'logo',
        ] as $field => $imageType) {
            if (isset($images[$imageType])) {
                continue;
            }

            $formUrl = data_get($metadata, 'tmdb.' . $field);
            $storedSource = data_get($metadata, 'tmdb.' . $this->sourceMetadataKey($field));
            $ingestUrl = $this->resolveIngestUrl(
                is_string($formUrl) ? $formUrl : '',
                null,
                is_string($storedSource) ? $storedSource : null,
            );

            if ($ingestUrl === null) {
                continue;
            }

            $result = $this->imageIngestionService->ingest($ingestUrl, [
                'entity_type' => 'series',
                'entity_id' => $series->id,
                'image_type' => $imageType,
                'is_primary' => false,
            ]);

            $preferredUrl = $result['processed_url'] ?? (string) $formUrl;

            if (in_array($field, ['poster_url', 'backdrop_url'], true)) {
                $updates[$field] = $preferredUrl;
            }

            data_set($metadata, 'tmdb.' . $field, $preferredUrl);
            data_set($metadata, 'tmdb.' . $this->sourceMetadataKey($field), $ingestUrl);

            if ($result['image']) {
                $images[$imageType] = $result['image'];
                $changed = true;
            }
        }

        if (! $changed) {
            return;
        }

        $metadata['images'] = $images;
        $series->update([
            'poster_url' => $updates['poster_url'],
            'backdrop_url' => $updates['backdrop_url'],
            'metadata' => $metadata,
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{
     *   poster_url: ?string,
     *   backdrop_url: ?string,
     *   metadata: array<string, mixed>
     * }
     */
    public function processCollectionImages(Collection $collection, array $validated, array $metadata): array
    {
        $images = is_array($metadata['images'] ?? null) ? $metadata['images'] : [];
        $updates = [
            'poster_url' => $collection->poster_url,
            'backdrop_url' => $collection->backdrop_url,
        ];

        foreach ([
            'poster_url' => 'poster',
            'backdrop_url' => 'backdrop',
        ] as $field => $imageType) {
            $formUrl = $validated[$field] ?? $updates[$field] ?? data_get($metadata, 'tmdb.'.$field);

            if (! filled($formUrl)) {
                continue;
            }

            $storedProcessed = data_get($images, "{$imageType}.original")
                ?? data_get($metadata, 'tmdb.'.$field);
            $storedSource = data_get($metadata, 'tmdb.'.$this->sourceMetadataKey($field));
            $ingestUrl = $this->resolveIngestUrl((string) $formUrl, $storedProcessed, $storedSource);

            if ($ingestUrl === null) {
                if ($this->variantUrlBuilder->isManagedPublicUrl((string) $formUrl)) {
                    $preferred = (string) $formUrl;
                    $updates[$field] = $preferred;
                    data_set($metadata, 'tmdb.'.$field, $preferred);
                } elseif (isset($images[$imageType])) {
                    $preferred = data_get($images[$imageType], 'original');
                    if (filled($preferred)) {
                        $updates[$field] = $preferred;
                    }
                    data_set($metadata, 'tmdb.'.$field, $preferred);
                }

                continue;
            }

            $originalSource = $ingestUrl;

            $result = $this->imageIngestionService->ingest($ingestUrl, [
                'entity_type' => 'collection',
                'entity_id' => $collection->id,
                'image_type' => $imageType,
                'is_primary' => $imageType === 'poster',
            ]);

            $preferredUrl = $result['processed_url'] ?? (string) $formUrl;
            $updates[$field] = $preferredUrl;
            data_set($metadata, 'tmdb.'.$field, $preferredUrl);
            data_set($metadata, 'tmdb.'.$this->sourceMetadataKey($field), $originalSource);

            if ($result['image']) {
                $images[$imageType] = $result['image'];
            }
        }

        $metadata['images'] = $images;

        return [
            'poster_url' => $updates['poster_url'],
            'backdrop_url' => $updates['backdrop_url'],
            'metadata' => $metadata,
        ];
    }

    public function processSeasonPoster(SeriesSeason $season, ?string $sourceUrl): ?string
    {
        if (! filled($sourceUrl) || ! $this->sourceNormalizer->isIngestible($sourceUrl)) {
            return $sourceUrl;
        }

        $result = $this->imageIngestionService->ingest($sourceUrl, [
            'entity_type' => 'season',
            'entity_id' => $season->id,
            'image_type' => 'poster',
        ]);

        if ($result['image']) {
            $metadata = is_array($season->metadata) ? $season->metadata : [];
            $metadata['images']['poster'] = $result['image'];
            $season->update(['metadata' => $metadata]);
        }

        return $result['processed_url'] ?? $sourceUrl;
    }

    public function processEpisodeStill(SeriesEpisode $episode, ?string $sourceUrl): ?string
    {
        if (! filled($sourceUrl) || ! $this->sourceNormalizer->isIngestible($sourceUrl)) {
            return $sourceUrl;
        }

        $result = $this->imageIngestionService->ingest($sourceUrl, [
            'entity_type' => 'episode',
            'entity_id' => $episode->id,
            'image_type' => 'still',
        ]);

        if ($result['image']) {
            $metadata = is_array($episode->metadata) ? $episode->metadata : [];
            $metadata['images']['still'] = $result['image'];
            $episode->update(['metadata' => $metadata]);
        }

        return $result['processed_url'] ?? $sourceUrl;
    }

    public function processPersonProfile(Person $person, ?string $sourceUrl): ?string
    {
        if (! filled($sourceUrl) || ! $this->sourceNormalizer->isIngestible($sourceUrl)) {
            return $sourceUrl;
        }

        $result = $this->imageIngestionService->ingest($sourceUrl, [
            'entity_type' => 'person',
            'entity_id' => $person->id,
            'image_type' => 'profile',
            'is_primary' => true,
        ]);

        if ($result['image']) {
            $metadata = is_array($person->metadata) ? $person->metadata : [];
            $metadata['images']['profile'] = $result['image'];
            $metadata['profile_source_url'] = $sourceUrl;
            $person->update([
                'profile_url' => $result['processed_url'] ?? $sourceUrl,
                'metadata' => $metadata,
            ]);
        } elseif ($result['processed_url']) {
            $person->update(['profile_url' => $result['processed_url']]);
        }

        return $result['processed_url'] ?? $sourceUrl;
    }

    /**
     * @param  array<int, array<string, mixed>>  $actors
     * @return array<int, array<string, mixed>>
     */
    public function processMetadataActorProfiles(array $actors, string $entityType, int $entityId): array
    {
        $processed = [];

        foreach ($actors as $actor) {
            if (! is_array($actor)) {
                continue;
            }

            $profileUrl = $actor['profile_url'] ?? null;
            $storedSource = $actor['profile_source_url'] ?? null;
            $storedProcessed = data_get($actor, 'profile_image.original', $profileUrl);
            $ingestUrl = filled($profileUrl)
                ? $this->resolveIngestUrl((string) $profileUrl, $storedProcessed, $storedSource)
                : null;

            if ($ingestUrl === null) {
                $processed[] = $actor;

                continue;
            }

            $originalSource = $ingestUrl;

            $result = $this->imageIngestionService->ingest($ingestUrl, [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'image_type' => 'profile',
            ]);

            if ($result['processed_url']) {
                $actor['profile_url'] = $result['processed_url'];
                $actor['profile_source_url'] = $originalSource;
            }

            if ($result['image']) {
                $actor['profile_image'] = $result['image'];
            }

            $processed[] = $actor;
        }

        return $processed;
    }

    /**
     * Eagerly ingest TMDB image URLs right after admin TMDB sync (parallel downloads).
     *
     * @param  array{
     *   entity_type: string,
     *   entity_id?: ?int,
     *   poster_url?: ?string,
     *   backdrop_url?: ?string,
     *   banner_url?: ?string,
     *   logo_url?: ?string,
     *   actors?: array<int, array<string, mixed>>,
     *   seasons?: array<int, array<string, mixed>>
     * }  $payload
     * @return array<string, mixed>
     */
    public function ingestTmdbSyncBatch(array $payload): array
    {
        $entityType = (string) ($payload['entity_type'] ?? 'movie');
        $entityId = filled($payload['entity_id'] ?? null) ? (int) $payload['entity_id'] : null;
        $maxActors = max(1, (int) config('cinema_images.tmdb_sync_max_actor_profiles', 25));
        $maxEpisodeStills = max(1, (int) config('cinema_images.tmdb_sync_max_episode_stills', 120));

        $jobs = [];
        $jobSources = [];

        foreach ([
            'poster_url' => 'poster',
            'backdrop_url' => 'backdrop',
            'banner_url' => 'banner',
            'logo_url' => 'logo',
        ] as $field => $imageType) {
            $url = $payload[$field] ?? null;

            if (! $this->shouldEagerIngestUrl($url)) {
                continue;
            }

            $key = "primary:{$field}";
            $jobs[$key] = [
                'source_url' => (string) $url,
                'context' => [
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'image_type' => $imageType,
                    'is_primary' => $imageType === 'poster',
                ],
            ];
            $jobSources[$key] = (string) $url;
        }

        $actors = is_array($payload['actors'] ?? null) ? $payload['actors'] : [];
        $actorLimit = min(count($actors), $maxActors);
        $actorIndexToJobKey = [];

        for ($index = 0; $index < $actorLimit; $index++) {
            $actor = $actors[$index];

            if (! is_array($actor)) {
                continue;
            }

            $url = $actor['profile_url'] ?? null;

            if (! $this->shouldEagerIngestUrl($url)) {
                continue;
            }

            try {
                $normalizedSource = $this->sourceNormalizer->normalize((string) $url);
            } catch (\Throwable) {
                continue;
            }

            if ($normalizedSource === null) {
                continue;
            }

            $sourceHash = $this->sourceNormalizer->sourceHash($normalizedSource);
            $key = 'actor:src:' . $sourceHash;
            $actorIndexToJobKey[$index] = $key;

            if (isset($jobs[$key])) {
                continue;
            }

            $jobs[$key] = [
                'source_url' => (string) $url,
                'context' => [
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'image_type' => 'profile',
                ],
            ];
            $jobSources[$key] = (string) $url;
        }

        $seasonPayload = is_array($payload['seasons'] ?? null) ? $payload['seasons'] : [];

        foreach ($seasonPayload as $seasonIndex => $season) {
            if (! is_array($season)) {
                continue;
            }

            $seasonPoster = $season['poster_url'] ?? null;

            if ($this->shouldEagerIngestUrl($seasonPoster)) {
                $key = "season:{$seasonIndex}:poster";
                $jobs[$key] = [
                    'source_url' => (string) $seasonPoster,
                    'context' => [
                        'entity_type' => 'season',
                        'entity_id' => null,
                        'image_type' => 'poster',
                    ],
                ];
                $jobSources[$key] = (string) $seasonPoster;
            }

            $episodes = is_array($season['episodes'] ?? null) ? $season['episodes'] : [];
            $episodeStillCount = 0;

            foreach ($episodes as $episodeIndex => $episode) {
                if ($episodeStillCount >= $maxEpisodeStills) {
                    break;
                }

                if (! is_array($episode)) {
                    continue;
                }

                $stillUrl = $episode['still_url'] ?? null;

                if (! $this->shouldEagerIngestUrl($stillUrl)) {
                    continue;
                }

                $key = "season:{$seasonIndex}:episode:{$episodeIndex}:still";
                $jobs[$key] = [
                    'source_url' => (string) $stillUrl,
                    'context' => [
                        'entity_type' => 'episode',
                        'entity_id' => null,
                        'image_type' => 'still',
                    ],
                ];
                $jobSources[$key] = (string) $stillUrl;
                $episodeStillCount++;
            }
        }

        $ingestResults = $this->imageIngestionService->ingestMany($jobs);

        $response = [
            'poster_url' => $payload['poster_url'] ?? null,
            'backdrop_url' => $payload['backdrop_url'] ?? null,
            'banner_url' => $payload['banner_url'] ?? null,
            'logo_url' => $payload['logo_url'] ?? null,
            'actors' => $actors,
            'seasons' => $seasonPayload,
            'stats' => [
                'requested' => count($jobs),
                'succeeded' => 0,
                'failed' => 0,
            ],
        ];

        foreach ([
            'poster_url' => 'primary:poster_url',
            'backdrop_url' => 'primary:backdrop_url',
            'banner_url' => 'primary:banner_url',
            'logo_url' => 'primary:logo_url',
        ] as $field => $jobKey) {
            if (! isset($ingestResults[$jobKey])) {
                continue;
            }

            $result = $ingestResults[$jobKey];

            if ($result['success'] && filled($result['processed_url'])) {
                $response[$field] = $result['processed_url'];
                $response['stats']['succeeded']++;
            } elseif (isset($jobs[$jobKey])) {
                $response['stats']['failed']++;
            }
        }

        $processedActors = [];

        foreach ($actors as $index => $actor) {
            if (! is_array($actor)) {
                continue;
            }

            $key = $actorIndexToJobKey[$index] ?? null;
            $result = $key !== null ? ($ingestResults[$key] ?? null) : null;

            if ($result && $result['success'] && filled($result['processed_url'])) {
                $actor['profile_url'] = $result['processed_url'];
                $actor['profile_source_url'] = $jobSources[$key] ?? $actor['profile_url'];

                if ($result['image']) {
                    $actor['profile_image'] = $result['image'];
                }

                if ($index < $actorLimit) {
                    $response['stats']['succeeded']++;
                }
            } elseif ($key !== null && isset($jobs[$key]) && $index < $actorLimit) {
                $response['stats']['failed']++;
            }

            $processedActors[] = $actor;
        }

        $response['actors'] = $processedActors;

        $processedSeasons = [];

        foreach ($seasonPayload as $seasonIndex => $season) {
            if (! is_array($season)) {
                continue;
            }

            $posterKey = "season:{$seasonIndex}:poster";
            $posterResult = $ingestResults[$posterKey] ?? null;

            if ($posterResult && $posterResult['success'] && filled($posterResult['processed_url'])) {
                $season['poster_url'] = $posterResult['processed_url'];
                $response['stats']['succeeded']++;
            } elseif (isset($jobs[$posterKey])) {
                $response['stats']['failed']++;
            }

            $episodes = is_array($season['episodes'] ?? null) ? $season['episodes'] : [];
            $processedEpisodes = [];

            foreach ($episodes as $episodeIndex => $episode) {
                if (! is_array($episode)) {
                    continue;
                }

                $stillKey = "season:{$seasonIndex}:episode:{$episodeIndex}:still";
                $stillResult = $ingestResults[$stillKey] ?? null;

                if ($stillResult && $stillResult['success'] && filled($stillResult['processed_url'])) {
                    $episode['still_url'] = $stillResult['processed_url'];
                    $response['stats']['succeeded']++;
                } elseif (isset($jobs[$stillKey])) {
                    $response['stats']['failed']++;
                }

                $processedEpisodes[] = $episode;
            }

            $season['episodes'] = $processedEpisodes;
            $processedSeasons[] = $season;
        }

        $response['seasons'] = $processedSeasons;

        return $response;
    }

    private function shouldEagerIngestUrl(mixed $url): bool
    {
        if (! filled($url)) {
            return false;
        }

        $url = trim((string) $url);

        if ($this->variantUrlBuilder->isManagedPublicUrl($url)) {
            return false;
        }

        return $this->sourceNormalizer->isIngestible($url);
    }

    private function sourceMetadataKey(string $field): string
    {
        return match ($field) {
            'poster_url' => 'poster_source_url',
            'backdrop_url' => 'backdrop_source_url',
            'banner_url' => 'banner_source_url',
            'logo_url' => 'logo_source_url',
            'trailer_url' => 'trailer_source_url',
            default => $field . '_source',
        };
    }
}
