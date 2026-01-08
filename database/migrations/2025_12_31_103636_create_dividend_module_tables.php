<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Dividend Declarations (Core Header)
        Schema::create('dividend_declarations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('register_id')->constrained('registers')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('period_label', 100);
            $table->string('description', 255)->nullable();
            $table->enum('action_type', ['DIVIDEND'])->default('DIVIDEND');
            $table->enum('declaration_method', ['RATE_PER_SHARE'])->default('RATE_PER_SHARE');
            $table->decimal('rate_per_share', 18, 6)->nullable();
            $table->date('announcement_date')->nullable();
            $table->date('record_date')->nullable();
            $table->date('payment_date')->nullable();
            $table->boolean('exclude_caution_accounts')->default(false);
            $table->boolean('require_active_bank_mandate')->default(true);
            $table->enum('status', ['DRAFT', 'SUBMITTED', 'VERIFIED', 'APPROVED', 'REJECTED'])->default('DRAFT');
            
            // Server-calculated totals (frozen after approval)
            $table->decimal('total_gross_amount', 18, 2)->nullable();
            $table->decimal('total_tax_amount', 18, 2)->nullable();
            $table->decimal('total_net_amount', 18, 2)->nullable();
            $table->decimal('rounding_residue', 18, 6)->nullable();
            $table->unsignedBigInteger('eligible_shareholders_count')->nullable();
            
            // Workflow timestamps
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_reason', 255)->nullable();
            
            // Actor references
            $table->foreignId('created_by')->constrained('admin_users')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('admin_users')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('admin_users')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('admin_users')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('rejected_by')->nullable()->constrained('admin_users')->cascadeOnUpdate()->restrictOnDelete();
            
            $table->timestamps();
            
            // Indexes
            $table->unique(['company_id', 'period_label'], 'uq_div_company_period');
            $table->index('register_id');
            $table->index('status');
            $table->index(['record_date', 'payment_date']);
        });

        // 2. Dividend Declaration Share Classes (Junction Table)
        Schema::create('dividend_declaration_share_classes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('dividend_declaration_id');
            $table->unsignedBigInteger('share_class_id');
            $table->timestamp('created_at')->useCurrent();
            
            // Add foreign keys with custom names
            $table->foreign('dividend_declaration_id', 'fk_div_decl_sc_decl_id')
                ->references('id')
                ->on('dividend_declarations')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            
            $table->foreign('share_class_id', 'fk_div_decl_sc_class_id')
                ->references('id')
                ->on('share_classes')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            
            $table->unique(['dividend_declaration_id', 'share_class_id'], 'uq_div_decl_share_class');
        });

        // 3. Dividend Entitlement Runs (Snapshot Header)
        Schema::create('dividend_entitlement_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('dividend_declaration_id');
            $table->enum('run_type', ['PREVIEW', 'FROZEN'])->default('PREVIEW');
            $table->enum('run_status', ['PENDING', 'COMPLETED', 'FAILED'])->default('PENDING');
            $table->timestamp('computed_at')->nullable();
            $table->unsignedBigInteger('computed_by')->nullable();
            
            // Computed totals
            $table->decimal('total_gross_amount', 18, 2)->nullable();
            $table->decimal('total_tax_amount', 18, 2)->nullable();
            $table->decimal('total_net_amount', 18, 2)->nullable();
            $table->decimal('rounding_residue', 18, 6)->nullable();
            $table->unsignedBigInteger('eligible_shareholders_count')->nullable();
            $table->string('error_message', 255)->nullable();
            
            $table->timestamp('created_at')->useCurrent();
            
            // Add foreign keys with custom names
            $table->foreign('dividend_declaration_id', 'fk_div_ent_runs_decl_id')
                ->references('id')
                ->on('dividend_declarations')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            
            $table->foreign('computed_by', 'fk_div_ent_runs_computed_by')
                ->references('id')
                ->on('admin_users')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            
            $table->index('dividend_declaration_id', 'idx_div_ent_runs_decl_id');
            $table->index('run_type');
            $table->index('run_status');
        });

        // 4. Dividend Entitlements (Line Items per Holder)
        Schema::create('dividend_entitlements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('entitlement_run_id');
            $table->unsignedBigInteger('dividend_declaration_id');
            $table->unsignedBigInteger('register_account_id');
            $table->unsignedBigInteger('share_class_id');
            
            $table->decimal('eligible_shares', 18, 6)->default(0);
            $table->decimal('gross_amount', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('net_amount', 18, 2)->default(0);
            
            // Eligibility flags
            $table->boolean('is_payable')->default(true);
            $table->enum('ineligibility_reason', ['NONE', 'CAUTION', 'NO_ACTIVE_BANK_MANDATE', 'OTHER'])->default('NONE');
            
            $table->timestamp('created_at')->useCurrent();
            
            // Add foreign keys with custom names
            $table->foreign('entitlement_run_id', 'fk_div_ent_run_id')
                ->references('id')
                ->on('dividend_entitlement_runs')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            
            $table->foreign('dividend_declaration_id', 'fk_div_ent_decl_id')
                ->references('id')
                ->on('dividend_declarations')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            
            $table->foreign('register_account_id', 'fk_div_ent_reg_acct_id')
                ->references('id')
                ->on('shareholder_register_accounts')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            
            $table->foreign('share_class_id', 'fk_div_ent_class_id')
                ->references('id')
                ->on('share_classes')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            
            $table->unique(['entitlement_run_id', 'register_account_id', 'share_class_id'], 'uq_div_ent_run_acct_class');
            $table->index('dividend_declaration_id', 'idx_div_ent_decl_id');
            $table->index('register_account_id', 'idx_div_ent_reg_acct_id');
            $table->index('share_class_id', 'idx_div_ent_share_class_id');
            $table->index('is_payable');
        });

        // 5. Dividend Workflow Events (Audit Trail)
        Schema::create('dividend_workflow_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('dividend_declaration_id');
            $table->enum('event_type', ['CREATED', 'UPDATED', 'SUBMITTED', 'VERIFIED', 'APPROVED', 'REJECTED', 'EXPORTED', 'PAYMENT_REISSUED']);
            $table->unsignedBigInteger('actor_id');
            $table->string('note', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
            
            // Add foreign keys with custom names
            $table->foreign('dividend_declaration_id', 'fk_div_wf_decl_id')
                ->references('id')
                ->on('dividend_declarations')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            
            $table->foreign('actor_id', 'fk_div_wf_actor_id')
                ->references('id')
                ->on('admin_users')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            
            $table->index('dividend_declaration_id', 'idx_div_wf_events_decl_id');
            $table->index('event_type');
            $table->index('actor_id');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dividend_workflow_events');
        Schema::dropIfExists('dividend_entitlements');
        Schema::dropIfExists('dividend_entitlement_runs');
        Schema::dropIfExists('dividend_declaration_share_classes');
        Schema::dropIfExists('dividend_declarations');
    }
};