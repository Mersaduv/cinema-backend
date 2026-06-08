<?php

namespace Database\Seeders;

use App\Support\CatalogGenreManifest;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

class TmdbReferenceFallbackSeeder extends Seeder
{
    public function run(): void
    {
        CatalogGenreManifest::ensureCatalogGenresExist();
        Cache::forget('filter_options');
    }
}
