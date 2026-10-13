<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Speed up ImagePresentationService lookups that filter by original_public_url + status.
 * TEXT columns need a prefix index on MySQL/MariaDB.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('image_assets')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        $exists = collect(DB::select("SHOW INDEX FROM image_assets WHERE Key_name = 'image_assets_original_url_status_index'"))
            ->isNotEmpty();

        if ($exists) {
            return;
        }

        DB::statement(
            'CREATE INDEX image_assets_original_url_status_index ON image_assets (original_public_url(255), status)'
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('image_assets')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        $exists = collect(DB::select("SHOW INDEX FROM image_assets WHERE Key_name = 'image_assets_original_url_status_index'"))
            ->isNotEmpty();

        if (! $exists) {
            return;
        }

        DB::statement('DROP INDEX image_assets_original_url_status_index ON image_assets');
    }
};
