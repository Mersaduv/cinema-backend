<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(
            "ALTER TABLE movies MODIFY cast_members JSON NULL COMMENT 'LEGACY: prefer movie_cast relation; kept for one release cycle as fallback'"
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE movies MODIFY cast_members JSON NULL');
    }
};
