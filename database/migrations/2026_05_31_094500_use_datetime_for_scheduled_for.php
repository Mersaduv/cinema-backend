<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        if (Schema::hasTable('series') && Schema::hasColumn('series', 'scheduled_for')) {
            DB::statement('ALTER TABLE series MODIFY scheduled_for DATETIME NULL');
        }

        if (Schema::hasTable('movies') && Schema::hasColumn('movies', 'scheduled_for')) {
            DB::statement('ALTER TABLE movies MODIFY scheduled_for DATETIME NULL');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        if (Schema::hasTable('series') && Schema::hasColumn('series', 'scheduled_for')) {
            DB::statement('ALTER TABLE series MODIFY scheduled_for TIMESTAMP NULL');
        }

        if (Schema::hasTable('movies') && Schema::hasColumn('movies', 'scheduled_for')) {
            DB::statement('ALTER TABLE movies MODIFY scheduled_for TIMESTAMP NULL');
        }
    }
};
