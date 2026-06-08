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
