<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_pipeline_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pipeline_id')->constrained('content_pipelines')->cascadeOnDelete();
            $table->string('task_key', 64);
            $table->string('task_label', 128);
            $table->tinyInteger('priority')->default(5);
            $table->boolean('is_critical')->default(true);
            $table->enum('status', ['pending', 'running', 'completed', 'failed', 'skipped'])->default('pending');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('laravel_job_id', 128)->nullable();
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->text('error_message')->nullable();
            $table->json('result_summary')->nullable();
            $table->timestamps();

            $table->unique(['pipeline_id', 'task_key']);
            $table->index('pipeline_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_pipeline_tasks');
    }
};
