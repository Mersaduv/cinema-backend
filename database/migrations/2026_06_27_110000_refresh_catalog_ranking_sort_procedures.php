<?php

use App\Support\CatalogPublicProcedureInstaller;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        CatalogPublicProcedureInstaller::install();
    }

    public function down(): void
    {
        // Procedures are replaced by re-running the original installer migration if needed.
    }
};
