<?php

namespace App\Services;

use App\Support\OutboundHttpClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class OverviewTranslationService
{
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36';

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

        $cacheKey = 'overview_fa_v3:' . hash('sha256', $englishText);
        $ttlDays = max(1, (int) config('services.overview_translation.cache_ttl_days', 90));

        try {
            if (Cache::has($cacheKey)) {
                $cached = Cache::get($cacheKey);

                if (is_string($cached) && trim($cached) !== '' && $this->containsPersianScript($cached)) {
                    return $cached;
                }

                Cache::forget($cacheKey);
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
        $errors = [];

        foreach ($this->availableDrivers() as $driver) {
            try {
                $translated = $this->translateViaDriver($driver, $englishText);

                if ($translated !== null && $translated !== '') {
                    Log::info('overview_translation.succeeded', [
                        'driver' => $driver,
                        'chars' => mb_strlen($englishText),
                    ]);

                    return $translated;
                }

                $errors[] = "{$driver}: empty";
            } catch (\Throwable $exception) {
                $errors[] = "{$driver}: {$exception->getMessage()}";

                Log::warning('overview_translation.driver_failed', [
                    'driver' => $driver,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        Log::warning('overview_translation.all_drivers_failed', [
            'errors' => $errors,
            'chars' => mb_strlen($englishText),
        ]);

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

        // Order: fastest/most reliable on this host first; fail over instantly.
        foreach (['google', 'bing', 'mymemory', 'libretranslate', 'openai'] as $driver) {
            if ($this->driverIsUsable($driver)) {
                $drivers[] = $driver;
            }
        }

        return $drivers;
    }

    private function driverIsUsable(string $driver): bool
    {
        return match ($driver) {
            'mymemory', 'google', 'bing' => true,
            'libretranslate' => trim((string) config('services.overview_translation.libretranslate_url')) !== '',
            'openai' => trim((string) config('services.overview_translation.api_key')) !== '',
            default => false,
        };
    }

    private function translateViaDriver(string $driver, string $englishText): ?string
    {
        return match ($driver) {
            'google' => $this->translateViaGoogle($englishText),
            'bing' => $this->translateViaBing($englishText),
            'mymemory' => $this->translateViaMyMemory($englishText),
            'libretranslate' => $this->translateViaLibreTranslate($englishText),
            'openai' => $this->translateViaOpenAiCompatibleApi($englishText),
            default => throw new RuntimeException("Unsupported overview translation driver [{$driver}]."),
        };
    }

    private function translateViaMyMemory(string $englishText): ?string
    {
        $timeout = max(5, (int) config('services.overview_translation.timeout', 30));
        $email = $this->resolveMyMemoryEmail();
        // MyMemory free API rejects queries longer than 500 characters.
        $chunks = $this->chunkTextForTranslation($englishText, 450);
        $translatedChunks = [];

        foreach ($chunks as $index => $chunk) {
            if ($index > 0) {
                usleep(150_000);
            }

            $query = [
                'q' => $chunk,
                'langpair' => 'en|fa',
            ];

            if ($email !== '') {
                $query['de'] = $email;
            }

            $response = $this->http()->timeout($timeout)->get(
                'https://api.mymemory.translated.net/get',
                $query,
            );

            // Fail fast on rate-limit so the next driver can run immediately.
            if ($response->status() === 429) {
                throw new RuntimeException('MyMemory translation failed with status 429.');
            }

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

        return $this->finalizePersianTranslation(implode("\n\n", $translatedChunks));
    }

    private function translateViaGoogle(string $englishText): ?string
    {
        $endpointErrors = [];

        foreach ($this->googleEndpointAttempts($englishText) as $attempt) {
            try {
                $translated = $attempt();

                if ($translated !== null && $translated !== '') {
                    return $translated;
                }

                $endpointErrors[] = 'empty';
            } catch (\Throwable $exception) {
                $endpointErrors[] = $exception->getMessage();
            }
        }

        throw new RuntimeException(
            'Google translation failed on all endpoints: ' . implode(' | ', $endpointErrors),
        );
    }

    /**
     * @return list<\Closure(): ?string>
     */
    private function googleEndpointAttempts(string $englishText): array
    {
        $timeout = max(5, (int) config('services.overview_translation.timeout', 30));

        return [
            // Fastest reliable endpoint observed on this host.
            fn () => $this->translateViaGoogleElement($englishText, $timeout),
            // Chrome dictionary endpoint — also works when gtx is blocked.
            fn () => $this->translateViaGoogleDictChrome($englishText, $timeout),
            // Legacy gtx (often 429 from datacenter IPs).
            fn () => $this->translateViaGoogleGtx($englishText, $timeout),
        ];
    }

    private function translateViaGoogleElement(string $englishText, int $timeout): ?string
    {
        $chunks = $this->chunkTextForTranslation($englishText, 1800);
        $translatedChunks = [];

        foreach ($chunks as $index => $chunk) {
            if ($index > 0) {
                usleep(120_000);
            }

            $response = $this->http()
                ->asForm()
                ->timeout($timeout)
                ->post('https://translate.google.com/translate_a/single', [
                    'client' => 'element',
                    'sl' => 'en',
                    'tl' => 'fa',
                    'dt' => 't',
                    'q' => $chunk,
                ]);

            if (!$response->successful()) {
                throw new RuntimeException(
                    'Google element failed with status ' . $response->status() . '.',
                );
            }

            $translated = $this->parseGoogleSinglePayload($response->json());

            if ($translated === '') {
                throw new RuntimeException('Google element returned an empty result.');
            }

            $translatedChunks[] = $translated;
        }

        return $this->finalizePersianTranslation(implode("\n\n", $translatedChunks));
    }

    private function translateViaGoogleDictChrome(string $englishText, int $timeout): ?string
    {
        $chunks = $this->chunkTextForTranslation($englishText, 1800);
        $translatedChunks = [];

        foreach ($chunks as $index => $chunk) {
            if ($index > 0) {
                usleep(120_000);
            }

            $response = $this->http()->timeout($timeout)->get(
                'https://clients5.google.com/translate_a/t',
                [
                    'client' => 'dict-chrome-ex',
                    'sl' => 'en',
                    'tl' => 'fa',
                    'q' => $chunk,
                ],
            );

            if (!$response->successful()) {
                throw new RuntimeException(
                    'Google dict-chrome failed with status ' . $response->status() . '.',
                );
            }

            $payload = $response->json();
            $translated = '';

            if (is_array($payload) && isset($payload[0]) && is_string($payload[0])) {
                $translated = trim($payload[0]);
            } elseif (is_array($payload) && isset($payload[0]) && is_array($payload[0])) {
                // Some responses nest sentences: [["fa1","fa2"], "en"]
                $parts = [];
                foreach ($payload[0] as $row) {
                    if (is_string($row)) {
                        $parts[] = $row;
                    }
                }
                $translated = trim(implode('', $parts));
            }

            if ($translated === '') {
                throw new RuntimeException('Google dict-chrome returned an empty result.');
            }

            $translatedChunks[] = $translated;
        }

        return $this->finalizePersianTranslation(implode("\n\n", $translatedChunks));
    }

    private function translateViaGoogleGtx(string $englishText, int $timeout): ?string
    {
        $chunks = $this->chunkTextForTranslation($englishText, 1800);
        $translatedChunks = [];

        foreach ($chunks as $index => $chunk) {
            if ($index > 0) {
                usleep(120_000);
            }

            $response = $this->http()->timeout($timeout)->get(
                'https://translate.googleapis.com/translate_a/single',
                [
                    'client' => 'gtx',
                    'sl' => 'en',
                    'tl' => 'fa',
                    'dt' => 't',
                    'q' => $chunk,
                ],
            );

            if (!$response->successful()) {
                throw new RuntimeException(
                    'Google gtx failed with status ' . $response->status() . '.',
                );
            }

            $translated = $this->parseGoogleSinglePayload($response->json());

            if ($translated === '') {
                throw new RuntimeException('Google gtx returned an empty result.');
            }

            $translatedChunks[] = $translated;
        }

        return $this->finalizePersianTranslation(implode("\n\n", $translatedChunks));
    }

    private function parseGoogleSinglePayload(mixed $payload): string
    {
        $parts = [];

        if (is_array($payload) && isset($payload[0]) && is_array($payload[0])) {
            foreach ($payload[0] as $row) {
                if (is_array($row) && isset($row[0]) && is_string($row[0])) {
                    $parts[] = $row[0];
                }
            }
        }

        return trim(implode('', $parts));
    }

    private function translateViaBing(string $englishText): ?string
    {
        $timeout = max(5, (int) config('services.overview_translation.timeout', 30));
        $auth = $this->fetchBingTranslatorAuth($timeout);

        $chunks = $this->chunkTextForTranslation($englishText, 1800);
        $translatedChunks = [];

        foreach ($chunks as $index => $chunk) {
            if ($index > 0) {
                usleep(150_000);
            }

            $query = http_build_query([
                'isVertical' => '1',
                'IG' => $auth['ig'],
                'IID' => $auth['iid'],
            ]);

            $response = $this->http()
                ->withHeaders([
                    'Referer' => 'https://www.bing.com/translator',
                    'Origin' => 'https://www.bing.com',
                ])
                ->asForm()
                ->timeout($timeout)
                ->post("https://www.bing.com/ttranslatev3?{$query}", [
                    'fromLang' => 'en',
                    'to' => 'fa',
                    'text' => $chunk,
                    'token' => $auth['token'],
                    'key' => $auth['key'],
                ]);

            if (!$response->successful()) {
                throw new RuntimeException(
                    'Bing translation failed with status ' . $response->status() . '.',
                );
            }

            $translated = trim((string) data_get($response->json(), '0.translations.0.text', ''));

            if ($translated === '') {
                throw new RuntimeException('Bing translation returned an empty result.');
            }

            $translatedChunks[] = $translated;
        }

        return $this->finalizePersianTranslation(implode("\n\n", $translatedChunks));
    }

    /**
     * @return array{ig: string, iid: string, token: string, key: string}
     */
    private function fetchBingTranslatorAuth(int $timeout): array
    {
        $response = $this->http()->timeout($timeout)->get('https://www.bing.com/translator');

        if (!$response->successful()) {
            throw new RuntimeException(
                'Bing translator page failed with status ' . $response->status() . '.',
            );
        }

        $html = $response->body();

        if (
            !preg_match('/IG:"([^"]+)"/', $html, $ig)
            || !preg_match('/params_AbusePreventionHelper\s*=\s*\[(\d+),"([^"]+)",(\d+)\]/', $html, $abuse)
        ) {
            throw new RuntimeException('Bing translator auth tokens were not found.');
        }

        preg_match('/data-iid="([^"]+)"/', $html, $iid);

        return [
            'ig' => $ig[1],
            'iid' => $iid[1] ?? 'translator.5028',
            'key' => $abuse[1],
            'token' => $abuse[2],
        ];
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
                usleep(200_000);
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

            $response = $this->http()->timeout($timeout)->post("{$baseUrl}/translate", $body);

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

        return $this->finalizePersianTranslation(implode("\n\n", $translatedChunks));
    }

    private function translateViaOpenAiCompatibleApi(string $englishText): ?string
    {
        $apiKey = trim((string) config('services.overview_translation.api_key'));
        $baseUrl = rtrim((string) config('services.overview_translation.base_url'), '/');
        $model = trim((string) config('services.overview_translation.model', 'gpt-4o-mini'));
        $timeout = max(5, (int) config('services.overview_translation.timeout', 30));

        $response = OutboundHttpClient::configure(
            Http::withToken($apiKey)
                ->withHeaders(['User-Agent' => self::USER_AGENT])
                ->acceptJson()
                ->timeout($timeout),
        )->post("{$baseUrl}/chat/completions", [
            'model' => $model,
            'temperature' => 0.2,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You translate movie and TV plot summaries from English into natural Persian (Farsi). '
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

        return $this->finalizePersianTranslation($content);
    }

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        return OutboundHttpClient::configure(
            Http::withHeaders(['User-Agent' => self::USER_AGENT])->acceptJson(),
        );
    }

    private function resolveMyMemoryEmail(): string
    {
        $configured = trim((string) config('services.overview_translation.mymemory_email', ''));

        if ($configured !== '') {
            return $configured;
        }

        // Higher free quota when an email is supplied.
        return 'cinema-overview@' . (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost');
    }

    private function finalizePersianTranslation(string $text): ?string
    {
        $result = trim($text);

        if ($result === '' || !$this->containsPersianScript($result)) {
            return null;
        }

        return $result;
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
            || str_contains($normalized, 'INVALID LANGUAGE')
            || str_contains($normalized, 'QUERY LENGTH LIMIT');
    }

    private function containsPersianScript(string $text): bool
    {
        return preg_match('/[\x{0600}-\x{06FF}]/u', $text) === 1;
    }
}
