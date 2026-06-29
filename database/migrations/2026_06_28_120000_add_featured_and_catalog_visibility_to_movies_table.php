<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movies', function (Blueprint $table) {
            if (! Schema::hasColumn('movies', 'is_featured')) {
                $table->boolean('is_featured')->default(false)->after('provider_name');
            }

            if (! Schema::hasColumn('movies', 'catalog_visibility')) {
                $table->string('catalog_visibility', 50)
                    ->default('catalog_search')
                    ->after('is_featured');
            }
        });
    }

    public function down(): void
    {
        Schema::table('movies', function (Blueprint $table) {
            if (Schema::hasColumn('movies', 'catalog_visibility')) {
                $table->dropColumn('catalog_visibility');
            }

            if (Schema::hasColumn('movies', 'is_featured')) {
                $table->dropColumn('is_featured');
            }
        });
    }
};
