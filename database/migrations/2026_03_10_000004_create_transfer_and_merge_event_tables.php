<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('share_transfer_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_shareholder_id')->constrained('shareholders')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('to_shareholder_id')->constrained('shareholders')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('from_sra_id')->constrained('shareholder_register_accounts')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('to_sra_id')->constrained('shareholder_register_accounts')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('share_class_id')->constrained('share_classes')->cascadeOnUpdate()->restrictOnDelete();
            $table->decimal('quantity', 28, 6);
            $table->string('tx_ref', 64)->index();
            $table->string('document_ref', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('admin_users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('shareholder_merge_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('primary_shareholder_id')->constrained('shareholders')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('duplicate_shareholder_id')->constrained('shareholders')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('verification_basis', 50);
            $table->string('reason', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('admin_users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shareholder_merge_events');
        Schema::dropIfExists('share_transfer_events');
    }
};

