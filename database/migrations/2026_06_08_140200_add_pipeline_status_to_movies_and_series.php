<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('movies') && ! Schema::hasColumn('movies', 'pipeline_status')) {
            Schema::table('movies', function (Blueprint $table) {
                $table->enum('pipeline_status', ['idle', 'pending', 'running', 'completed', 'failed'])
                    ->default('idle')
                    ->after('processing_status');
                $table->index('pipeline_status');
            });
        }

        if (Schema::hasTable('series') && ! Schema::hasColumn('series', 'pipeline_status')) {
            Schema::table('series', function (Blueprint $table) {
                $table->enum('pipeline_status', ['idle', 'pending', 'running', 'completed', 'failed'])
                    ->default('idle')
                    ->after('backdrop_url');
                $table->index('pipeline_status');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('movies') && Schema::hasColumn('movies', 'pipeline_status')) {
            Schema::table('movies', function (Blueprint $table) {
                $table->dropIndex(['pipeline_status']);
                $table->dropColumn('pipeline_status');
            });
        }

        if (Schema::hasTable('series') && Schema::hasColumn('series', 'pipeline_status')) {
            Schema::table('series', function (Blueprint $table) {
                $table->dropIndex(['pipeline_status']);
                $table->dropColumn('pipeline_status');
            });
        }
    }
};
