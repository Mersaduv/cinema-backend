<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movies', function (Blueprint $table) {
            $table->enum('drm_scheme', ['widevine', 'playready', 'fairplay', 'none'])
                ->default('none')
                ->after('has_dubbed_audio');
            $table->string('drm_license_url', 1024)->nullable()->after('drm_scheme');
        });

        Schema::table('series', function (Blueprint $table) {
            $table->enum('drm_scheme', ['widevine', 'playready', 'fairplay', 'none'])
                ->default('none');
            $table->string('drm_license_url', 1024)->nullable()->after('drm_scheme');
        });
    }

    public function down(): void
    {
        Schema::table('movies', function (Blueprint $table) {
            $table->dropColumn(['drm_scheme', 'drm_license_url']);
        });

        Schema::table('series', function (Blueprint $table) {
            $table->dropColumn(['drm_scheme', 'drm_license_url']);
        });
    }
};
