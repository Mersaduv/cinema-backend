<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('playback_events', function (Blueprint $table) {
            $table->id();
            $table->string('session_id', 36);
            $table->string('device_id', 36);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('media_id', 36);
            $table->string('episode_id', 36)->nullable();
            $table->enum('platform', ['WEB', 'MOBILE', 'TV']);
            $table->string('platform_family', 32);
            $table->string('engine_id', 32);
            $table->enum('manifest_format', ['hls', 'dash', 'progressive']);
            $table->enum('event_type', [
                'session_start',
                'engine_selected',
                'startup',
                'rebuffer',
                'quality_change',
                'error',
                'ended',
            ]);
            $table->unsignedInteger('startup_ms')->nullable();
            $table->unsignedSmallInteger('rebuffer_count')->nullable();
            $table->unsignedInteger('rebuffer_duration_ms')->nullable();
            $table->string('error_layer', 32)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->unsignedInteger('bitrate_kbps')->nullable();
            $table->timestamp('client_ts');
            $table->timestamp('created_at')->useCurrent();

            $table->index('session_id', 'idx_session');
            $table->index(['media_id', 'created_at'], 'idx_media');
            $table->index(['engine_id', 'event_type', 'created_at'], 'idx_engine');
            $table->index(['platform_family', 'created_at'], 'idx_platform');
            $table->index(['event_type', 'error_layer', 'created_at'], 'idx_errors');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playback_events');
    }
};
