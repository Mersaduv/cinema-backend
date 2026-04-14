<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_assets', function (Blueprint $table) {
            $table->decimal('processing_progress_percent', 5, 2)->nullable()->after('processing_status');
            $table->unsignedInteger('processing_eta_seconds')->nullable()->after('processing_progress_percent');
            $table->string('current_profile_key')->nullable()->after('processing_eta_seconds');
            $table->timestamp('processing_started_at')->nullable()->after('current_profile_key');
        });

        Schema::table('media_processing_runs', function (Blueprint $table) {
            $table->decimal('progress_percent', 5, 2)->nullable()->after('status');
            $table->unsignedInteger('eta_seconds')->nullable()->after('progress_percent');
            $table->string('current_profile_key')->nullable()->after('eta_seconds');
            $table->unsignedInteger('source_duration_seconds')->nullable()->after('current_profile_key');
        });
    }

    public function down(): void
    {
        Schema::table('media_processing_runs', function (Blueprint $table) {
            $table->dropColumn([
                'progress_percent',
                'eta_seconds',
                'current_profile_key',
                'source_duration_seconds',
            ]);
        });

        Schema::table('media_assets', function (Blueprint $table) {
            $table->dropColumn([
                'processing_progress_percent',
                'processing_eta_seconds',
                'current_profile_key',
                'processing_started_at',
            ]);
        });
    }
};
