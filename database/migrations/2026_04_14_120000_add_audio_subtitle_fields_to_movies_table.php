<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movies', function (Blueprint $table) {
            $table->boolean('has_dubbed_audio')->default(false)->after('trailer_path');
            $table->string('dubbed_source_path')->nullable()->after('has_dubbed_audio');
            $table->boolean('has_subtitle')->default(false)->after('dubbed_source_path');
            $table->string('subtitle_source_path')->nullable()->after('has_subtitle');
        });
    }

    public function down(): void
    {
        Schema::table('movies', function (Blueprint $table) {
            $table->dropColumn([
                'has_dubbed_audio',
                'dubbed_source_path',
                'has_subtitle',
                'subtitle_source_path',
            ]);
        });
    }
};
