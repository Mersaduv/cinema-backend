<?php

namespace App\Services\Cinema;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

class TicketAttachmentService
{
    public function __construct(
        private readonly ImageStorageService $imageStorage,
        private readonly ImageVariantUrlBuilder $variantUrlBuilder,
    ) {
    }

    /**
     * @return array{
     *   fileDirectory: string,
     *   fileName: string,
     *   image_path: string,
     *   image_url: string
     * }
     */
    public function storeUploadedImage(UploadedFile $file): array
    {
        return $this->storeUploadedAttachment($file, 'ticket-images', true);
    }

    /**
     * @return array{
     *   fileDirectory: string,
     *   fileName: string,
     *   image_path: string,
     *   image_url: string
     * }
     */
    public function storeUploadedAttachment(
        UploadedFile $file,
        ?string $subdirectory = null,
        ?bool $forceImage = null,
    ): array {
        $mimeType = strtolower((string) $file->getMimeType());
        $isImage = $forceImage ?? str_starts_with($mimeType, 'image/');
        $folder = $subdirectory ?? ($isImage ? 'ticket-images' : 'ticket-files');

        if ($isImage) {
            $extension = $this->imageStorage->extensionFromMimeType($mimeType, true);
        } else {
            $extension = strtolower((string) $file->getClientOriginalExtension());
            if ($extension === '') {
                $extension = 'bin';
            }
        }

        $filename = $this->imageStorage->generateUniqueFilename($extension);
        $publicPath = $this->publicPathFromFilename($filename, $folder);
        $physicalPath = $this->imageStorage->toPhysicalPath($publicPath);

        $binary = file_get_contents($file->getRealPath());
        if ($binary === false) {
            throw new InvalidArgumentException('Unable to read uploaded file.');
        }

        $this->imageStorage->saveBinary($physicalPath, $binary);

        return [
            'fileDirectory' => $this->publicDirectoryPath($folder),
            'fileName' => $filename,
            'image_path' => $publicPath,
            'image_url' => $this->publicUrlFromPath($publicPath),
        ];
    }

    public function resolveImageUrl(?string $directory, ?string $filename): ?string
    {
        if (! $filename || trim($filename) === '') {
            return null;
        }

        $filename = trim($filename);

        if ($directory) {
            $directory = rtrim(trim($directory), '/');

            if (preg_match('#^https?://#i', $directory) === 1) {
                $path = parse_url($directory, PHP_URL_PATH);
                $directory = is_string($path) && $path !== '' ? $path : $directory;
            }

            $managedDirectory = '/' . trim(str_replace('\\', '/', $directory), '/');
            $publicPath = $managedDirectory . '/' . ltrim($filename, '/');

            return $this->publicUrlFromPath($publicPath);
        }

        return $this->publicUrlFromPath($this->publicPathFromFilename($filename));
    }

    public function resolveDisplayUrl(?string $imagePath, ?string $storedUrl = null): ?string
    {
        if (filled($imagePath)) {
            return $this->publicUrlFromPath((string) $imagePath);
        }

        if (! filled($storedUrl)) {
            return null;
        }

        $storedUrl = (string) $storedUrl;

        if (preg_match('#^https?://#i', $storedUrl) === 1) {
            $path = parse_url($storedUrl, PHP_URL_PATH);

            if (is_string($path) && $path !== '') {
                return $this->publicUrlFromPath($path);
            }
        }

        return $this->publicUrlFromPath($storedUrl);
    }

    public function publicPathFromFilename(string $filename, ?string $subdirectory = null): string
    {
        return $this->publicDirectoryPath($subdirectory) . '/' . ltrim($filename, '/');
    }

    public function publicPathFromUpload(?string $directory, string $filename): string
    {
        if (filled($directory)) {
            $normalizedDirectory = '/' . trim(str_replace('\\', '/', (string) $directory), '/');

            return $normalizedDirectory . '/' . ltrim($filename, '/');
        }

        return $this->publicPathFromFilename($filename);
    }

    public function publicUrlFromPath(string $publicPath): string
    {
        return $this->variantUrlBuilder->buildAbsoluteOrRelativeUrl($publicPath);
    }

    private function publicDirectoryPath(?string $subdirectory = null): string
    {
        $folder = $subdirectory ?? 'ticket-images';

        return $this->imageStorage->publicPrefix() . '/' . trim($folder, '/');
    }
}
