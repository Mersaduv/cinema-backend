<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$tmdb = app(App\Services\TmdbService::class);
$movie = $tmdb->fetchMovie(550);
$posterUrl = $movie['poster_url'] ?? null;
$backdropUrl = $movie['backdrop_url'] ?? null;

echo "poster_url: {$posterUrl}\n";
echo "backdrop_url: {$backdropUrl}\n";

if ($posterUrl) {
    $service = app(App\Services\Cinema\ImageIngestionService::class);
    $result = $service->ingest($posterUrl, [
        'entity_type' => 'movie',
        'entity_id' => 99998,
        'image_type' => 'poster',
    ]);
    echo "ingest success: " . ($result['success'] ? 'yes' : 'no') . "\n";
    echo "processed_url: " . ($result['processed_url'] ?? 'null') . "\n";
    if (! $result['success']) {
        echo "error: " . ($result['error'] ?? '') . "\n";
    }
}
