<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('content_requests')) {
            return;
        }

        if (Schema::hasColumn('content_requests', 'viewer_id') || ! Schema::hasColumn('content_requests', 'user_id')) {
            return;
        }

        $this->dropForeignKey('content_requests', 'user_id');

        Schema::table('content_requests', function (Blueprint $table): void {
            $table->renameColumn('user_id', 'viewer_id');
        });

        if (Schema::hasTable('viewers') && Schema::getConnection()->getDriverName() === 'mysql') {
            Schema::table('content_requests', function (Blueprint $table): void {
                $table->foreign('viewer_id')->references('id')->on('viewers')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        // Repair migration. The previous column name is not restored.
    }

    private function dropForeignKey(string $table, string $column): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        $database = Schema::getConnection()->getDatabaseName();
        $foreign = DB::selectOne(
            'SELECT CONSTRAINT_NAME AS name FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL
             LIMIT 1',
            [$database, $table, $column],
        );

        if ($foreign === null || empty($foreign->name)) {
            return;
        }

        $name = str_replace('`', '``', (string) $foreign->name);
        $tableName = str_replace('`', '``', $table);
        DB::statement("ALTER TABLE `{$tableName}` DROP FOREIGN KEY `{$name}`");
    }
};
