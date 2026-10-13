<?php

namespace Tests\Unit;

use App\Services\OverviewTranslationService;
use App\Support\CinemaSynopsisHelper;
use Mockery;
use Tests\TestCase;

class CinemaSynopsisHelperTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_normalize_stored_moves_english_out_of_synopsis_column(): void
    {
        $helper = new CinemaSynopsisHelper(Mockery::mock(OverviewTranslationService::class));

        $result = $helper->normalizeStored('English-only season overview.', null);

        $this->assertNull($result['synopsis']);
        $this->assertSame('English-only season overview.', $result['synopsis_en']);
    }

    public function test_localize_preserves_admin_primary_text_when_translation_unavailable(): void
    {
        $translation = Mockery::mock(OverviewTranslationService::class);
        $translation->shouldReceive('translateEnglishOverviewToPersian')->never();

        $helper = new CinemaSynopsisHelper($translation);

        $result = $helper->localizeContentSynopsis(
            'A crew follows a signal through a failing city.',
            null,
            autoTranslate: false,
        );

        $this->assertSame('A crew follows a signal through a failing city.', $result['synopsis']);
        $this->assertSame('A crew follows a signal through a failing city.', $result['synopsis_en']);
    }

    public function test_localize_preserves_persian_admin_text(): void
    {
        $helper = new CinemaSynopsisHelper(Mockery::mock(OverviewTranslationService::class));

        $result = $helper->localizeContentSynopsis('خلاصهٔ فارسی سریال تست', null);

        $this->assertSame('خلاصهٔ فارسی سریال تست', $result['synopsis']);
        $this->assertNull($result['synopsis_en']);
    }

    public function test_localize_season_or_episode_translates_when_persian_missing(): void
    {
        $translation = Mockery::mock(OverviewTranslationService::class);
        $translation
            ->shouldReceive('translateEnglishOverviewToPersian')
            ->once()
            ->with('English episode overview.')
            ->andReturn('خلاصهٔ قسمت به فارسی.');

        $helper = new CinemaSynopsisHelper($translation);

        $result = $helper->localizeSeasonOrEpisode(null, 'English episode overview.');

        $this->assertSame('خلاصهٔ قسمت به فارسی.', $result['synopsis']);
        $this->assertSame('English episode overview.', $result['synopsis_en']);
    }
}
