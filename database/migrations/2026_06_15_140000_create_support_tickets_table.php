<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('category', 64);
            $table->string('category_label', 120);
            $table->text('description');
            $table->string('image_path')->nullable();
            $table->string('image_url')->nullable();
            $table->string('status', 32)->default('waiting');
            $table->text('admin_reply')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_tickets');
    }
};
