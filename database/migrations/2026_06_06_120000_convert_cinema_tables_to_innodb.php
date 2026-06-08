<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach (DB::select('SHOW TABLE STATUS') as $table) {
            $name = (string) ($table->Name ?? '');
            $engine = strtoupper((string) ($table->Engine ?? ''));

            if ($name === '' || $engine === 'INNODB') {
                continue;
            }

            DB::statement(sprintf('ALTER TABLE `%s` ENGINE=InnoDB', str_replace('`', '``', $name)));
        }

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        // Engine conversion is intentionally one-way for transactional integrity.
    }
};
