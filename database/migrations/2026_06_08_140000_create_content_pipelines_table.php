<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_pipelines', function (Blueprint $table) {
            $table->id();
            $table->enum('entity_type', ['movie', 'series']);
            $table->unsignedBigInteger('entity_id');
            $table->enum('status', ['pending', 'running', 'completed', 'failed', 'cancelled'])->default('pending');
            $table->unsignedBigInteger('triggered_by')->nullable();
            $table->timestamp('triggered_at')->useCurrent();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->unsignedTinyInteger('total_tasks')->default(0);
            $table->unsignedTinyInteger('completed_tasks')->default(0);
            $table->unsignedTinyInteger('failed_tasks')->default(0);
            $table->text('error_summary')->nullable();
            $table->timestamps();

            $table->index(['entity_type', 'entity_id']);
            $table->index(['entity_type', 'entity_id', 'status']);
            $table->index(['status', 'triggered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_pipelines');
    }
};
