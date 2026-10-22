<?php

namespace Tests\Unit;

use App\Services\Cinema\EpisodeArrival;
use PHPUnit\Framework\TestCase;

class EpisodeArrivalTest extends TestCase
{
    public function test_existing_arrival_stays_put_on_later_edits(): void
    {
        $arrival = new EpisodeArrival();

        $this->assertSame(
            '2026-01-01 10:00:00',
            $arrival->resolve('2026-01-01 10:00:00', true, true, '2026-01-01 10:00:00', '2026-10-22 12:00:00'),
        );
    }

    public function test_first_playable_upload_uses_now(): void
    {
        $arrival = new EpisodeArrival();

        $this->assertSame(
            '2026-10-22 12:00:00',
            $arrival->resolve(null, false, true, '2026-01-01 10:00:00', '2026-10-22 12:00:00'),
        );
    }

    public function test_already_playable_row_without_a_stamp_keeps_created_at(): void
    {
        $arrival = new EpisodeArrival();

        $this->assertSame(
            '2026-01-01 10:00:00',
            $arrival->resolve(null, true, true, '2026-01-01 10:00:00', '2026-10-22 12:00:00'),
        );
    }
}
