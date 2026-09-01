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
        private readonly ImageStorageService $imageStorageService,
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
            if ($this->managedPublicFileExists($formUrl)) {
                return null;
            }

            $source = filled($storedSourceUrl) ? trim((string) $storedSourceUrl) : '';

            if (
                $source !== ''
                && ! $this->variantUrlBuilder->isManagedPublicUrl($source)
                && $this->sourceNormalizer->isIngestible($source)
            ) {
                return $source;
            }

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

    private function managedPublicFileExists(string $publicUrl): bool
    {
        try {
            $path = parse_url($publicUrl, PHP_URL_PATH);
            $path = is_string($path) && $path !== '' ? $path : $publicUrl;
            $path = explode('?', $path, 2)[0];

            return is_file($this->imageStorageService->toPhysicalPath($path));
        } catch (\Throwable) {
            return false;
        }
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
                if (
                    $this->variantUrlBuilder->isManagedPublicUrl((string) $formUrl)
                    && $this->managedPublicFileExists((string) $formUrl)
                ) {
                    $tmdb[$field] = $formUrl;

                    if (filled($storedSource)) {
                        $tmdb[$this->sourceMetadataKey($field)] = (string) $storedSource;
                    }
                } elseif (isset($images[$imageType])) {
                    $tmdb[$field] = data_get($images[$imageType], 'original', data_get($tmdb, $field));
                } elseif (filled($storedSource) && $this->sourceNormalizer->isIngestible((string) $storedSource)) {
                    $tmdb[$field] = (string) $storedSource;
                    $tmdb[$this->sourceMetadataKey($field)] = (string) $storedSource;
                }

                continue;
            }

            // Keep remote/source URLs on the record; ProcessMovieImagesJob ingests later
            // so publication-form returns immediately.
            if ($deferSecondaryImages) {
                $displayUrl = $this->variantUrlBuilder->isManagedPublicUrl((string) $formUrl)
                    && ! $this->managedPublicFileExists((string) $formUrl)
                    ? $ingestUrl
                    : (string) $formUrl;

                $tmdb[$field] = $displayUrl;
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

    /**
     * Ingest poster/backdrop/banner/logo that were deferred on save.
     *
     * @return array{poster: bool, backdrop: bool, banner: bool, logo: bool}
     */
    public function processMovieDeferredSecondaryImages(Movie $movie): array
    {
        $metadata = is_array($movie->metadata) ? $movie->metadata : [];
        $images = is_array($metadata['images'] ?? null) ? $metadata['images'] : [];
        $tmdb = is_array($metadata['tmdb'] ?? null) ? $metadata['tmdb'] : [];
        $changed = false;
        $processed = [
            'poster' => isset($images['poster']),
            'backdrop' => isset($images['backdrop']),
            'banner' => isset($images['banner']),
            'logo' => isset($images['logo']),
        ];

        foreach ([
            'poster_url' => 'poster',
            'backdrop_url' => 'backdrop',
            'banner_url' => 'banner',
            'logo_url' => 'logo',
        ] as $field => $imageType) {
            $existingOriginal = data_get($images, "{$imageType}.original");

            if (
                isset($images[$imageType])
                && is_string($existingOriginal)
                && $this->managedPublicFileExists($existingOriginal)
            ) {
                $processed[$imageType] = true;

                continue;
            }

            $formUrl = $tmdb[$field] ?? null;
            $storedSource = data_get($tmdb, $this->sourceMetadataKey($field));
            $ingestUrl = $this->resolveDeferredIngestUrl(
                is_string($formUrl) ? $formUrl : null,
                is_string($storedSource) ? $storedSource : null,
            );

            if ($ingestUrl === null) {
                continue;
            }

            $result = $this->imageIngestionService->ingest($ingestUrl, [
                'entity_type' => 'movie',
                'entity_id' => $movie->id,
                'image_type' => $imageType,
                'is_primary' => $imageType === 'poster',
            ]);

            $preferredUrl = $result['processed_url'] ?? (string) ($formUrl ?? $ingestUrl);
            $tmdb[$field] = $preferredUrl;
            $tmdb[$this->sourceMetadataKey($field)] = $ingestUrl;

            if ($result['image']) {
                $images[$imageType] = $result['image'];
                $processed[$imageType] = true;
                $changed = true;
            }
        }

        if ($changed) {
            $metadata['tmdb'] = $tmdb;
            $metadata['images'] = $images;
            $movie->update(['metadata' => $metadata]);
        }

        return $processed;
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
                if (
                    $this->variantUrlBuilder->isManagedPublicUrl((string) $formUrl)
                    && $this->managedPublicFileExists((string) $formUrl)
                ) {
                    $preferred = (string) $formUrl;

                    if (in_array($field, ['poster_url', 'backdrop_url'], true)) {
                        $updates[$field] = $preferred;
                    }

                    data_set($metadata, 'tmdb.' . $field, $preferred);

                    if (filled($storedSource)) {
                        data_set($metadata, 'tmdb.' . $this->sourceMetadataKey($field), (string) $storedSource);
                    }
                } elseif (isset($images[$imageType])) {
                    $preferred = data_get($images[$imageType], 'original');

                    if (in_array($field, ['poster_url', 'backdrop_url'], true) && filled($preferred)) {
                        $updates[$field] = $preferred;
                    }

                    data_set($metadata, 'tmdb.' . $field, $preferred);
                } elseif (filled($storedSource) && $this->sourceNormalizer->isIngestible((string) $storedSource)) {
                    // Broken managed URL without a recoverable ingest target — keep remote source for display/retry.
                    if (in_array($field, ['poster_url', 'backdrop_url'], true)) {
                        $updates[$field] = (string) $storedSource;
                    }

                    data_set($metadata, 'tmdb.' . $field, (string) $storedSource);
                    data_set($metadata, 'tmdb.' . $this->sourceMetadataKey($field), (string) $storedSource);
                }

                continue;
            }

            // Keep remote/source URLs; ProcessSeriesImagesJob ingests later.
            if ($deferSecondaryImages) {
                $displayUrl = $this->variantUrlBuilder->isManagedPublicUrl((string) $formUrl)
                    && ! $this->managedPublicFileExists((string) $formUrl)
                    ? $ingestUrl
                    : (string) $formUrl;

                if (in_array($field, ['poster_url', 'backdrop_url'], true)) {
                    $updates[$field] = $displayUrl;
                }

                data_set($metadata, 'tmdb.' . $field, $displayUrl);
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

    /**
     * Ingest series poster/backdrop/banner/logo deferred on save.
     *
     * @return array{poster: bool, backdrop: bool, banner: bool, logo: bool}
     */
    public function processSeriesDeferredSecondaryImages(Series $series): array
    {
        $metadata = is_array($series->metadata) ? $series->metadata : [];
        $images = is_array($metadata['images'] ?? null) ? $metadata['images'] : [];
        $updates = [
            'poster_url' => $series->poster_url,
            'backdrop_url' => $series->backdrop_url,
        ];
        $changed = false;
        $processed = [
            'poster' => isset($images['poster']),
            'backdrop' => isset($images['backdrop']),
            'banner' => isset($images['banner']),
            'logo' => isset($images['logo']),
        ];

        foreach ([
            'poster_url' => 'poster',
            'backdrop_url' => 'backdrop',
            'banner_url' => 'banner',
            'logo_url' => 'logo',
        ] as $field => $imageType) {
            $existingOriginal = data_get($images, "{$imageType}.original");

            if (
                isset($images[$imageType])
                && is_string($existingOriginal)
                && $this->managedPublicFileExists($existingOriginal)
            ) {
                $processed[$imageType] = true;

                continue;
            }

            $formUrl = data_get($metadata, 'tmdb.' . $field) ?? $updates[$field] ?? null;
            $storedSource = data_get($metadata, 'tmdb.' . $this->sourceMetadataKey($field));

            if (
                is_string($formUrl)
                && $this->variantUrlBuilder->isManagedPublicUrl($formUrl)
                && $this->managedPublicFileExists($formUrl)
                && ! filled($storedSource)
            ) {
                $processed[$imageType] = true;

                continue;
            }

            $ingestUrl = $this->resolveDeferredIngestUrl(
                is_string($formUrl) ? $formUrl : null,
                is_string($storedSource) ? $storedSource : null,
            );

            if ($ingestUrl === null) {
                continue;
            }

            $result = $this->imageIngestionService->ingest($ingestUrl, [
                'entity_type' => 'series',
                'entity_id' => $series->id,
                'image_type' => $imageType,
                'is_primary' => $imageType === 'poster',
            ]);

            $preferredUrl = $result['processed_url'] ?? (string) ($formUrl ?? $ingestUrl);

            if (in_array($field, ['poster_url', 'backdrop_url'], true)) {
                $updates[$field] = $preferredUrl;
            }

            data_set($metadata, 'tmdb.' . $field, $preferredUrl);
            data_set($metadata, 'tmdb.' . $this->sourceMetadataKey($field), $ingestUrl);

            if ($result['image']) {
                $images[$imageType] = $result['image'];
                $processed[$imageType] = true;
                $changed = true;
            }
        }

        if ($changed) {
            $metadata['images'] = $images;
            $series->update([
                'poster_url' => $updates['poster_url'],
                'backdrop_url' => $updates['backdrop_url'],
                'metadata' => $metadata,
            ]);
        }

        return $processed;
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
                $response[$field] = $this->preferRelativeManagedPublicUrl($result['processed_url']);
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
                $actor['profile_url'] = $this->preferRelativeManagedPublicUrl($result['processed_url']);
                $actor['profile_source_url'] = $jobSources[$key] ?? ($actor['profile_source_url'] ?? null);

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
                $season['poster_url'] = $this->preferRelativeManagedPublicUrl($posterResult['processed_url']);
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
                    $episode['still_url'] = $this->preferRelativeManagedPublicUrl($stillResult['processed_url']);
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

    /**
     * Admin form fields expect relative managed paths (/images/...), even when
     * CINEMA_IMAGE_PUBLIC_BASE_URL makes asset.original_public_url absolute.
     */
    private function preferRelativeManagedPublicUrl(?string $url): ?string
    {
        if (! filled($url)) {
            return $url;
        }

        $url = trim((string) $url);

        if (! $this->variantUrlBuilder->isManagedPublicUrl($url)) {
            return $url;
        }

        $path = parse_url($url, PHP_URL_PATH);

        if (is_string($path) && str_starts_with($path, '/images/')) {
            return $path;
        }

        if (str_starts_with($url, '/images/')) {
            return explode('?', $url, 2)[0];
        }

        return $url;
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

    /**
     * Persist an admin-uploaded managed image onto a movie/series record immediately.
     *
     * @param  array<string, mixed>|null  $imageObject
     * @return array{entity_type: string, entity_id: int, image_role: string, image_path: string}
     */
    public function syncUploadedCatalogImage(
        string $entityType,
        int $entityId,
        string $imageRole,
        string $publicPath,
        ?array $imageObject = null,
    ): array {
        $publicPath = $this->preferRelativeManagedPublicUrl($publicPath) ?? trim($publicPath);
        $field = $imageRole === 'backdrop' ? 'backdrop_url' : 'poster_url';
        $imageType = $imageRole === 'backdrop' ? 'backdrop' : 'poster';

        if ($entityType === 'movie') {
            $movie = Movie::query()->findOrFail($entityId);
            $metadata = is_array($movie->metadata) ? $movie->metadata : [];
            $images = is_array($metadata['images'] ?? null) ? $metadata['images'] : [];
            $tmdb = is_array($metadata['tmdb'] ?? null) ? $metadata['tmdb'] : [];

            if ($imageObject) {
                $images[$imageType] = $imageObject;
            }

            $tmdb[$field] = $publicPath;
            unset($tmdb[$this->sourceMetadataKey($field)]);
            $metadata['images'] = $images;
            $metadata['tmdb'] = $tmdb;

            $movie->update([
                'poster_path' => $imageRole === 'poster' ? $publicPath : $movie->poster_path,
                'metadata' => $metadata,
            ]);
        } elseif ($entityType === 'series') {
            $series = Series::query()->findOrFail($entityId);
            $metadata = is_array($series->metadata) ? $series->metadata : [];
            $images = is_array($metadata['images'] ?? null) ? $metadata['images'] : [];
            $tmdb = is_array($metadata['tmdb'] ?? null) ? $metadata['tmdb'] : [];

            if ($imageObject) {
                $images[$imageType] = $imageObject;
            }

            $tmdb[$field] = $publicPath;
            unset($tmdb[$this->sourceMetadataKey($field)]);
            $metadata['images'] = $images;
            $metadata['tmdb'] = $tmdb;

            $series->update([
                $field => $publicPath,
                'metadata' => $metadata,
            ]);
        }

        return [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'image_role' => $imageRole,
            'image_path' => $publicPath,
        ];
    }

    /**
     * Resolve a remote image URL queued during save (source preferred over display URL).
     * Unlike resolveIngestUrl(), matching form+source is expected before ingest and must not skip.
     */
    public function resolveDeferredIngestUrl(?string $formUrl, ?string $storedSourceUrl): ?string
    {
        $candidate = filled($storedSourceUrl)
            ? trim((string) $storedSourceUrl)
            : trim((string) ($formUrl ?? ''));

        if ($candidate === '') {
            return null;
        }

        if ($this->variantUrlBuilder->isManagedPublicUrl($candidate)) {
            return null;
        }

        if (str_starts_with($candidate, '/media/') || str_contains($candidate, '/placeholders/')) {
            return null;
        }

        if (! $this->sourceNormalizer->isIngestible($candidate)) {
            return null;
        }

        return $candidate;
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
