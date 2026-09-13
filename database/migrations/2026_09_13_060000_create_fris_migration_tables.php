<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fris_migration_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('source_filename');
            $table->text('source_path');
            $table->char('source_sha256', 64)->nullable();
            $table->unsignedBigInteger('source_size');
            $table->string('status', 40)->default('CREATED')->index();
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedInteger('register_code')->nullable()->index();
            $table->string('company_name')->nullable();
            $table->unsignedBigInteger('expected_profile_rows')->default(0);
            $table->unsignedBigInteger('expected_unit_rows')->default(0);
            $table->unsignedBigInteger('staged_profile_rows')->default(0);
            $table->unsignedBigInteger('staged_unit_rows')->default(0);
            $table->unsignedBigInteger('valid_profile_rows')->default(0);
            $table->unsignedBigInteger('valid_unit_rows')->default(0);
            $table->unsignedBigInteger('error_profile_rows')->default(0);
            $table->unsignedBigInteger('error_unit_rows')->default(0);
            $table->decimal('expected_profile_holdings', 28, 6)->default(0);
            $table->decimal('expected_unit_quantity', 28, 6)->default(0);
            $table->decimal('staged_profile_holdings', 28, 6)->default(0);
            $table->decimal('staged_unit_quantity', 28, 6)->default(0);
            $table->json('profile_summary')->nullable();
            $table->json('unit_summary')->nullable();
            $table->json('reconciliation')->nullable();
            $table->char('approval_snapshot_hash', 64)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->foreignId('validated_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('publishing_started_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['register_code', 'status'], 'idx_fris_batch_register_status');
        });

        Schema::create('fris_migration_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('fris_migration_batches')->cascadeOnDelete();
            $table->unsignedBigInteger('source_row_number');
            $table->unsignedInteger('register_code');
            $table->unsignedBigInteger('account_number');
            $table->char('source_key_hash', 64);
            $table->char('row_hash', 64);
            $table->string('status', 30)->default('VALID')->index();
            $table->string('holder_type', 20)->nullable();
            $table->string('normalized_name', 255)->nullable();
            $table->string('normalized_email', 255)->nullable();
            $table->string('normalized_mobile', 32)->nullable();
            $table->decimal('source_holdings', 28, 6)->nullable();
            $table->json('source_data');
            $table->json('normalized_data')->nullable();
            $table->json('errors')->nullable();
            $table->unsignedBigInteger('shareholder_id')->nullable();
            $table->unsignedBigInteger('address_id')->nullable();
            $table->unsignedBigInteger('mandate_id')->nullable();
            $table->unsignedBigInteger('sra_id')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['batch_id', 'source_row_number'], 'uk_fris_profile_batch_row');
            $table->unique(['batch_id', 'register_code', 'account_number'], 'uk_fris_profile_batch_key');
            $table->index(['batch_id', 'status'], 'idx_fris_profile_batch_status');
            $table->index(['register_code', 'account_number'], 'idx_fris_profile_source_key');
        });

        Schema::create('fris_migration_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('fris_migration_batches')->cascadeOnDelete();
            $table->unsignedBigInteger('source_row_number');
            $table->unsignedBigInteger('fris_unit_id');
            $table->unsignedInteger('register_code')->nullable();
            $table->unsignedBigInteger('account_number')->nullable();
            $table->unsignedBigInteger('cert_number')->nullable();
            $table->char('source_key_hash', 64);
            $table->char('row_hash', 64);
            $table->string('status', 30)->default('VALID')->index();
            $table->decimal('source_units', 28, 6)->nullable();
            $table->timestamp('issue_date')->nullable();
            $table->integer('source_status')->nullable();
            $table->integer('source_verified')->nullable();
            $table->integer('source_claimed')->nullable();
            $table->integer('source_stop')->nullable();
            $table->json('source_data');
            $table->json('normalized_data')->nullable();
            $table->json('errors')->nullable();
            $table->unsignedBigInteger('profile_stage_id')->nullable();
            $table->unsignedBigInteger('share_lot_id')->nullable();
            $table->unsignedBigInteger('share_transaction_id')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['batch_id', 'fris_unit_id'], 'uk_fris_unit_batch_source_id');
            $table->index(['batch_id', 'source_row_number'], 'idx_fris_unit_batch_row');
            $table->index(['batch_id', 'status'], 'idx_fris_unit_batch_status');
            $table->index(['register_code', 'account_number'], 'idx_fris_unit_source_account');
            $table->index(['register_code', 'cert_number'], 'idx_fris_unit_source_cert');
        });

        Schema::create('fris_migration_crosswalks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->nullable()->constrained('fris_migration_batches')->nullOnDelete();
            $table->string('source_table', 50);
            $table->string('source_key', 150);
            $table->char('source_key_hash', 64);
            $table->string('target_table', 80);
            $table->unsignedBigInteger('target_id');
            $table->char('row_hash', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['source_table', 'source_key_hash', 'target_table'], 'uk_fris_crosswalk_source_target');
            $table->index(['target_table', 'target_id'], 'idx_fris_crosswalk_target');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fris_migration_crosswalks');
        Schema::dropIfExists('fris_migration_units');
        Schema::dropIfExists('fris_migration_profiles');
        Schema::dropIfExists('fris_migration_batches');
    }
};
