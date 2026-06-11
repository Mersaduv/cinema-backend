<?php

/**
 * Live smoke test using Sanctum token (no password needed).
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$baseUrl = rtrim((string) env('APP_URL', 'http://localhost:8005'), '/');

$admin = App\Models\User::query()
    ->whereHas('roles', fn ($q) => $q->where('slug', 'admin'))
    ->first();

if (! $admin) {
    fwrite(STDERR, "No admin user found.\n");
    exit(1);
}

$token = $admin->createToken('live-movie-form-test')->plainTextToken;
$slug = 'live-image-test-' . time();
$tmdbMovie = app(App\Services\TmdbService::class)->fetchMovie(550);
$posterUrl = $tmdbMovie['poster_url'] ?? null;
$backdropUrl = $tmdbMovie['backdrop_url'] ?? null;

if (! $posterUrl || ! $backdropUrl) {
    fwrite(STDERR, "Could not fetch TMDB image URLs.\n");
    exit(1);
}

$request = fn (string $method, string $path, ?array $json = null) => Illuminate\Support\Facades\Http::withHeaders([
    'Accept' => 'application/json',
    'Authorization' => 'Bearer ' . $token,
])->timeout(120);

echo "== Step 1: Create movie ==\n";
$create = $request('POST', '')->post("{$baseUrl}/api/admin/movies", [
    'title' => 'Live Image Test',
    'original_title' => 'Live Image Test',
    'publish_status' => 'draft',
    'start_processing' => false,
]);

if (! $create->successful()) {
    fwrite(STDERR, "Create failed: {$create->status()}\n{$create->body()}\n");
    exit(1);
}

$movieId = $create->json('data.movie.id');
echo "Movie ID: {$movieId}\n";

echo "== Step 2: Publication form ==\n";
$publication = $request('PUT', '')->put("{$baseUrl}/api/admin/movies/{$movieId}/publication-form", [
    'title' => 'Live Image Test',
    'slug' => $slug,
    'poster_url' => $posterUrl,
    'backdrop_url' => $backdropUrl,
    'banner_url' => $backdropUrl,
    'logo_url' => $posterUrl,
    'synopsis' => 'Live smoke test',
    'release_year' => 1999,
    'tmdb_id' => 550,
    'genres' => ['Drama'],
    'actors' => [
        ['name' => 'Brad Pitt', 'character' => 'Tyler', 'profile_url' => $tmdbMovie['actors'][0]['profile_url'] ?? null],
    ],
]);

if (! $publication->successful()) {
    fwrite(STDERR, "Publication failed: {$publication->status()}\n{$publication->body()}\n");
    exit(1);
}

$movie = $publication->json('data.movie');
$posterUrl = data_get($movie, 'metadata.tmdb.poster_url');
$posterSource = data_get($movie, 'metadata.tmdb.poster_source_url');

echo "poster_url: {$posterUrl}\n";
echo "poster_source_url: {$posterSource}\n";
echo "poster_image: " . (data_get($movie, 'poster_image.original') ? 'yes' : 'no') . "\n";
echo "images.poster: " . (data_get($movie, 'metadata.images.poster.original') ? 'yes' : 'no') . "\n";

$errors = [];
if (! str_starts_with((string) $posterUrl, '/images/')) {
    $errors[] = 'poster_url not ingested';
}
if (! str_contains((string) $posterSource, 'image.tmdb.org')) {
    $errors[] = 'poster_source_url missing';
}
if (! data_get($movie, 'metadata.images.poster.original')) {
    $errors[] = 'metadata.images.poster missing';
}

$assets = App\Models\ImageAsset::query()->where('status', 'ready')->count();
echo "image_assets ready: {$assets}\n";

$storageRoot = config('cinema_images.storage_root');
echo "storage_root: {$storageRoot}\n";
if (is_dir($storageRoot)) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($storageRoot . DIRECTORY_SEPARATOR . 'images', FilesystemIterator::SKIP_DOTS));
    $count = iterator_count($files);
    echo "physical image files: {$count}\n";
} else {
    $errors[] = "storage root missing: {$storageRoot}";
}

echo "== Cleanup ==\n";
$request('DELETE', '')->delete("{$baseUrl}/api/admin/movies/{$movieId}");
$admin->tokens()->where('name', 'live-movie-form-test')->delete();

if ($errors !== []) {
    fwrite(STDERR, "FAILED:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

echo "\nLIVE MOVIE FORM TEST PASSED\n";
