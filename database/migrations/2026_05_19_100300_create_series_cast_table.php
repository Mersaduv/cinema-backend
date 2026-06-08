<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('series_cast', function (Blueprint $table) {
            $table->id();
            $table->foreignId('series_id')->constrained('series')->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('persons')->cascadeOnDelete();
            $table->string('tmdb_credit_id', 100)->nullable();
            $table->string('character_name', 500)->nullable();
            $table->unsignedInteger('episode_count')->nullable();
            $table->integer('cast_order')->default(0);
            $table->timestamps();

            $table->index('series_id');
            $table->index('person_id');
            $table->unique(['series_id', 'person_id', 'tmdb_credit_id'], 'series_cast_series_person_credit_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('series_cast');
    }
};
