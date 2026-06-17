<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class QoeTelemetryControllerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function receive_accepts_json_array_and_inserts_rows(): void
    {
        $payload = [[
            'sessionId' => 'sess-1234567890123456789012345678901',
            'deviceId' => 'dev-1234567890123456789012345678901',
            'mediaId' => 'movie-1',
            'platformFamily' => 'desktop',
            'engineId' => 'hlsjs',
            'manifestFormat' => 'hls',
            'eventType' => 'session_start',
            'timestamp' => 1_700_000_000_000,
        ]];

        $response = $this->postJson('/api/v1/qoe', $payload);

        $response->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseCount('playback_events', 1);
        $this->assertDatabaseHas('playback_events', [
            'session_id' => 'sess-1234567890123456789012345678901',
            'event_type' => 'session_start',
        ]);
    }

    #[Test]
    public function receive_gracefully_handles_invalid_json(): void
    {
        $response = $this->call(
            'POST',
            '/api/v1/qoe',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            'not-json',
        );

        $response->assertStatus(400)->assertJson(['ok' => false]);
        $this->assertSame(0, DB::table('playback_events')->count());
    }
}
