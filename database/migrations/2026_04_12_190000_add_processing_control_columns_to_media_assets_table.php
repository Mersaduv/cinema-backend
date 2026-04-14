<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_assets', function (Blueprint $table) {
            $table->boolean('stop_requested')->default(false)->after('selected_hls_profiles');
            $table->unsignedBigInteger('processing_pid')->nullable()->after('stop_requested');
        });
    }

    public function down(): void
    {
        Schema::table('media_assets', function (Blueprint $table) {
            $table->dropColumn(['stop_requested', 'processing_pid']);
        });
    }
};
