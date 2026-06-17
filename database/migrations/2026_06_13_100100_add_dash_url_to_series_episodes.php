<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('series_episodes', function (Blueprint $table) {
            $table->string('dash_url', 2048)->nullable()->after('hls_url');
            $table->boolean('dash_verified')->default(false)->after('dash_url');
        });
    }

    public function down(): void
    {
        Schema::table('series_episodes', function (Blueprint $table) {
            $table->dropColumn(['dash_url', 'dash_verified']);
        });
    }
};
