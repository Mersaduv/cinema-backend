<?php

/**
 * Live smoke test against running API (php artisan serve --port=8005).
 * Usage: php scripts/test-movie-form-live.php [admin@email] [password]
 */

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$baseUrl = rtrim((string) env('APP_URL', 'http://localhost:8005'), '/');
$email = $argv[1] ?? env('LIVE_TEST_ADMIN_EMAIL');
$password = $argv[2] ?? env('LIVE_TEST_ADMIN_PASSWORD');

if (! $email || ! $password) {
    fwrite(STDERR, "Usage: php scripts/test-movie-form-live.php admin@example.com password\n");
    exit(1);
}

function api(string $method, string $path, ?array $json = null, ?string $token = null): array
{
    global $baseUrl;

    $request = Http::withHeaders(array_filter([
        'Accept' => 'application/json',
        'Authorization' => $token ? 'Bearer ' . $token : null,
    ]))->timeout(120);

    $url = $baseUrl . $path;
    $response = match (strtoupper($method)) {
        'GET' => $request->get($url),
        'POST' => $request->post($url, $json ?? []),
        'PUT' => $request->put($url, $json ?? []),
        'DELETE' => $request->delete($url),
        default => throw new InvalidArgumentException('Unsupported method'),
    };

    return [
        'status' => $response->status(),
        'json' => $response->json(),
        'body' => $response->body(),
    ];
}

echo "== Login ==\n";
$login = api('POST', '/api/login', ['email' => $email, 'password' => $password]);
if ($login['status'] !== 200 || empty($login['json']['data']['token'])) {
    fwrite(STDERR, "Login failed: HTTP {$login['status']}\n{$login['body']}\n");
    exit(1);
}
$token = $login['json']['data']['token'];
echo "OK\n";

$slug = 'live-image-test-' . time();

echo "== Step 1: Create movie shell ==\n";
$create = api('POST', '/api/admin/movies', [
    'title' => 'Live Image Test',
    'original_title' => 'Live Image Test',
    'publish_status' => 'draft',
    'start_processing' => false,
], $token);

if ($create['status'] !== 201) {
    fwrite(STDERR, "Create failed: HTTP {$create['status']}\n{$create['body']}\n");
    exit(1);
}

$movieId = $create['json']['data']['movie']['id'] ?? null;
echo "Movie ID: {$movieId}\n";

echo "== Step 2: Publication form with TMDB images ==\n";
$publication = api('PUT', "/api/admin/movies/{$movieId}/publication-form", [
    'title' => 'Live Image Test',
    'slug' => $slug,
    'poster_url' => 'https://image.tmdb.org/t/p/w780/wwemzKWzjKYJFfCeiB57N3kcsF3.jpg',
    'backdrop_url' => 'https://image.tmdb.org/t/p/original/9BBTo63ANSmhC4e6r62OJluK2k.jpg',
    'synopsis' => 'Live smoke test',
    'release_year' => 2024,
    'tmdb_id' => 550,
    'genres' => ['Drama'],
], $token);

if ($publication['status'] !== 200) {
    fwrite(STDERR, "Publication failed: HTTP {$publication['status']}\n{$publication['body']}\n");
    exit(1);
}

$movie = $publication['json']['data']['movie'] ?? [];
$posterUrl = $movie['metadata']['tmdb']['poster_url'] ?? '';
$posterSource = $movie['metadata']['tmdb']['poster_source_url'] ?? '';
$hasImageObject = ! empty($movie['metadata']['images']['poster']['original']);

echo "poster_url: {$posterUrl}\n";
echo "poster_source_url: {$posterSource}\n";
echo "has poster image object: " . ($hasImageObject ? 'yes' : 'no') . "\n";

if (! str_starts_with((string) $posterUrl, '/images/') && ! str_starts_with((string) $posterUrl, 'http')) {
    fwrite(STDERR, "ERROR: poster_url was not ingested.\n");
    exit(1);
}

if (! str_contains((string) $posterSource, 'image.tmdb.org')) {
    fwrite(STDERR, "ERROR: poster_source_url missing.\n");
    exit(1);
}

echo "== Step 3: Show movie ==\n";
$show = api('GET', "/api/admin/movies/{$movieId}", null, $token);
if ($show['status'] !== 200) {
    fwrite(STDERR, "Show failed: HTTP {$show['status']}\n");
    exit(1);
}

$showPoster = $show['json']['data']['movie']['poster_url'] ?? '';
echo "show poster_url: {$showPoster}\n";

$assetCount = \App\Models\ImageAsset::query()->where('status', 'ready')->count();
echo "image_assets ready count: {$assetCount}\n";

echo "== Cleanup: delete test movie ==\n";
$delete = api('DELETE', "/api/admin/movies/{$movieId}", null, $token);
echo "delete HTTP {$delete['status']}\n";

echo "\nLIVE MOVIE FORM TEST PASSED\n";
