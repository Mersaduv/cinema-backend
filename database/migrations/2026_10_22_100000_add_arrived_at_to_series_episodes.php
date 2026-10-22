<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('series_episodes', 'arrived_at')) {
            Schema::table('series_episodes', function (Blueprint $table) {
                $table->timestamp('arrived_at')->nullable()->after('publish_status');
                $table->index(['series_id', 'arrived_at']);
            });
        }

        DB::table('series_episodes')
            ->whereNull('arrived_at')
            ->where(function ($playable): void {
                $playable->where(function ($progressive): void {
                    $progressive->whereIn('source_method', ['url', 'upload', 'video'])
                        ->whereNotNull('video_url')
                        ->where('video_url', '!=', '');
                })->orWhere(function ($embed): void {
                    $embed->where('source_method', 'embed')
                        ->whereNotNull('embed_url')
                        ->where('embed_url', '!=', '');
                })->orWhere(function ($hls): void {
                    $hls->whereNotNull('hls_url')
                        ->where('hls_url', '!=', '');
                });
            })
            ->update(['arrived_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('series_episodes', 'arrived_at')) {
            return;
        }

        Schema::table('series_episodes', function (Blueprint $table) {
            $table->dropIndex(['series_id', 'arrived_at']);
            $table->dropColumn('arrived_at');
        });
    }
};
