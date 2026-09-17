<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('legacy_migration_batches')) {
            return;
        }

        if (! Schema::hasColumn('legacy_migration_batches', 'attempt_no')) {
            Schema::table('legacy_migration_batches', function (Blueprint $table) {
                $table->unsignedInteger('attempt_no')->default(1)->after('revision');
            });
        }

        if (! Schema::hasIndex('legacy_migration_batches', 'idx_legacy_batch_register_fk')) {
            Schema::table('legacy_migration_batches', function (Blueprint $table) {
                $table->index('register_id', 'idx_legacy_batch_register_fk');
            });
        }

        if (! Schema::hasIndex('legacy_migration_batches', 'uk_legacy_batch_register_source_attempt')) {
            Schema::table('legacy_migration_batches', function (Blueprint $table) {
                $table->unique(
                    ['register_id', 'source_sha256', 'attempt_no'],
                    'uk_legacy_batch_register_source_attempt'
                );
            });
        }

        if (Schema::hasIndex('legacy_migration_batches', 'uk_legacy_batch_register_source')) {
            Schema::table('legacy_migration_batches', function (Blueprint $table) {
                $table->dropUnique('uk_legacy_batch_register_source');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('legacy_migration_batches') || ! Schema::hasColumn('legacy_migration_batches', 'attempt_no')) {
            return;
        }

        $hasRepeatedAttempts = DB::table('legacy_migration_batches')
            ->select(['register_id', 'source_sha256'])
            ->groupBy(['register_id', 'source_sha256'])
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasRepeatedAttempts) {
            throw new RuntimeException('Cannot remove migration attempt support while repeated batches exist.');
        }

        if (Schema::hasIndex('legacy_migration_batches', 'uk_legacy_batch_register_source_attempt')) {
            Schema::table('legacy_migration_batches', function (Blueprint $table) {
                $table->dropUnique('uk_legacy_batch_register_source_attempt');
            });
        }

        if (Schema::hasColumn('legacy_migration_batches', 'attempt_no')) {
            Schema::table('legacy_migration_batches', function (Blueprint $table) {
                $table->dropColumn('attempt_no');
            });
        }

        if (! Schema::hasIndex('legacy_migration_batches', 'uk_legacy_batch_register_source')) {
            Schema::table('legacy_migration_batches', function (Blueprint $table) {
                $table->unique(['register_id', 'source_sha256'], 'uk_legacy_batch_register_source');
            });
        }

        if (Schema::hasIndex('legacy_migration_batches', 'idx_legacy_batch_register_fk')) {
            Schema::table('legacy_migration_batches', function (Blueprint $table) {
                $table->dropIndex('idx_legacy_batch_register_fk');
            });
        }
    }
};
