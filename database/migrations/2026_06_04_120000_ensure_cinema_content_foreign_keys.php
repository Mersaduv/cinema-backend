<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        Artisan::call('cinema:repair-orphaned-series');

        $this->addForeignKeyIfMissing('series_seasons', 'series_seasons_series_id_foreign', 'series_id', 'series', 'id');
        $this->addForeignKeyIfMissing('series_episodes', 'series_episodes_series_id_foreign', 'series_id', 'series', 'id');
        $this->addForeignKeyIfMissing(
            'series_episodes',
            'series_episodes_series_season_id_foreign',
            'series_season_id',
            'series_seasons',
            'id',
            'set null',
        );
        $this->addForeignKeyIfMissing('series_cast', 'series_cast_series_id_foreign', 'series_id', 'series', 'id');
        $this->addForeignKeyIfMissing('series_crew', 'series_crew_series_id_foreign', 'series_id', 'series', 'id');
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        foreach ([
            'series_crew',
            'series_cast',
            'series_episodes',
            'series_seasons',
        ] as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $foreignKeys = $this->foreignKeyNames($table);

                foreach ($foreignKeys as $foreignKey) {
                    try {
                        $blueprint->dropForeign($foreignKey);
                    } catch (\Throwable) {
                        // ignore missing keys on rollback
                    }
                }
            });
        }
    }

    private function addForeignKeyIfMissing(
        string $table,
        string $constraintName,
        string $column,
        string $referencedTable,
        string $referencedColumn,
        string $onDelete = 'cascade',
    ): void {
        if ($this->foreignKeyExists($table, $constraintName)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column, $referencedTable, $referencedColumn, $onDelete): void {
            $foreign = $blueprint->foreign($column)->references($referencedColumn)->on($referencedTable);

            if ($onDelete === 'set null') {
                $foreign->nullOnDelete();
            } else {
                $foreign->cascadeOnDelete();
            }
        });
    }

    private function foreignKeyExists(string $table, string $constraintName): bool
    {
        $schema = Schema::getConnection()->getDatabaseName();

        $rows = DB::select(
            'SELECT CONSTRAINT_NAME
             FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = ?
               AND TABLE_NAME = ?
               AND CONSTRAINT_NAME = ?
               AND CONSTRAINT_TYPE = ?',
            [$schema, $table, $constraintName, 'FOREIGN KEY'],
        );

        return count($rows) > 0;
    }

    /**
     * @return list<string>
     */
    private function foreignKeyNames(string $table): array
    {
        $schema = Schema::getConnection()->getDatabaseName();

        return collect(DB::select(
            'SELECT CONSTRAINT_NAME
             FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = ?
               AND TABLE_NAME = ?
               AND CONSTRAINT_TYPE = ?',
            [$schema, $table, 'FOREIGN KEY'],
        ))
            ->map(fn ($row) => (string) $row->CONSTRAINT_NAME)
            ->values()
            ->all();
    }
};
