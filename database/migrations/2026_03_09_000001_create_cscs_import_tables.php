<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sra_external_identifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sra_id')->constrained('shareholder_register_accounts')->cascadeOnUpdate()->cascadeOnDelete();
            $table->enum('identifier_type', ['chn', 'cscs_account_no']);
            $table->string('identifier_value', 100);
            $table->string('source', 50)->default('manual');
            $table->foreignId('created_by')->nullable()->constrained('admin_users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();

            $table->unique(['identifier_type', 'identifier_value'], 'uk_sra_ext_id_type_value');
            $table->index(['sra_id', 'identifier_type'], 'idx_sra_ext_id_sra_type');
        });

        Schema::create('cscs_upload_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('uploaded_by')->nullable()->constrained('admin_users')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('register_id')->nullable()->constrained('registers')->cascadeOnUpdate()->nullOnDelete();
            $table->enum('status', ['processing', 'completed', 'completed_with_errors', 'failed'])->default('processing');
            $table->json('uploaded_files');
            $table->json('summary')->nullable();
            $table->timestamps();
        });

        Schema::create('cscs_upload_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('cscs_upload_batches')->cascadeOnUpdate()->cascadeOnDelete();
            $table->enum('file_type', ['master', 'movement']);
            $table->string('source_filename', 255);
            $table->unsignedInteger('row_number');
            $table->string('tran_no', 32)->nullable();
            $table->string('tran_seq', 8)->nullable();
            $table->date('trade_date')->nullable();
            $table->string('sec_code', 20)->nullable();
            $table->enum('identifier_type', ['chn', 'cscs_account_no'])->nullable();
            $table->string('identifier_value', 100)->nullable();
            $table->enum('sign', ['+', '-'])->nullable();
            $table->decimal('volume', 28, 6)->nullable();
            $table->enum('status', ['posted', 'skipped', 'failed'])->default('skipped');
            $table->string('matched_by', 50)->nullable();
            $table->text('error_message')->nullable();
            $table->decimal('before_qty', 28, 6)->nullable();
            $table->decimal('delta_qty', 28, 6)->nullable();
            $table->decimal('after_qty', 28, 6)->nullable();
            $table->foreignId('shareholder_id')->nullable()->constrained('shareholders')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('sra_id')->nullable()->constrained('shareholder_register_accounts')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('share_class_id')->nullable()->constrained('share_classes')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('share_transaction_id')->nullable()->constrained('share_transactions')->cascadeOnUpdate()->nullOnDelete();
            $table->string('fingerprint', 191)->nullable();
            $table->text('raw_line');
            $table->json('extra_details')->nullable();
            $table->timestamps();

            $table->index(['batch_id', 'status'], 'idx_cscs_rows_batch_status');
            $table->index(['tran_no', 'tran_seq'], 'idx_cscs_rows_tran');
            $table->unique('fingerprint', 'uk_cscs_rows_fingerprint');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cscs_upload_rows');
        Schema::dropIfExists('cscs_upload_batches');
        Schema::dropIfExists('sra_external_identifiers');
    }
};

