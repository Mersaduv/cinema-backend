<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$url = 'https://image.tmdb.org/t/p/w780/wwemzKWzjKYJFfCeiB57N3kcsF3.jpg';
$service = app(App\Services\Cinema\ImageIngestionService::class);

echo "storage_root: " . config('cinema_images.storage_root') . "\n";
echo "source_hash column: " . (Illuminate\Support\Facades\Schema::hasColumn('image_assets', 'source_hash') ? 'yes' : 'no') . "\n";

$result = $service->ingest($url, [
    'entity_type' => 'movie',
    'entity_id' => 99999,
    'image_type' => 'poster',
]);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
