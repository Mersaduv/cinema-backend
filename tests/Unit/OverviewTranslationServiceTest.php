<?php

namespace Tests\Unit;

use App\Services\OverviewTranslationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OverviewTranslationServiceTest extends TestCase
{
    public function test_translate_returns_null_when_disabled(): void
    {
        config()->set('services.overview_translation.enabled', false);

        $result = app(OverviewTranslationService::class)->translateEnglishOverviewToPersian(
            'English season overview.',
        );

        $this->assertNull($result);
    }

    public function test_translate_uses_mymemory_by_default_and_cache(): void
    {
        config()->set('services.overview_translation.enabled', true);
        config()->set('services.overview_translation.driver', 'auto');
        Cache::flush();

        Http::fake([
            'api.mymemory.translated.net/*' => Http::response([
                'responseData' => [
                    'translatedText' => 'خلاصهٔ فصل به فارسی.',
                ],
            ], 200),
        ]);

        $service = app(OverviewTranslationService::class);

        $first = $service->translateEnglishOverviewToPersian('English season overview.');
        $second = $service->translateEnglishOverviewToPersian('English season overview.');

        $this->assertSame('خلاصهٔ فصل به فارسی.', $first);
        $this->assertSame('خلاصهٔ فصل به فارسی.', $second);
        Http::assertSentCount(1);
    }

    public function test_translate_chunks_long_text_for_mymemory_limit(): void
    {
        config()->set('services.overview_translation.enabled', true);
        config()->set('services.overview_translation.driver', 'mymemory');
        Cache::flush();

        $longEnglish = str_repeat('A long overview sentence about the plot. ', 20);

        Http::fake([
            'api.mymemory.translated.net/*' => Http::response([
                'responseData' => [
                    'translatedText' => 'بخش اول خلاصه.',
                ],
            ], 200),
        ]);

        $service = app(OverviewTranslationService::class);
        $result = $service->translateEnglishOverviewToPersian($longEnglish);

        $this->assertNotNull($result);
        $this->assertStringContainsString('بخش اول خلاصه.', $result);
        $this->assertGreaterThan(1, count(Http::recorded()));
    }

    public function test_translate_uses_openai_when_driver_is_openai(): void
    {
        config()->set('services.overview_translation.enabled', true);
        config()->set('services.overview_translation.driver', 'openai');
        config()->set('services.overview_translation.api_key', 'secret');
        config()->set('services.overview_translation.base_url', 'https://api.openai.com/v1');
        config()->set('services.overview_translation.model', 'gpt-4o-mini');
        Cache::flush();

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'خلاصهٔ فصل به فارسی.',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $service = app(OverviewTranslationService::class);

        $this->assertSame(
            'خلاصهٔ فصل به فارسی.',
            $service->translateEnglishOverviewToPersian('English season overview.'),
        );
    }
}
