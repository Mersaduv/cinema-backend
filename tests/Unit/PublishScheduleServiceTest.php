<?php

namespace Tests\Unit;

use App\Models\Series;
use App\Services\Cinema\PublishScheduleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublishScheduleServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.timezone' => 'Asia/Kabul']);
    }

    public function test_past_scheduled_time_resolves_to_published_immediately(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-31 15:00:00', 'Asia/Kabul'));

        $service = app(PublishScheduleService::class);
        $resolved = $service->resolvePublishState('scheduled', '2026-05-31T10:00');

        $this->assertSame('published', $resolved['publish_status']);
        $this->assertNotNull($resolved['published_at']);
        $this->assertSame('2026-05-31 10:00:00', $resolved['scheduled_for']?->format('Y-m-d H:i:s'));
    }

    public function test_future_scheduled_time_stays_scheduled(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-31 15:00:00', 'Asia/Kabul'));

        $service = app(PublishScheduleService::class);
        $resolved = $service->resolvePublishState('scheduled', '2026-05-31T18:00');

        $this->assertSame('scheduled', $resolved['publish_status']);
        $this->assertNull($resolved['published_at']);
    }

    public function test_publish_due_content_publishes_overdue_series(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-31 15:00:00', 'Asia/Kabul'));

        Series::create([
            'title' => 'Overdue Series',
            'slug' => 'overdue-series',
            'publish_status' => 'scheduled',
            'scheduled_for' => Carbon::parse('2026-05-31 10:00:00', 'Asia/Kabul'),
        ]);

        Series::create([
            'title' => 'Future Series',
            'slug' => 'future-series',
            'publish_status' => 'scheduled',
            'scheduled_for' => Carbon::parse('2026-05-31 20:00:00', 'Asia/Kabul'),
        ]);

        $counts = app(PublishScheduleService::class)->publishDueContent();

        $this->assertSame(1, $counts['series']);
        $this->assertSame('published', Series::where('slug', 'overdue-series')->value('publish_status'));
        $this->assertSame('scheduled', Series::where('slug', 'future-series')->value('publish_status'));

        Carbon::setTestNow();
    }
}
