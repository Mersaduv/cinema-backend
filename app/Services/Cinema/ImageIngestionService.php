<?php

namespace App\Services\Cinema;

use App\Models\ImageAsset;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class ImageIngestionService
{
    public function __construct(
        private readonly ImageStorageService $imageStorageService,
        private readonly ImageVariantUrlBuilder $variantUrlBuilder,
        private readonly ImageSourceNormalizer $sourceNormalizer,
    ) {
    }

    /**
     * @param  array{
     *   entity_type?: ?string,
     *   entity_id?: ?int,
     *   image_type: string,
     *   is_primary?: bool,
     *   metadata?: array<string, mixed>
     * }  $context
     * @return array{
     *   success: bool,
     *   processed_url: ?string,
     *   fallback_url: ?string,
     *   image: ?array<string, mixed>,
     *   asset: ?ImageAsset,
     *   error: ?string
     * }
     */
    public function ingest(?string $sourceUrl, array $context, ?array $preDownload = null): array
    {
        $fallbackUrl = filled($sourceUrl) ? trim((string) $sourceUrl) : null;

        if (! filled($fallbackUrl)) {
            return $this->failureResult(null, null, 'Empty image source URL.');
        }

        try {
            $normalizedSource = $this->sourceNormalizer->normalize($fallbackUrl);
        } catch (InvalidArgumentException $exception) {
            return $this->failureResult($fallbackUrl, null, $exception->getMessage());
        }

        if ($normalizedSource === null) {
            return $this->failureResult($fallbackUrl, null, 'Image source could not be normalized.');
        }

        $cached = $this->resolveCachedIngest($fallbackUrl, $normalizedSource, $context);

        if ($cached !== null) {
            return $cached;
        }

        $sourceHash = $this->sourceNormalizer->sourceHash($normalizedSource);
        $existing = ImageAsset::query()
            ->where('source_hash', $sourceHash)
            ->orderByRaw("CASE status WHEN 'ready' THEN 0 WHEN 'processing' THEN 1 ELSE 2 END")
            ->orderByDesc('id')
            ->first();

        $folderType = $this->imageStorageService->resolveFolderType(
            (string) ($context['image_type'] ?? 'image'),
            $context['entity_type'] ?? null,
        );

        if ($existing && in_array($existing->status, ['processing', 'failed'], true)) {
            $failedAsset = $existing;
            $failedAsset->update([
                'entity_type' => $context['entity_type'] ?? $failedAsset->entity_type,
                'entity_id' => $context['entity_id'] ?? $failedAsset->entity_id,
                'image_type' => (string) ($context['image_type'] ?? $failedAsset->image_type),
                'source_url' => $normalizedSource,
                'status' => 'processing',
                'error_message' => null,
                'is_primary' => (bool) ($context['is_primary'] ?? $failedAsset->is_primary),
                'metadata' => $context['metadata'] ?? $failedAsset->metadata,
            ]);
        } else {
            $failedAsset = ImageAsset::query()->create([
                'entity_type' => $context['entity_type'] ?? null,
                'entity_id' => $context['entity_id'] ?? null,
                'image_type' => (string) ($context['image_type'] ?? 'image'),
                'source_url' => $normalizedSource,
                'source_hash' => $sourceHash,
                'original_public_url' => '',
                'status' => 'processing',
                'is_primary' => (bool) ($context['is_primary'] ?? false),
                'folder_type' => $folderType,
                'metadata' => $context['metadata'] ?? null,
            ]);
        }

        try {
            $download = $preDownload ?? $this->downloadImage($normalizedSource, $folderType === 'genres');
            $plan = $this->imageStorageService->buildStoragePlan($folderType, $download['extension']);
            $this->imageStorageService->saveBinary($plan['physical_path'], $download['binary']);

            $checksum = hash('sha256', $download['binary']);
            $duplicateByChecksum = ImageAsset::query()
                ->where('checksum', $checksum)
                ->where('status', 'ready')
                ->where('id', '!=', $failedAsset->id)
                ->first();

            if ($duplicateByChecksum) {
                if (! $this->ensureAssetFileOnCurrentRoot($duplicateByChecksum)) {
                    $duplicateByChecksum->update([
                        'status' => 'failed',
                        'error_message' => 'Duplicate checksum asset missing on current storage root.',
                    ]);
                } else {
                    @unlink($plan['physical_path']);
                    $failedAsset->delete();

                    $this->attachEntityContext($duplicateByChecksum, $context);

                    return $this->successFromAsset($duplicateByChecksum, $fallbackUrl);
                }
            }

            $imageObject = $this->variantUrlBuilder->buildFromPublicPath(
                $plan['public_path'],
                $download['mime_type'],
                $download['width'],
                $download['height'],
            );

            $failedAsset->update([
                'original_public_url' => $imageObject['original'],
                'blur_url' => $imageObject['blur'],
                'src_url' => $imageObject['src'],
                'srcset_json' => $imageObject['srcset'],
                'srcset_string' => $imageObject['srcset_string'],
                'variants_json' => [
                    'blur' => $imageObject['blur'],
                    'src' => $imageObject['src'],
                ],
                'physical_storage_path' => $plan['physical_path'],
                'public_path' => $plan['public_path'],
                'folder_type' => $plan['folder_type'],
                'year' => $plan['year'],
                'filename' => $plan['filename'],
                'extension' => $plan['extension'],
                'mime_type' => $download['mime_type'],
                'width' => $download['width'],
                'height' => $download['height'],
                'file_size' => strlen($download['binary']),
                'checksum' => $checksum,
                'status' => 'ready',
                'error_message' => null,
            ]);

            $this->bustPresentationCache($context);

            return [
                'success' => true,
                'processed_url' => $imageObject['original'],
                'fallback_url' => $fallbackUrl,
                'image' => $imageObject,
                'asset' => $failedAsset->fresh(),
                'error' => null,
            ];
        } catch (\Throwable $exception) {
            Log::warning('ImageIngestionService: ingest failed', [
                'source_url' => $normalizedSource,
                'image_type' => $context['image_type'] ?? null,
                'error' => $exception->getMessage(),
            ]);

            $failedAsset->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
            ]);

            return $this->failureResult($fallbackUrl, $failedAsset->fresh(), $exception->getMessage());
        }
    }

    /**
     * Batch-ingest multiple images with parallel HTTP downloads.
     *
     * @param  array<string, array{source_url: string, context: array<string, mixed>}>  $jobs
     * @return array<string, array{success: bool, processed_url: ?string, fallback_url: ?string, image: ?array<string, mixed>, asset: ?ImageAsset, error: ?string}>
     */
    public function ingestMany(array $jobs): array
    {
        if ($jobs === []) {
            return [];
        }

        $results = [];
        $pendingJobs = [];
        $urlAllowSvg = [];

        foreach ($jobs as $key => $job) {
            $sourceUrl = trim((string) ($job['source_url'] ?? ''));
            $context = is_array($job['context'] ?? null) ? $job['context'] : [];

            if ($sourceUrl === '') {
                $results[$key] = $this->failureResult(null, null, 'Empty image source URL.');

                continue;
            }

            try {
                $normalizedSource = $this->sourceNormalizer->normalize($sourceUrl);
            } catch (InvalidArgumentException $exception) {
                $results[$key] = $this->failureResult($sourceUrl, null, $exception->getMessage());

                continue;
            }

            if ($normalizedSource === null) {
                $results[$key] = $this->failureResult($sourceUrl, null, 'Image source could not be normalized.');

                continue;
            }

            $cached = $this->resolveCachedIngest($sourceUrl, $normalizedSource, $context);

            if ($cached !== null) {
                $results[$key] = $cached;

                continue;
            }

            $folderType = $this->imageStorageService->resolveFolderType(
                (string) ($context['image_type'] ?? 'image'),
                $context['entity_type'] ?? null,
            );

            $pendingJobs[$key] = [
                'source_url' => $sourceUrl,
                'normalized_source' => $normalizedSource,
                'context' => $context,
            ];
            $urlAllowSvg[$normalizedSource] = $folderType === 'genres';
        }

        if ($pendingJobs === []) {
            return $results;
        }

        $downloads = $this->downloadManyInParallel(array_keys($urlAllowSvg), $urlAllowSvg);

        foreach ($pendingJobs as $key => $job) {
            $download = $downloads[$job['normalized_source']] ?? null;

            if ($download === null) {
                $results[$key] = $this->failureResult($job['source_url'], null, 'Image download failed.');

                continue;
            }

            $results[$key] = $this->ingest($job['source_url'], $job['context'], $download);
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{success: bool, processed_url: ?string, fallback_url: ?string, image: ?array<string, mixed>, asset: ?ImageAsset, error: ?string}|null
     */
    private function resolveCachedIngest(string $fallbackUrl, string $normalizedSource, array $context): ?array
    {
        if ($this->variantUrlBuilder->isManagedPublicUrl($normalizedSource)) {
            $existingManaged = ImageAsset::query()
                ->where('original_public_url', $normalizedSource)
                ->where('status', 'ready')
                ->first();

            if ($existingManaged && $this->ensureAssetFileOnCurrentRoot($existingManaged)) {
                return $this->successFromAsset($existingManaged, $fallbackUrl);
            }

            // Managed URL without a real file must NOT be treated as success — force re-ingest
            // from the original remote source (fallbackUrl) when available.
            if ($this->sourceNormalizer->isIngestible($fallbackUrl) && ! $this->variantUrlBuilder->isManagedPublicUrl($fallbackUrl)) {
                return null;
            }

            return $this->failureResult(
                $fallbackUrl,
                $existingManaged,
                'Managed image URL has no file on disk.',
            );
        }

        $sourceHash = $this->sourceNormalizer->sourceHash($normalizedSource);
        $existing = ImageAsset::query()
            ->where('source_hash', $sourceHash)
            ->orderByRaw("CASE status WHEN 'ready' THEN 0 WHEN 'processing' THEN 1 ELSE 2 END")
            ->orderByDesc('id')
            ->first();

        if ($existing?->status === 'ready') {
            if (! $this->ensureAssetFileOnCurrentRoot($existing)) {
                $existing->update([
                    'status' => 'failed',
                    'error_message' => 'Image file is missing on current storage root; re-ingesting.',
                ]);

                return null;
            }

            $this->attachEntityContext($existing, $context);

            return $this->successFromAsset($existing, $fallbackUrl);
        }

        return null;
    }

    private function ensureAssetFileOnCurrentRoot(ImageAsset $asset): bool
    {
        $publicUrl = (string) ($asset->original_public_url ?? '');

        if ($publicUrl === '') {
            return filled($asset->physical_storage_path) && is_file((string) $asset->physical_storage_path);
        }

        $physical = $this->imageStorageService->ensureManagedFileOnCurrentRoot(
            $publicUrl,
            filled($asset->physical_storage_path) ? (string) $asset->physical_storage_path : null,
        );

        if ($physical === null) {
            return false;
        }

        if ((string) $asset->physical_storage_path !== $physical) {
            $asset->update(['physical_storage_path' => $physical]);
            $asset->physical_storage_path = $physical;
        }

        return true;
    }

    /**
     * @param  array<int, string>  $urls
     * @param  array<string, bool>  $urlAllowSvg
     * @return array<string, array{binary: string, mime_type: string, extension: string, width: ?int, height: ?int}>
     */
    private function downloadManyInParallel(array $urls, array $urlAllowSvg): array
    {
        if ($urls === []) {
            return [];
        }

        $timeout = (int) config('cinema_images.download_timeout', 30);
        $verifySsl = (bool) config('services.tmdb.verify_ssl', true);
        $headers = [
            'User-Agent' => 'CinemaImageIngestion/1.0',
            'Accept' => 'image/*,*/*;q=0.8',
        ];

        $responses = Http::pool(function ($pool) use ($urls, $timeout, $verifySsl, $headers) {
            $requests = [];

            foreach ($urls as $url) {
                $requests[] = $pool->as($url)
                    ->withOptions(['verify' => $verifySsl])
                    ->withHeaders($headers)
                    ->timeout($timeout)
                    ->get($url);
            }

            return $requests;
        });

        $maxSize = (int) config('cinema_images.max_download_size', 15 * 1024 * 1024);
        $downloads = [];

        foreach ($urls as $url) {
            $response = $responses[$url] ?? null;

            if ($response === null || ! $response->successful()) {
                continue;
            }

            try {
                $binary = (string) $response->body();

                if ($binary === '' || strlen($binary) > $maxSize) {
                    continue;
                }

                $mimeType = strtolower(trim((string) ($response->header('Content-Type') ?: 'application/octet-stream')));
                $mimeType = explode(';', $mimeType)[0];
                $allowSvg = $urlAllowSvg[$url] ?? false;
                $this->assertAllowedMimeType($mimeType, $allowSvg);
                $extension = $this->resolveExtension($url, $mimeType, $allowSvg);
                [$width, $height] = $this->resolveDimensions($binary, $mimeType);

                $downloads[$url] = [
                    'binary' => $binary,
                    'mime_type' => $mimeType,
                    'extension' => $extension,
                    'width' => $width,
                    'height' => $height,
                ];
            } catch (\Throwable) {
                continue;
            }
        }

        return $downloads;
    }

    /**
     * @return array{binary: string, mime_type: string, extension: string, width: ?int, height: ?int}
     */
    private function downloadImage(string $url, bool $allowSvg): array
    {
        $timeout = (int) config('cinema_images.download_timeout', 30);
        $maxSize = (int) config('cinema_images.max_download_size', 15 * 1024 * 1024);
        $retries = max(0, (int) config('cinema_images.download_retries', 2));
        $lastException = null;

        for ($attempt = 0; $attempt <= $retries; $attempt++) {
            try {
                $response = Http::withOptions([
                    'verify' => (bool) config('services.tmdb.verify_ssl', true),
                ])
                    ->withHeaders([
                        'User-Agent' => 'CinemaImageIngestion/1.0',
                        'Accept' => 'image/*,*/*;q=0.8',
                    ])
                    ->timeout($timeout)
                    ->get($url);

                if (! $response->successful()) {
                    throw new InvalidArgumentException('Image download failed with HTTP ' . $response->status() . '.');
                }

                $binary = (string) $response->body();

                if ($binary === '') {
                    throw new InvalidArgumentException('Downloaded image body is empty.');
                }

                if (strlen($binary) > $maxSize) {
                    throw new InvalidArgumentException('Downloaded image exceeds maximum allowed size.');
                }

                $mimeType = strtolower(trim((string) ($response->header('Content-Type') ?: 'application/octet-stream')));
                $mimeType = explode(';', $mimeType)[0];
                $this->assertAllowedMimeType($mimeType, $allowSvg);

                $extension = $this->resolveExtension($url, $mimeType, $allowSvg);
                [$width, $height] = $this->resolveDimensions($binary, $mimeType);

                return [
                    'binary' => $binary,
                    'mime_type' => $mimeType,
                    'extension' => $extension,
                    'width' => $width,
                    'height' => $height,
                ];
            } catch (\Throwable $exception) {
                $lastException = $exception;

                if ($attempt < $retries) {
                    usleep(200_000 * ($attempt + 1));
                }
            }
        }

        throw new InvalidArgumentException($lastException?->getMessage() ?? 'Image download failed.');
    }

    private function assertAllowedMimeType(string $mimeType, bool $allowSvg): void
    {
        $allowed = $allowSvg
            ? (array) config('cinema_images.allowed_mime_types', [])
            : (array) config('cinema_images.allowed_raster_mime_types', []);

        if (! in_array($mimeType, $allowed, true)) {
            throw new InvalidArgumentException('Unsupported downloaded image mime type: ' . $mimeType);
        }
    }

    private function resolveExtension(string $url, string $mimeType, bool $allowSvg): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $pathExtension = is_string($path) ? strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) : '';

        if ($pathExtension !== '' && in_array($pathExtension, ['jpg', 'jpeg', 'png', 'webp', 'svg'], true)) {
            return $this->imageStorageService->sanitizeExtension($pathExtension === 'svg' && ! $allowSvg ? 'jpg' : $pathExtension);
        }

        return $this->imageStorageService->extensionFromMimeType($mimeType, $allowSvg);
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    private function resolveDimensions(string $binary, string $mimeType): array
    {
        if ($mimeType === 'image/svg+xml') {
            return [null, null];
        }

        if (! function_exists('getimagesizefromstring')) {
            return [null, null];
        }

        $size = @getimagesizefromstring($binary);

        if (! is_array($size)) {
            return [null, null];
        }

        return [
            isset($size[0]) ? (int) $size[0] : null,
            isset($size[1]) ? (int) $size[1] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function attachEntityContext(ImageAsset $asset, array $context): void
    {
        $updates = [];

        if (filled($context['entity_type'] ?? null)) {
            $updates['entity_type'] = $context['entity_type'];
        }

        if (filled($context['entity_id'] ?? null)) {
            $updates['entity_id'] = (int) $context['entity_id'];
        }

        if (($context['is_primary'] ?? false) && ! $asset->is_primary) {
            $updates['is_primary'] = true;
        }

        if ($updates !== []) {
            $asset->update($updates);
        }
    }

    /**
     * @return array{success: bool, processed_url: ?string, fallback_url: ?string, image: ?array<string, mixed>, asset: ?ImageAsset, error: ?string}
     */
    private function successFromAsset(ImageAsset $asset, ?string $fallbackUrl): array
    {
        return [
            'success' => true,
            'processed_url' => $asset->original_public_url,
            'fallback_url' => $fallbackUrl,
            'image' => $asset->toImageObject(),
            'asset' => $asset,
            'error' => null,
        ];
    }

    /**
     * @return array{success: bool, processed_url: ?string, fallback_url: ?string, image: ?array<string, mixed>, asset: ?ImageAsset, error: ?string}
     */
    private function failureResult(?string $fallbackUrl, ?ImageAsset $asset, string $error): array
    {
        return [
            'success' => false,
            'processed_url' => null,
            'fallback_url' => $fallbackUrl,
            'image' => null,
            'asset' => $asset,
            'error' => $error,
        ];
    }

    /**
     * Register variants for a file already stored under /images/... (admin upload).
     *
     * @param  array{
     *   entity_type?: ?string,
     *   entity_id?: ?int,
     *   image_type?: string,
     *   is_primary?: bool,
     *   metadata?: array<string, mixed>
     * }  $context
     * @return array{success: bool, processed_url: ?string, fallback_url: ?string, image: ?array<string, mixed>, asset: ?ImageAsset, error: ?string}
     */
    public function registerUploadedManagedFile(string $publicPath, array $context): array
    {
        $publicPath = explode('?', trim($publicPath), 2)[0];

        if (! $this->variantUrlBuilder->isManagedPublicUrl($publicPath)) {
            return $this->failureResult($publicPath, null, 'Not a managed image path.');
        }

        $physicalPath = $this->imageStorageService->toPhysicalPath($publicPath);

        if (! is_file($physicalPath)) {
            return $this->failureResult($publicPath, null, 'Uploaded file not found on disk.');
        }

        $binary = file_get_contents($physicalPath);

        if ($binary === false) {
            return $this->failureResult($publicPath, null, 'Unable to read uploaded file.');
        }

        $imageInfo = @getimagesizefromstring($binary);
        $mimeType = is_array($imageInfo) && isset($imageInfo['mime'])
            ? (string) $imageInfo['mime']
            : 'image/jpeg';
        $width = is_array($imageInfo) ? ($imageInfo[0] ?? null) : null;
        $height = is_array($imageInfo) ? ($imageInfo[1] ?? null) : null;

        $folderType = $this->imageStorageService->resolveFolderType(
            (string) ($context['image_type'] ?? 'image'),
            $context['entity_type'] ?? null,
        );

        $imageObject = $this->variantUrlBuilder->buildFromPublicPath(
            $publicPath,
            $mimeType,
            is_int($width) ? $width : null,
            is_int($height) ? $height : null,
        );

        $checksum = hash('sha256', $binary);
        $sourceHash = $this->sourceNormalizer->sourceHash($publicPath);

        $asset = ImageAsset::query()
            ->where('original_public_url', $publicPath)
            ->orWhere('public_path', $publicPath)
            ->orderByDesc('id')
            ->first();

        $payload = [
            'entity_type' => $context['entity_type'] ?? null,
            'entity_id' => $context['entity_id'] ?? null,
            'image_type' => (string) ($context['image_type'] ?? 'image'),
            'source_url' => $publicPath,
            'source_hash' => $sourceHash,
            'original_public_url' => $imageObject['original'],
            'blur_url' => $imageObject['blur'],
            'src_url' => $imageObject['src'],
            'srcset_json' => $imageObject['srcset'],
            'srcset_string' => $imageObject['srcset_string'],
            'variants_json' => [
                'blur' => $imageObject['blur'],
                'src' => $imageObject['src'],
            ],
            'physical_storage_path' => $physicalPath,
            'public_path' => $publicPath,
            'folder_type' => $folderType,
            'year' => (int) now()->format('Y'),
            'filename' => basename($publicPath),
            'extension' => pathinfo($publicPath, PATHINFO_EXTENSION),
            'mime_type' => $mimeType,
            'width' => $width,
            'height' => $height,
            'file_size' => strlen($binary),
            'checksum' => $checksum,
            'status' => 'ready',
            'error_message' => null,
            'is_primary' => (bool) ($context['is_primary'] ?? false),
            'metadata' => $context['metadata'] ?? null,
        ];

        if ($asset) {
            $asset->update($payload);
            $asset = $asset->fresh();
        } else {
            $asset = ImageAsset::query()->create($payload);
        }

        $this->bustPresentationCache($context);

        return $this->successFromAsset($asset, $publicPath);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function bustPresentationCache(array $context): void
    {
        $entityType = $context['entity_type'] ?? null;
        $entityId = $context['entity_id'] ?? null;

        if (! filled($entityType) || ! filled($entityId)) {
            return;
        }

        Cache::forget("cinema_img_{$entityType}_{$entityId}");
    }
}
