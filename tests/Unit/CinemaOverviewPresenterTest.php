<?php

namespace Tests\Unit;

use App\Support\CinemaOverviewPresenter;
use PHPUnit\Framework\TestCase;

class CinemaOverviewPresenterTest extends TestCase
{
    public function test_display_prefers_persian_over_english(): void
    {
        $this->assertSame(
            'خلاصه فارسی',
            CinemaOverviewPresenter::display('English overview', 'خلاصه فارسی'),
        );
    }

    public function test_display_falls_back_to_english_when_persian_missing(): void
    {
        $this->assertSame(
            'English overview',
            CinemaOverviewPresenter::display('English overview', null),
        );
    }
}
