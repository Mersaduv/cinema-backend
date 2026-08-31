<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Completes / repairs viewer separation after a partial run of
 * 2026_08_05_100000_create_viewers_table_and_separate_from_users.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('viewers')) {
            Schema::create('viewers', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->timestamp('email_verified_at')->nullable();
                $table->string('password');
                $table->enum('status', ['active', 'banned'])->default('active');
                $table->string('google_id')->nullable()->unique();
                $table->rememberToken();
                $table->timestamps();
            });
        }

        $staffUserIds = DB::table('role_user')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->whereIn('roles.slug', ['admin', 'support'])
            ->pluck('role_user.user_id')
            ->unique()
            ->all();

        $viewerUsersQuery = DB::table('users');
        if ($staffUserIds !== []) {
            $viewerUsersQuery->whereNotIn('id', $staffUserIds);
        }

        foreach ($viewerUsersQuery->get() as $user) {
            $exists = DB::table('viewers')->where('id', $user->id)->exists()
                || DB::table('viewers')->where('email', $user->email)->exists();

            if ($exists) {
                continue;
            }

            DB::table('viewers')->insert([
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at,
                'password' => $user->password,
                'status' => $user->status ?? 'active',
                'google_id' => $user->google_id ?? null,
                'remember_token' => $user->remember_token,
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
            ]);
        }

        $viewerIds = DB::table('viewers')->pluck('id')->all();
        $migratedFromUsers = DB::table('users')
            ->when($staffUserIds !== [], fn ($q) => $q->whereNotIn('id', $staffUserIds))
            ->pluck('id')
            ->all();

        if ($migratedFromUsers !== []) {
            DB::table('role_user')->whereIn('user_id', $migratedFromUsers)->delete();
            DB::table('personal_access_tokens')
                ->where('tokenable_type', 'App\\Models\\User')
                ->whereIn('tokenable_id', $migratedFromUsers)
                ->delete();
        }

        $this->migrateAudienceTable('reviews', dropUnique: 'uq_user_reviewable', uniqueColumns: ['viewer_id', 'reviewable_type', 'reviewable_id'], addUnique: 'uq_viewer_reviewable');
        $this->migrateAudienceTable('watch_progress');
        $this->migrateAudienceTable('content_requests');
        $this->migrateAudienceTable('support_tickets');
        $this->migrateAudienceTable('tv_activation_codes', nullable: true);

        // Drop orphan audience rows that still point at staff-only users.
        foreach (['reviews', 'watch_progress', 'content_requests', 'support_tickets'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'viewer_id')) {
                DB::table($table)->whereNotIn('viewer_id', $viewerIds ?: [0])->delete();
            }
        }

        if (Schema::hasTable('tv_activation_codes') && Schema::hasColumn('tv_activation_codes', 'viewer_id')) {
            DB::table('tv_activation_codes')
                ->whereNotNull('viewer_id')
                ->whereNotIn('viewer_id', $viewerIds ?: [0])
                ->update(['viewer_id' => null]);
        }

        $this->ensureViewerForeignKey('reviews', 'viewer_id', nullable: false, uniqueColumns: ['viewer_id', 'reviewable_type', 'reviewable_id'], uniqueName: 'uq_viewer_reviewable');
        $this->ensureViewerForeignKey('watch_progress', 'viewer_id');
        $this->ensureViewerForeignKey('content_requests', 'viewer_id');
        $this->ensureViewerForeignKey('support_tickets', 'viewer_id');
        $this->ensureViewerForeignKey('tv_activation_codes', 'viewer_id', nullable: true);

        if (Schema::hasTable('support_ticket_messages') && ! Schema::hasColumn('support_ticket_messages', 'sender_viewer_id')) {
            Schema::table('support_ticket_messages', function (Blueprint $table) {
                $table->foreignId('sender_viewer_id')->nullable()->after('sender_user_id')->constrained('viewers')->nullOnDelete();
            });
        }

        if (Schema::hasColumn('support_ticket_messages', 'sender_viewer_id') && $viewerIds !== []) {
            DB::table('support_ticket_messages')
                ->whereIn('sender_user_id', $viewerIds)
                ->where('sender_role', 'user')
                ->whereNull('sender_viewer_id')
                ->orderBy('id')
                ->chunkById(200, function ($messages) {
                    foreach ($messages as $message) {
                        DB::table('support_ticket_messages')
                            ->where('id', $message->id)
                            ->update([
                                'sender_viewer_id' => $message->sender_user_id,
                                'sender_user_id' => null,
                            ]);
                    }
                });
        }

        if ($migratedFromUsers !== []) {
            DB::table('users')->whereIn('id', $migratedFromUsers)->delete();
        }

        $maxViewerId = (int) DB::table('viewers')->max('id');
        if ($maxViewerId > 0 && Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE viewers AUTO_INCREMENT = ' . ($maxViewerId + 1));
        }
    }

    public function down(): void
    {
        // Non-reversible repair migration.
    }

    private function migrateAudienceTable(
        string $table,
        ?string $dropUnique = null,
        ?array $uniqueColumns = null,
        ?string $addUnique = null,
        bool $nullable = false,
    ): void {
        if (! Schema::hasTable($table)) {
            return;
        }

        if (Schema::hasColumn($table, 'viewer_id')) {
            return;
        }

        if (! Schema::hasColumn($table, 'user_id')) {
            return;
        }

        $this->tryDropForeign($table, 'user_id');

        if ($dropUnique) {
            $this->tryDropIndex($table, $dropUnique);
        }

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->renameColumn('user_id', 'viewer_id');
        });
    }

    private function ensureViewerForeignKey(
        string $table,
        string $column,
        bool $nullable = false,
        ?array $uniqueColumns = null,
        ?string $uniqueName = null,
    ): void {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        $this->tryDropForeign($table, $column);

        if ($this->foreignExists($table, $column)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column, $nullable) {
            if ($nullable) {
                $blueprint->foreign($column)->references('id')->on('viewers')->nullOnDelete();
            } else {
                $blueprint->foreign($column)->references('id')->on('viewers')->cascadeOnDelete();
            }
        });

        if ($uniqueName && $uniqueColumns && ! $this->indexExists($table, $uniqueName)) {
            Schema::table($table, function (Blueprint $blueprint) use ($uniqueColumns, $uniqueName) {
                $blueprint->unique($uniqueColumns, $uniqueName);
            });
        }
    }

    private function tryDropForeign(string $table, string $column): void
    {
        $name = $this->foreignName($table, $column);
        if (! $name) {
            return;
        }

        Schema::getConnection()->statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$name}`");
    }

    private function foreignExists(string $table, string $column): bool
    {
        return $this->foreignName($table, $column) !== null;
    }

    private function foreignName(string $table, string $column): ?string
    {
        $connection = Schema::getConnection();
        $database = $connection->getDatabaseName();

        $foreign = $connection->selectOne(
            'SELECT CONSTRAINT_NAME AS name FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL
             LIMIT 1',
            [$database, $table, $column]
        );

        return $foreign && ! empty($foreign->name) ? (string) $foreign->name : null;
    }

    private function tryDropIndex(string $table, string $indexName): void
    {
        if (! $this->indexExists($table, $indexName)) {
            return;
        }

        Schema::getConnection()->statement("ALTER TABLE `{$table}` DROP INDEX `{$indexName}`");
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $connection = Schema::getConnection();
        $database = $connection->getDatabaseName();

        $index = $connection->selectOne(
            'SELECT INDEX_NAME AS name FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?
             LIMIT 1',
            [$database, $table, $indexName]
        );

        return (bool) ($index && ! empty($index->name));
    }
};
