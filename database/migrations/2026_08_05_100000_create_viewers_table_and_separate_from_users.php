<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Original split migration — kept as a no-op so environments that partially
 * applied it do not re-run destructive steps. Real work is in 100001.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Intentionally empty. See 2026_08_05_100001_finish_viewers_table_separation.
    }

    public function down(): void
    {
        //
    }
};
