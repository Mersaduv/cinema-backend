<?php

namespace App\Services;

use App\Support\OutboundHttpClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class OverviewTranslationService
{
    public function isEnabled(): bool
    {
        if (!config('services.overview_translation.enabled', true)) {
            return false;
        }

        return $this->availableDrivers() !== [];
    }

    public function translateEnglishOverviewToPersian(string $englishText): ?string
    {
        $englishText = trim($englishText);

        if ($englishText === '' || !$this->isEnabled()) {
            return null;
        }

        if ($this->containsPersianScript($englishText)) {
            return $englishText;
        }

        $cacheKey = 'overview_fa_v2:' . hash('sha256', $englishText);
        $ttlDays = max(1, (int) config('services.overview_translation.cache_ttl_days', 90));

        try {
            if (Cache::has($cacheKey)) {
                return Cache::get($cacheKey);
            }

            $translated = $this->translateWithDriverFallback($englishText);

            if ($translated !== null && $translated !== '') {
                Cache::put($cacheKey, $translated, now()->addDays($ttlDays));
            }

            return $translated;
        } catch (\Throwable $exception) {
            Log::warning('overview_translation.failed', [
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function translateWithDriverFallback(string $englishText): ?string
    {
        foreach ($this->availableDrivers() as $driver) {
            try {
                $translated = $this->translateViaDriver($driver, $englishText);

                if ($translated !== null && $translated !== '') {
                    return $translated;
                }
            } catch (\Throwable $exception) {
                Log::warning('overview_translation.driver_failed', [
                    'driver' => $driver,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function availableDrivers(): array
    {
        $configured = strtolower(trim((string) config('services.overview_translation.driver', 'auto')));

        if ($configured !== 'auto') {
            return $this->driverIsUsable($configured) ? [$configured] : [];
        }

        $drivers = [];

        if ($this->driverIsUsable('mymemory')) {
            $drivers[] = 'mymemory';
        }

        if ($this->driverIsUsable('libretranslate')) {
            $drivers[] = 'libretranslate';
        }

        if ($this->driverIsUsable('openai')) {
            $drivers[] = 'openai';
        }

        return $drivers;
    }

    private function driverIsUsable(string $driver): bool
    {
        return match ($driver) {
            'mymemory' => true,
            'libretranslate' => trim((string) config('services.overview_translation.libretranslate_url')) !== '',
            'openai' => trim((string) config('services.overview_translation.api_key')) !== '',
            default => false,
        };
    }

    private function translateViaDriver(string $driver, string $englishText): ?string
    {
        return match ($driver) {
            'mymemory' => $this->translateViaMyMemory($englishText),
            'libretranslate' => $this->translateViaLibreTranslate($englishText),
            'openai' => $this->translateViaOpenAiCompatibleApi($englishText),
            default => throw new RuntimeException("Unsupported overview translation driver [{$driver}]."),
        };
    }

    private function translateViaMyMemory(string $englishText): ?string
    {
        $timeout = max(5, (int) config('services.overview_translation.timeout', 30));
        $email = trim((string) config('services.overview_translation.mymemory_email', ''));
        $chunks = $this->chunkTextForTranslation($englishText, 4200);
        $translatedChunks = [];

        foreach ($chunks as $index => $chunk) {
            if ($index > 0) {
                usleep(250_000);
            }

            $query = [
                'q' => $chunk,
                'langpair' => 'en|fa',
            ];

            if ($email !== '') {
                $query['de'] = $email;
            }

            $response = OutboundHttpClient::configure(
                Http::acceptJson()->timeout($timeout),
            )->get('https://api.mymemory.translated.net/get', $query);

            if (!$response->successful()) {
                throw new RuntimeException(
                    'MyMemory translation failed with status ' . $response->status() . '.',
                );
            }

            $payload = $response->json() ?? [];
            $translated = trim((string) data_get($payload, 'responseData.translatedText', ''));

            if ($translated === '' || $this->looksLikeMyMemoryError($translated)) {
                throw new RuntimeException('MyMemory returned an empty or invalid translation.');
            }

            $translatedChunks[] = $translated;
        }

        $result = trim(implode("\n\n", $translatedChunks));

        if ($result === '' || !$this->containsPersianScript($result)) {
            return null;
        }

        return $result;
    }

    private function translateViaLibreTranslate(string $englishText): ?string
    {
        $baseUrl = rtrim((string) config('services.overview_translation.libretranslate_url'), '/');
        $apiKey = trim((string) config('services.overview_translation.libretranslate_api_key', ''));
        $timeout = max(5, (int) config('services.overview_translation.timeout', 30));
        $chunks = $this->chunkTextForTranslation($englishText, 4000);
        $translatedChunks = [];

        foreach ($chunks as $index => $chunk) {
            if ($index > 0) {
                usleep(300_000);
            }

            $body = [
                'q' => $chunk,
                'source' => 'en',
                'target' => 'fa',
                'format' => 'text',
            ];

            if ($apiKey !== '') {
                $body['api_key'] = $apiKey;
            }

            $response = OutboundHttpClient::configure(
                Http::acceptJson()->timeout($timeout),
            )->post("{$baseUrl}/translate", $body);

            if (!$response->successful()) {
                throw new RuntimeException(
                    'LibreTranslate failed with status ' . $response->status() . '.',
                );
            }

            $translated = trim((string) ($response->json('translatedText') ?? ''));

            if ($translated === '') {
                throw new RuntimeException('LibreTranslate returned an empty translation.');
            }

            $translatedChunks[] = $translated;
        }

        $result = trim(implode("\n\n", $translatedChunks));

        if ($result === '' || !$this->containsPersianScript($result)) {
            return null;
        }

        return $result;
    }

    private function translateViaOpenAiCompatibleApi(string $englishText): ?string
    {
        $apiKey = trim((string) config('services.overview_translation.api_key'));
        $baseUrl = rtrim((string) config('services.overview_translation.base_url'), '/');
        $model = trim((string) config('services.overview_translation.model', 'gpt-4o-mini'));
        $timeout = max(5, (int) config('services.overview_translation.timeout', 30));

        $response = OutboundHttpClient::configure(
            Http::withToken($apiKey)->acceptJson()->timeout($timeout),
        )->post("{$baseUrl}/chat/completions", [
                'model' => $model,
                'temperature' => 0.2,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You translate TV season and episode plot summaries from English into natural Persian (Farsi). '
                            . 'Keep character and place names recognizable. Return only the Persian translation with no quotes, labels, or markdown.',
                    ],
                    [
                        'role' => 'user',
                        'content' => $englishText,
                    ],
                ],
            ]);

        if (!$response->successful()) {
            throw new RuntimeException(
                'Overview translation API failed with status ' . $response->status() . '.',
            );
        }

        $content = trim((string) data_get($response->json(), 'choices.0.message.content', ''));

        if ($content === '' || !$this->containsPersianScript($content)) {
            return null;
        }

        return $content;
    }

    /**
     * @return list<string>
     */
    private function chunkTextForTranslation(string $text, int $maxLength): array
    {
        if (mb_strlen($text) <= $maxLength) {
            return [$text];
        }

        $paragraphs = preg_split("/\n{2,}/u", $text) ?: [$text];
        $chunks = [];
        $buffer = '';

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim((string) $paragraph);

            if ($paragraph === '') {
                continue;
            }

            $candidate = $buffer === '' ? $paragraph : "{$buffer}\n\n{$paragraph}";

            if (mb_strlen($candidate) <= $maxLength) {
                $buffer = $candidate;

                continue;
            }

            if ($buffer !== '') {
                $chunks[] = $buffer;
                $buffer = '';
            }

            if (mb_strlen($paragraph) <= $maxLength) {
                $buffer = $paragraph;

                continue;
            }

            foreach ($this->splitLongParagraph($paragraph, $maxLength) as $part) {
                $chunks[] = $part;
            }
        }

        if ($buffer !== '') {
            $chunks[] = $buffer;
        }

        return $chunks !== [] ? $chunks : [$text];
    }

    /**
     * @return list<string>
     */
    private function splitLongParagraph(string $paragraph, int $maxLength): array
    {
        $parts = [];
        $offset = 0;
        $length = mb_strlen($paragraph);

        while ($offset < $length) {
            $slice = mb_substr($paragraph, $offset, $maxLength);

            if ($offset + $maxLength < $length) {
                $breakAt = max(
                    (int) mb_strrpos($slice, '. '),
                    (int) mb_strrpos($slice, ' '),
                );

                if ($breakAt > (int) ($maxLength * 0.5)) {
                    $slice = mb_substr($slice, 0, $breakAt + 1);
                }
            }

            $parts[] = trim($slice);
            $offset += mb_strlen($slice);
        }

        return array_values(array_filter($parts, fn (string $part) => $part !== ''));
    }

    private function looksLikeMyMemoryError(string $text): bool
    {
        $normalized = strtoupper(trim($text));

        return str_contains($normalized, 'MYMEMORY WARNING')
            || str_contains($normalized, 'QUOTA')
            || str_contains($normalized, 'INVALID LANGUAGE');
    }

    private function containsPersianScript(string $text): bool
    {
        return preg_match('/[\x{0600}-\x{06FF}]/u', $text) === 1;
    }
}
