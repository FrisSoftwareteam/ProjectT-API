<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shareholder_import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('uploaded_by')->nullable()->constrained('admin_users')->cascadeOnUpdate()->nullOnDelete();
            $table->string('status', 40)->default('processing');
            $table->string('source_filename')->nullable();
            $table->string('stored_path')->nullable();
            $table->json('summary')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('uploaded_by');
        });

        Schema::create('shareholder_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('shareholder_import_batches')->cascadeOnUpdate()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('status', 40)->default('pending');
            $table->json('raw_data')->nullable();
            $table->json('errors')->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('shareholder_id')->nullable()->constrained('shareholders')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('shareholder_register_account_id')->nullable()->constrained('shareholder_register_accounts')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('share_position_id')->nullable()->constrained('share_positions')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('share_lot_id')->nullable()->constrained('share_lots')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('share_transaction_id')->nullable()->constrained('share_transactions')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();

            $table->index(['batch_id', 'status']);
            $table->index('row_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shareholder_import_rows');
        Schema::dropIfExists('shareholder_import_batches');
    }
};
