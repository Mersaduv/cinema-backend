<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('persons', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('tmdb_person_id')->nullable()->unique();
            $table->string('imdb_id', 20)->nullable();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('biography')->nullable();
            $table->date('birthday')->nullable();
            $table->date('deathday')->nullable();
            $table->string('place_of_birth', 255)->nullable();
            $table->string('known_for_department', 100)->nullable()->index();
            $table->unsignedTinyInteger('gender')->nullable();
            $table->decimal('popularity', 8, 3)->nullable();
            $table->string('profile_path_tmdb', 500)->nullable();
            $table->string('profile_url', 1000)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('persons');
    }
};
