<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movies', function (Blueprint $table) {
            $table->string('trailer_path')->nullable()->after('poster_path');
            $table->string('age_rating', 50)->nullable()->after('trailer_path');
            $table->json('cast_members')->nullable()->after('age_rating');
        });
    }

    public function down(): void
    {
        Schema::table('movies', function (Blueprint $table) {
            $table->dropColumn(['trailer_path', 'age_rating', 'cast_members']);
        });
    }
};
