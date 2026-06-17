<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 200);
            $table->string('genre_slug', 120);
            $table->string('genre_name', 120);
            $table->text('description');
            $table->string('status', 32)->default('pending');
            $table->text('admin_note')->nullable();
            $table->timestamp('moderated_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_requests');
    }
};
