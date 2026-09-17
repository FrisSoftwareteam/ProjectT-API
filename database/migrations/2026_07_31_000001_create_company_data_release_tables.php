<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_data_releases', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->char('bundle_release_id', 64)->unique();
            $table->string('format_version', 40);
            $table->string('artifact_filename');
            $table->char('artifact_sha256', 64)->unique();
            $table->unsignedBigInteger('artifact_size');
            $table->text('artifact_path');
            $table->string('source_filename');
            $table->char('source_sha256', 64);
            $table->char('approved_snapshot_sha256', 64);
            $table->string('issuer_code', 50);
            $table->string('register_code', 50);
            $table->string('share_class_code', 50);
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('register_id')->constrained('registers')->restrictOnDelete();
            $table->foreignId('share_class_id')->constrained('share_classes')->restrictOnDelete();
            $table->string('status', 40)->index();
            $table->unsignedBigInteger('expected_rows');
            $table->decimal('expected_quantity', 28, 6);
            $table->unsignedBigInteger('imported_rows')->default(0);
            $table->unsignedBigInteger('rolled_back_rows')->default(0);
            $table->decimal('imported_quantity', 28, 6)->default(0);
            $table->json('manifest');
            $table->json('verification');
            $table->json('reconciliation')->nullable();
            $table->char('approval_snapshot_hash', 64)->nullable();
            $table->foreignId('verified_by')->constrained('admin_users')->restrictOnDelete();
            $table->timestamp('verified_at');
            $table->foreignId('approved_by')->nullable()->constrained('admin_users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('imported_by')->nullable()->constrained('admin_users')->restrictOnDelete();
            $table->timestamp('import_started_at')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->foreignId('rolled_back_by')->nullable()->constrained('admin_users')->restrictOnDelete();
            $table->timestamp('rollback_started_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['register_id', 'status'], 'idx_company_release_register_status');
            $table->index(['source_sha256', 'approved_snapshot_sha256'], 'idx_company_release_source_snapshot');
        });

        Schema::create('company_data_release_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('release_id')->constrained('company_data_releases')->cascadeOnDelete();
            $table->unsignedBigInteger('source_row_number');
            $table->char('idempotency_key', 64);
            $table->char('row_hash', 64);
            $table->string('source_account_number', 30);
            $table->string('category_code', 10);
            $table->string('holder_type', 20);
            $table->decimal('quantity', 28, 6);
            $table->string('holding_mode', 10);
            $table->string('status', 30)->index();
            $table->unsignedBigInteger('shareholder_id')->nullable();
            $table->unsignedBigInteger('address_id')->nullable();
            $table->unsignedBigInteger('sra_id')->nullable();
            $table->unsignedBigInteger('position_id')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->timestamps();

            $table->unique(['release_id', 'source_row_number'], 'uk_company_release_record_row');
            $table->unique(['release_id', 'idempotency_key'], 'uk_company_release_record_idempotency');
            $table->index(['release_id', 'status'], 'idx_company_release_record_status');
            $table->index(['release_id', 'category_code'], 'idx_company_release_record_category');
        });

        Schema::create('company_data_release_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('release_id')->constrained('company_data_releases')->cascadeOnDelete();
            $table->string('decision', 30);
            $table->foreignId('actor_id')->constrained('admin_users')->restrictOnDelete();
            $table->text('comment');
            $table->char('snapshot_hash', 64);
            $table->timestamp('acted_at')->useCurrent();
            $table->timestamps();

            $table->index(['release_id', 'decision'], 'idx_company_release_approval_decision');
        });

        Schema::create('company_data_release_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('release_id')->constrained('company_data_releases')->cascadeOnDelete();
            $table->string('event_type', 50);
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40)->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->text('comment')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['release_id', 'created_at'], 'idx_company_release_event_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_data_release_events');
        Schema::dropIfExists('company_data_release_approvals');
        Schema::dropIfExists('company_data_release_records');
        Schema::dropIfExists('company_data_releases');
    }
};
