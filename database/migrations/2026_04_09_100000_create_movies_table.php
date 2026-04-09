<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movies', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('original_title')->nullable();
            $table->text('synopsis')->nullable();
            $table->unsignedSmallInteger('release_year')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('provider_name')->nullable();
            $table->string('poster_path')->nullable();
            $table->string('media_folder');
            $table->string('publish_status')->default('draft');
            $table->string('processing_status')->default('pending');
            $table->text('processing_error')->nullable();
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movies');
    }
};
