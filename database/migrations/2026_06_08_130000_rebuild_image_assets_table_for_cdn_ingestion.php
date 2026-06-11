<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the legacy image_assets schema (owner_type/tmdb_path/original_url)
 * with the internal-CDN ingestion schema used by ImageIngestionService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('image_assets');

        Schema::create('image_assets', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('image_type');
            $table->text('source_url')->nullable();
            $table->string('source_hash', 64)->nullable();
            $table->text('original_public_url');
            $table->text('blur_url')->nullable();
            $table->text('src_url')->nullable();
            $table->json('srcset_json')->nullable();
            $table->text('srcset_string')->nullable();
            $table->json('variants_json')->nullable();
            $table->text('physical_storage_path')->nullable();
            $table->text('public_path')->nullable();
            $table->string('folder_type')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('filename')->nullable();
            $table->string('extension', 16)->nullable();
            $table->string('mime_type', 64)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->string('status')->default('ready');
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['entity_type', 'entity_id']);
            $table->index('image_type');
            $table->index('source_hash');
            $table->index('checksum');
            $table->index('folder_type');
            $table->index('year');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('image_assets');

        Schema::create('image_assets', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type')->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->string('entity_type', 32);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->unsignedBigInteger('tmdb_id')->nullable();
            $table->string('tmdb_path', 512)->nullable();
            $table->string('image_type', 64);
            $table->string('logical_path', 512)->nullable();
            $table->string('original_url', 1024);
            $table->string('blur_url', 1024)->nullable();
            $table->json('variants')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('mime_type', 64)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('source', 32)->default('tmdb');
            $table->string('storage_disk', 64)->nullable();
            $table->string('storage_path', 1024)->nullable();
            $table->string('checksum', 64)->nullable();
            $table->unsignedInteger('sort_order')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->json('metadata')->nullable();
            $table->string('fallback_tmdb_url', 1024)->nullable();
            $table->timestamps();

            $table->index(['entity_type', 'entity_id', 'image_type']);
            $table->index(['owner_type', 'owner_id']);
            $table->unique(['source', 'tmdb_path', 'image_type']);
            $table->index('tmdb_id');
        });
    }
};
