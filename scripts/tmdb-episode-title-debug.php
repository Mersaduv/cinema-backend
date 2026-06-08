<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$tmdb = app(App\Services\TmdbService::class);
$tmdbId = (int) ($argv[1] ?? 106379);

foreach (['fa-IR', 'en-US'] as $language) {
    echo "\n=== fetchTv {$tmdbId} language={$language} ===\n";
    $series = $tmdb->fetchTv($tmdbId, $language);
    echo 'series title: ' . json_encode($series['title'] ?? null, JSON_UNESCAPED_UNICODE) . "\n";

    foreach (array_slice($series['seasons'] ?? [], 0, 2) as $season) {
        $sn = $season['season_number'] ?? '?';
        echo "  season {$sn} name: " . json_encode($season['name'] ?? null, JSON_UNESCAPED_UNICODE) . "\n";
        foreach (array_slice($season['episodes'] ?? [], 0, 3) as $episode) {
            $en = $episode['episode_number'] ?? '?';
            echo "    ep {$en} title: " . json_encode($episode['title'] ?? null, JSON_UNESCAPED_UNICODE) . "\n";
        }
    }
}

// Raw season API (bypass our merge) for fa-IR
echo "\n=== Raw TMDB season/1 fa-IR (via Http) ===\n";
$key = config('services.tmdb.api_key');
$token = config('services.tmdb.access_token');
$base = rtrim((string) config('services.tmdb.base_url'), '/');
$headers = filled($token) ? ['Authorization' => 'Bearer ' . $token] : [];
$query = filled($token) ? [] : ['api_key' => $key];
$query['language'] = 'fa-IR';
$response = Illuminate\Support\Facades\Http::withHeaders($headers)
    ->get("{$base}/tv/{$tmdbId}/season/1", $query);
$raw = $response->json();
foreach (array_slice($raw['episodes'] ?? [], 0, 3) as $episode) {
    $en = $episode['episode_number'] ?? '?';
    echo "  raw ep {$en} name: " . json_encode($episode['name'] ?? null, JSON_UNESCAPED_UNICODE) . "\n";
}

echo "\n=== Raw TMDB season/1 en-US ===\n";
$query['language'] = 'en-US';
$response = Illuminate\Support\Facades\Http::withHeaders($headers)
    ->get("{$base}/tv/{$tmdbId}/season/1", $query);
$raw = $response->json();
foreach (array_slice($raw['episodes'] ?? [], 0, 3) as $episode) {
    $en = $episode['episode_number'] ?? '?';
    echo "  raw ep {$en} name: " . json_encode($episode['name'] ?? null, JSON_UNESCAPED_UNICODE) . "\n";
}
