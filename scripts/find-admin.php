<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$user = App\Models\User::query()
    ->whereHas('roles', fn ($q) => $q->where('slug', 'admin'))
    ->first();

if (! $user) {
    echo "no-admin\n";
    exit(1);
}

echo $user->email . "\n";
