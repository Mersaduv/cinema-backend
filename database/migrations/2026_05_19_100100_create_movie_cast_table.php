<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movie_cast', function (Blueprint $table) {
            $table->id();
            $table->foreignId('movie_id')->constrained('movies')->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('persons')->cascadeOnDelete();
            $table->string('tmdb_credit_id', 100)->nullable();
            $table->string('character_name', 500)->nullable();
            $table->integer('cast_order')->default(0);
            $table->timestamps();

            $table->index('movie_id');
            $table->index('person_id');
            $table->unique(['movie_id', 'person_id', 'tmdb_credit_id'], 'movie_cast_movie_person_credit_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movie_cast');
    }
};
