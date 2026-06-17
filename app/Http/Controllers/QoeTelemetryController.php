<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QoeTelemetryController extends Controller
{
    public function receive(Request $request): JsonResponse
    {
        $raw = $request->getContent();
        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return response()->json(['ok' => false], 400);
        }

        $events = array_is_list($decoded) ? $decoded : [$decoded];

        try {
            $rows = collect($events)
                ->filter(fn ($event) => is_array($event) && (isset($event['sessionId']) || isset($event['session_id'])))
                ->map(fn (array $event) => $this->normalizeEventRow($event, $request))
                ->filter()
                ->values()
                ->all();

            if ($rows !== []) {
                DB::table('playback_events')->insert($rows);
            }
        } catch (\Throwable) {
            // Intentionally silent — never fail playback over analytics.
        }

        return response()->json(['ok' => true]);
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>|null
     */
    private function normalizeEventRow(array $event, Request $request): ?array
    {
        $eventType = $this->normalizeEventType(
            (string) ($event['eventType'] ?? $event['event_type'] ?? $event['name'] ?? ''),
        );

        if ($eventType === null) {
            return null;
        }

        $data = is_array($event['data'] ?? null) ? $event['data'] : [];
        $platform = strtoupper((string) ($event['platform'] ?? 'WEB'));

        if (! in_array($platform, ['WEB', 'MOBILE', 'TV'], true)) {
            $platform = $this->inferPlatform((string) ($event['platformFamily'] ?? $event['platform_family'] ?? 'desktop'));
        }

        $manifestFormat = strtolower((string) ($event['manifestFormat'] ?? $event['manifest_format'] ?? $data['manifestFormat'] ?? 'hls'));

        if (! in_array($manifestFormat, ['hls', 'dash', 'progressive'], true)) {
            $manifestFormat = 'hls';
        }

        $timestamp = $event['timestamp'] ?? $event['client_ts'] ?? null;

        return [
            'session_id' => substr((string) ($event['sessionId'] ?? $event['session_id'] ?? ''), 0, 36),
            'device_id' => substr((string) ($event['deviceId'] ?? $event['device_id'] ?? 'unknown'), 0, 36),
            'user_id' => auth('sanctum')->id(),
            'media_id' => substr((string) ($event['mediaId'] ?? $event['media_id'] ?? 'unknown'), 0, 36),
            'episode_id' => isset($event['episodeId']) || isset($event['episode_id'])
                ? substr((string) ($event['episodeId'] ?? $event['episode_id']), 0, 36)
                : null,
            'platform' => $platform,
            'platform_family' => substr((string) ($event['platformFamily'] ?? $event['platform_family'] ?? 'unknown'), 0, 32),
            'engine_id' => substr((string) ($event['engineId'] ?? $event['engine_id'] ?? 'unknown'), 0, 32),
            'manifest_format' => $manifestFormat,
            'event_type' => $eventType,
            'startup_ms' => $this->intOrNull($event['startupMs'] ?? $event['startup_ms'] ?? $data['startupMs'] ?? null),
            'rebuffer_count' => $this->intOrNull($event['rebufferCount'] ?? $event['rebuffer_count'] ?? $data['count'] ?? null),
            'rebuffer_duration_ms' => $this->intOrNull(
                $event['rebufferDurationMs'] ?? $event['rebuffer_duration_ms'] ?? $data['durationMs'] ?? null,
            ),
            'error_layer' => isset($event['errorLayer']) || isset($event['error_layer']) || isset($data['layer'])
                ? substr((string) ($event['errorLayer'] ?? $event['error_layer'] ?? $data['layer']), 0, 32)
                : null,
            'error_code' => isset($event['errorCode']) || isset($event['error_code']) || isset($data['message'])
                ? substr((string) ($event['errorCode'] ?? $event['error_code'] ?? $data['message']), 0, 64)
                : null,
            'bitrate_kbps' => $this->intOrNull($event['bitrateKbps'] ?? $event['bitrate_kbps'] ?? $data['bitrateKbps'] ?? null),
            'client_ts' => $this->normalizeClientTimestamp($timestamp),
            'created_at' => now()->toDateTimeString(),
        ];
    }

    private function normalizeEventType(string $raw): ?string
    {
        $normalized = str_replace('playback.', '', strtolower(trim($raw)));

        $allowed = [
            'session_start',
            'engine_selected',
            'startup',
            'rebuffer',
            'quality_change',
            'error',
            'ended',
        ];

        return in_array($normalized, $allowed, true) ? $normalized : null;
    }

    private function inferPlatform(string $platformFamily): string
    {
        $family = strtolower($platformFamily);

        if (in_array($family, ['tizen', 'webos', 'firetv', 'androidtv', 'roku'], true)) {
            return 'TV';
        }

        if ($family === 'mobile') {
            return 'MOBILE';
        }

        return 'WEB';
    }

    private function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function normalizeClientTimestamp(mixed $timestamp): string
    {
        if (is_numeric($timestamp)) {
            $seconds = (int) $timestamp;

            if ($seconds > 9_999_999_999) {
                $seconds = (int) floor($seconds / 1000);
            }

            return date('Y-m-d H:i:s', $seconds);
        }

        return now()->toDateTimeString();
    }
}
