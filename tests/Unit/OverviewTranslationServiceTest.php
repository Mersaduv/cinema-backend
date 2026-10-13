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

    public function test_translate_uses_google_first_in_auto_mode_and_cache(): void
    {
        config()->set('services.overview_translation.enabled', true);
        config()->set('services.overview_translation.driver', 'auto');
        Cache::flush();

        Http::fake([
            'translate.google.com/*' => Http::response([
                [['خلاصهٔ فصل به فارسی.', 'English season overview.']],
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

    public function test_translate_falls_back_across_google_endpoints_then_bing(): void
    {
        config()->set('services.overview_translation.enabled', true);
        config()->set('services.overview_translation.driver', 'auto');
        Cache::flush();

        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            $url = $request->url();

            if (str_contains($url, 'translate.google.com')) {
                return Http::response('blocked', 429);
            }

            if (str_contains($url, 'clients5.google.com')) {
                return Http::response('blocked', 429);
            }

            if (str_contains($url, 'translate.googleapis.com')) {
                return Http::response('blocked', 429);
            }

            if (str_contains($url, 'www.bing.com/translator') && $request->method() === 'GET') {
                return Http::response(
                    'IG:"TESTIG123" data-iid="translator.5028" params_AbusePreventionHelper = [1710000000000,"TOKENABC",3600000]',
                    200,
                );
            }

            if (str_contains($url, 'www.bing.com/ttranslatev3')) {
                return Http::response([
                    [
                        'translations' => [
                            ['text' => 'خلاصهٔ فیلم به فارسی.', 'to' => 'fa'],
                        ],
                    ],
                ], 200);
            }

            return Http::response(['unexpected' => true], 500);
        });

        $service = app(OverviewTranslationService::class);

        $this->assertSame(
            'خلاصهٔ فیلم به فارسی.',
            $service->translateEnglishOverviewToPersian('English movie overview.'),
        );
    }

    public function test_translate_falls_back_to_mymemory_when_google_and_bing_fail(): void
    {
        config()->set('services.overview_translation.enabled', true);
        config()->set('services.overview_translation.driver', 'auto');
        Cache::flush();

        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            $url = $request->url();

            if (
                str_contains($url, 'translate.google.com')
                || str_contains($url, 'clients5.google.com')
                || str_contains($url, 'translate.googleapis.com')
                || str_contains($url, 'www.bing.com')
            ) {
                return Http::response('fail', 503);
            }

            if (str_contains($url, 'api.mymemory.translated.net')) {
                return Http::response([
                    'responseData' => [
                        'translatedText' => 'خلاصه از مای‌مموری.',
                    ],
                ], 200);
            }

            return Http::response(['unexpected' => true], 500);
        });

        $service = app(OverviewTranslationService::class);

        $this->assertSame(
            'خلاصه از مای‌مموری.',
            $service->translateEnglishOverviewToPersian('English movie overview.'),
        );
    }
}
