<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('probate_beneficiaries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('probate_case_id')->constrained('probate_cases')->cascadeOnUpdate()->restrictOnDelete();
            $t->foreignId('beneficiary_shareholder_id')->nullable()->constrained('shareholders')->cascadeOnUpdate()->restrictOnDelete();
            $t->string('beneficiary_name', 255)->nullable();
            $t->string('relationship', 100)->nullable();
            $t->foreignId('share_class_id')->nullable()->constrained('share_classes')->cascadeOnUpdate()->restrictOnDelete();
            $t->foreignId('sra_id')->nullable()->constrained('shareholder_register_accounts')->cascadeOnUpdate()->restrictOnDelete();
            $t->decimal('quantity', 28, 6)->nullable();
            $t->enum('transfer_status', ['pending','approved','executed','failed'])->default('pending');
            $t->foreignId('executed_by')->nullable()->constrained('admin_users')->cascadeOnUpdate()->restrictOnDelete();
            $t->timestamp('executed_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('probate_beneficiaries');
    }
};
