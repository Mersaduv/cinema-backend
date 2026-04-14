<?php

namespace App\Services\Cinema;

use App\Models\Actor;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ActorImageSyncService
{
    public function __construct(
        private readonly MediaPathService $mediaPathService,
    ) {
    }

    public function syncFromImdb(Actor $actor): Actor
    {
        if (!filled($actor->imdb_url)) {
            throw new InvalidArgumentException('برای دریافت تصویر بازیگر، لینک IMDb لازم است.');
        }

        $imdbId = $this->extractImdbNameId((string) $actor->imdb_url);

        if ($imdbId === null) {
            throw new InvalidArgumentException('لینک IMDb بازیگر معتبر نیست.');
        }

        $imageUrl = $this->resolveImageUrl($actor, $imdbId);

        if (!$imageUrl) {
            throw new InvalidArgumentException('تصویر بازیگر از IMDb پیدا نشد.');
        }

        $storedAbsolutePath = $this->downloadActorImage($actor, $imageUrl);

        $actor->forceFill([
            'image_path' => $storedAbsolutePath,
        ])->save();

        return $actor->refresh();
    }

    public function deleteStoredImage(Actor $actor): void
    {
        $actorDirectory = $this->mediaPathService->actorAbsoluteDirectory($actor->slug);

        if (is_dir($actorDirectory)) {
            File::deleteDirectory($actorDirectory);
        }
    }

    private function resolveImageUrl(Actor $actor, string $imdbId): ?string
    {
        $query = rawurlencode(Str::ascii($actor->slug !== '' ? str_replace('-', ' ', $actor->slug) : $actor->name));
        $firstLetter = strtolower(substr($query, 0, 1));

        if ($firstLetter === '' || !ctype_alpha($firstLetter)) {
            $firstLetter = 'a';
        }

        $response = Http::withoutVerifying()->withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36',
            'Accept' => 'application/json,text/plain,*/*',
            'Referer' => 'https://www.imdb.com/',
        ])->timeout(20)->get(
            "https://v2.sg.media-imdb.com/suggestion/{$firstLetter}/{$query}.json"
        );

        if (!$response->successful()) {
            throw new InvalidArgumentException('دریافت اطلاعات بازیگر از IMDb انجام نشد.');
        }

        $results = collect($response->json('d', []));

        $matchedById = $results->first(function (array $item) use ($imdbId) {
            return ($item['id'] ?? null) === $imdbId && filled($item['i']['imageUrl'] ?? null);
        });

        if ($matchedById) {
            return $matchedById['i']['imageUrl'];
        }

        $matchedByLabel = $results->first(function (array $item) use ($actor) {
            $label = Str::lower((string) ($item['l'] ?? ''));
            $actorName = Str::lower(Str::ascii($actor->name));
            $actorSlugLabel = Str::lower(str_replace('-', ' ', $actor->slug));

            return filled($item['i']['imageUrl'] ?? null)
                && ($label === $actorName || $label === $actorSlugLabel);
        });

        return $matchedByLabel['i']['imageUrl'] ?? null;
    }

    private function downloadActorImage(Actor $actor, string $imageUrl): string
    {
        $response = Http::withoutVerifying()->withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36',
            'Referer' => 'https://www.imdb.com/',
        ])->timeout(30)->get($imageUrl);

        if (!$response->successful() || $response->body() === '') {
            throw new InvalidArgumentException('دانلود تصویر بازیگر از IMDb انجام نشد.');
        }

        $actorDirectory = $this->mediaPathService->actorAbsoluteDirectory($actor->slug);
        File::ensureDirectoryExists($actorDirectory);

        foreach (glob($actorDirectory . DIRECTORY_SEPARATOR . 'portrait.*') ?: [] as $existingFile) {
            File::delete($existingFile);
        }

        $extension = $this->resolveImageExtension($imageUrl, (string) $response->header('Content-Type', ''));
        $relativePath = $this->mediaPathService->actorImageRelativePath($actor->slug, $extension);
        $absolutePath = $this->mediaPathService->toAbsolutePath($relativePath);

        File::put($absolutePath, $response->body());

        return $absolutePath;
    }

    private function extractImdbNameId(string $imdbUrl): ?string
    {
        if (preg_match('/(nm\d{7,})/i', $imdbUrl, $matches) !== 1) {
            return null;
        }

        return strtolower($matches[1]);
    }

    private function resolveImageExtension(string $imageUrl, string $contentType): string
    {
        $path = parse_url($imageUrl, PHP_URL_PATH);
        $extension = is_string($path) ? strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) : '';

        if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return $extension === 'jpeg' ? 'jpg' : $extension;
        }

        return match (true) {
            str_contains(strtolower($contentType), 'png') => 'png',
            str_contains(strtolower($contentType), 'webp') => 'webp',
            default => 'jpg',
        };
    }
}
