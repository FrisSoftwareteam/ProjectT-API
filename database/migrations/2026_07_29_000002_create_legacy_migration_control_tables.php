<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shareholders', function (Blueprint $table) {
            $table->boolean('email_is_verified')->default(true)->after('email');
            $table->boolean('phone_is_verified')->default(true)->after('phone');
            $table->boolean('contact_suppressed')->default(false)->after('phone_is_verified')->index();
        });

        Schema::create('legacy_migration_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('package_key', 100);
            $table->string('package_version', 30);
            $table->foreignId('register_id')->constrained('registers')->restrictOnDelete();
            $table->foreignId('share_class_id')->constrained('share_classes')->restrictOnDelete();
            $table->string('source_filename');
            $table->char('source_sha256', 64);
            $table->unsignedBigInteger('source_size');
            $table->string('status', 40)->default('CREATED')->index();
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedBigInteger('expected_rows');
            $table->decimal('expected_quantity', 28, 6);
            $table->unsignedBigInteger('staged_rows')->default(0);
            $table->unsignedBigInteger('valid_rows')->default(0);
            $table->unsignedBigInteger('error_rows')->default(0);
            $table->unsignedBigInteger('published_rows')->default(0);
            $table->unsignedBigInteger('rolled_back_rows')->default(0);
            $table->decimal('staged_quantity', 28, 6)->default(0);
            $table->json('config_snapshot');
            $table->json('reconciliation')->nullable();
            $table->char('approval_snapshot_hash', 64)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->foreignId('validated_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('publishing_started_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('rolled_back_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('rollback_started_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->unique(['register_id', 'source_sha256'], 'uk_legacy_batch_register_source');
            $table->index(['package_key', 'package_version'], 'idx_legacy_batch_package');
        });

        Schema::create('legacy_migration_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('legacy_migration_batches')->cascadeOnDelete();
            $table->unsignedBigInteger('source_row_number');
            $table->char('source_key_hash', 64);
            $table->char('row_hash', 64);
            $table->char('idempotency_key', 64)->index();
            $table->string('source_account_number', 30);
            $table->string('target_account_no', 20)->index();
            $table->string('target_email')->index();
            $table->string('target_phone', 32)->index();
            $table->string('holder_type', 20);
            $table->string('category_code', 10);
            $table->decimal('quantity', 28, 6);
            $table->string('holding_mode', 10);
            $table->string('status', 30)->default('VALID')->index();
            $table->json('normalized_data')->nullable();
            $table->json('errors')->nullable();
            $table->unsignedBigInteger('shareholder_id')->nullable();
            $table->unsignedBigInteger('address_id')->nullable();
            $table->unsignedBigInteger('sra_id')->nullable();
            $table->unsignedBigInteger('position_id')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->timestamps();

            $table->unique(['batch_id', 'source_row_number'], 'uk_legacy_record_batch_row');
            $table->index(['batch_id', 'source_key_hash'], 'idx_legacy_record_batch_source');
            $table->index(['batch_id', 'status'], 'idx_legacy_record_batch_status');
            $table->index(['batch_id', 'category_code'], 'idx_legacy_record_batch_category');
        });

        Schema::create('legacy_migration_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('legacy_migration_batches')->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->string('decision', 30);
            $table->foreignId('actor_id')->constrained('admin_users')->restrictOnDelete();
            $table->text('comment')->nullable();
            $table->char('snapshot_hash', 64)->nullable();
            $table->timestamp('acted_at')->useCurrent();
            $table->timestamps();

            $table->index(['batch_id', 'revision'], 'idx_legacy_approval_revision');
        });

        Schema::create('legacy_migration_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('legacy_migration_batches')->cascadeOnDelete();
            $table->string('event_type', 50);
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40)->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->text('comment')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['batch_id', 'created_at'], 'idx_legacy_event_batch_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_migration_events');
        Schema::dropIfExists('legacy_migration_approvals');
        Schema::dropIfExists('legacy_migration_records');
        Schema::dropIfExists('legacy_migration_batches');

        Schema::table('shareholders', function (Blueprint $table) {
            $table->dropIndex(['contact_suppressed']);
            $table->dropColumn(['email_is_verified', 'phone_is_verified', 'contact_suppressed']);
        });
    }
};
