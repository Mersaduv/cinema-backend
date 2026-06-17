<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tv_activation_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 12)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('personal_access_token_id')->nullable();
            $table->string('device_label')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();

            $table->index(['code', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tv_activation_codes');
    }
};
