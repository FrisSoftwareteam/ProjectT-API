<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dividend_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entitlement_id')
                ->constrained('dividend_entitlements')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->enum('payout_mode', ['edividend','warrant','bank_transfer']);
            $table->foreignId('bank_mandate_id')
                ->nullable()
                ->constrained('shareholder_bank_mandates')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->string('paid_ref', 64)->nullable();
            $table->enum('status', ['initiated','paid','failed','disputed','reissued'])->default('initiated');
            $table->unsignedBigInteger('reissued_from_id')->nullable();
            $table->string('reissue_reason', 255)->nullable();
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('admin_users')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->timestamps();

            $table->foreign('reissued_from_id')
                ->references('id')
                ->on('dividend_payments')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->index('entitlement_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dividend_payments');
    }
};
