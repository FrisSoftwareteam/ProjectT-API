<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ipo_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('register_id')->constrained('registers')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('share_class_id')->constrained('share_classes')->cascadeOnUpdate()->restrictOnDelete();
            $table->decimal('approved_units', 28, 6);
            $table->decimal('allotted_units', 28, 6)->default(0);
            $table->enum('status', ['draft', 'approved', 'finalized'])->default('draft');
            $table->string('offer_ref', 64)->unique();
            $table->foreignId('created_by')->nullable()->constrained('admin_users')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('admin_users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ipo_offer_allotments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained('ipo_offers')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('shareholder_id')->constrained('shareholders')->cascadeOnUpdate()->restrictOnDelete();
            $table->decimal('quantity', 28, 6);
            $table->enum('post_status', ['pending', 'posted'])->default('pending');
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->unique(['offer_id', 'shareholder_id'], 'uk_offer_shareholder');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ipo_offer_allotments');
        Schema::dropIfExists('ipo_offers');
    }
};

