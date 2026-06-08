<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movies', function (Blueprint $table) {
            $table->unsignedInteger('tmdb_id')->nullable()->unique()->after('id');
        });

        Schema::table('series', function (Blueprint $table) {
            $table->unsignedInteger('tmdb_id')->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('movies', function (Blueprint $table) {
            $table->dropUnique(['tmdb_id']);
            $table->dropColumn('tmdb_id');
        });

        Schema::table('series', function (Blueprint $table) {
            $table->dropUnique(['tmdb_id']);
            $table->dropColumn('tmdb_id');
        });
    }
};
