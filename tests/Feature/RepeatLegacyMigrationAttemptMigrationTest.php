<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RepeatLegacyMigrationAttemptMigrationTest extends TestCase
{
    protected function tearDown(): void
    {
        Schema::dropIfExists('legacy_migration_batches');

        parent::tearDown();
    }

    public function test_migration_resumes_after_attempt_column_was_partially_applied(): void
    {
        Schema::create('legacy_migration_batches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('register_id');
            $table->string('revision')->nullable();
            $table->unsignedInteger('attempt_no')->default(1);
            $table->char('source_sha256', 64);
            $table->unique(['register_id', 'source_sha256'], 'uk_legacy_batch_register_source');
        });

        $migration = require database_path('migrations/2026_07_30_000003_allow_repeat_legacy_migration_attempts.php');
        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasColumn('legacy_migration_batches', 'attempt_no'));
        $this->assertFalse(Schema::hasIndex('legacy_migration_batches', 'uk_legacy_batch_register_source'));
        $this->assertTrue(Schema::hasIndex('legacy_migration_batches', 'uk_legacy_batch_register_source_attempt'));
    }
}
