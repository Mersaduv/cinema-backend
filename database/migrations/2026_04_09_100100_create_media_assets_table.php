<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_assets', function (Blueprint $table) {
            $table->id();
            $table->morphs('mediable');
            $table->string('kind')->default('video');
            $table->string('source_path');
            $table->boolean('source_is_external')->default(true);
            $table->string('managed_relative_directory');
            $table->string('hls_output_relative_directory');
            $table->string('master_playlist_relative_path');
            $table->string('processing_status')->default('pending');
            $table->text('processing_error')->nullable();
            $table->timestamp('last_processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_assets');
    }
};
