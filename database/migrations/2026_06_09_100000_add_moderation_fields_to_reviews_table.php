<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->string('status', 20)->default('pending')->after('body');
            $table->text('body_original')->nullable()->after('status');
            $table->string('admin_note', 500)->nullable()->after('body_original');
            $table->timestamp('moderated_at')->nullable()->after('admin_note');
        });

        DB::table('reviews')->update([
            'status' => DB::raw("CASE WHEN is_approved = 1 THEN 'approved' ELSE 'pending' END"),
        ]);
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropColumn(['status', 'body_original', 'admin_note', 'moderated_at']);
        });
    }
};
