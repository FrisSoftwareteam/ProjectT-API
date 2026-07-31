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

        Schema::table('legacy_migration_batches', function (Blueprint $table) {
            $table->unsignedInteger('attempt_no')->default(1)->after('revision');
            $table->dropUnique('uk_legacy_batch_register_source');
            $table->unique(
                ['register_id', 'source_sha256', 'attempt_no'],
                'uk_legacy_batch_register_source_attempt'
            );
        });
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

        Schema::table('legacy_migration_batches', function (Blueprint $table) {
            $table->dropUnique('uk_legacy_batch_register_source_attempt');
            $table->dropColumn('attempt_no');
            $table->unique(['register_id', 'source_sha256'], 'uk_legacy_batch_register_source');
        });
    }
};
