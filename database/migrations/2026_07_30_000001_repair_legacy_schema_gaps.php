<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->ensureUsersTable();
        $this->ensureShareholderNameParts();
        $this->ensureCurrentDividendEntitlements();
    }

    public function down(): void
    {
        // This migration reconciles historical schema drift. Reversing it would
        // restore a schema that the current application cannot safely use.
    }

    private function ensureUsersTable(): void
    {
        if (Schema::hasTable('users')) {
            return;
        }

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
    }

    private function ensureShareholderNameParts(): void
    {
        if (! Schema::hasTable('shareholders')) {
            return;
        }

        Schema::table('shareholders', function (Blueprint $table) {
            if (! Schema::hasColumn('shareholders', 'first_name')) {
                $table->string('first_name', 100)->nullable()->after('holder_type');
            }
            if (! Schema::hasColumn('shareholders', 'middle_name')) {
                $table->string('middle_name', 100)->nullable()->after('first_name');
            }
            if (! Schema::hasColumn('shareholders', 'last_name')) {
                $table->string('last_name', 100)->nullable()->after('middle_name');
            }
        });
    }

    private function ensureCurrentDividendEntitlements(): void
    {
        $currentColumns = [
            'entitlement_run_id',
            'dividend_declaration_id',
            'register_account_id',
            'share_class_id',
            'eligible_shares',
            'gross_amount',
            'tax_amount',
            'net_amount',
            'is_payable',
            'ineligibility_reason',
        ];

        if (Schema::hasTable('dividend_entitlements') && Schema::hasColumns('dividend_entitlements', $currentColumns)) {
            return;
        }

        foreach (['dividend_payments', 'dividend_entitlements'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new \RuntimeException("Cannot rebuild {$table}: legacy data exists and requires a dedicated data conversion.");
            }
        }

        $hadPaymentsTable = Schema::hasTable('dividend_payments');
        Schema::dropIfExists('dividend_payments');
        Schema::dropIfExists('dividend_entitlements');

        Schema::create('dividend_entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entitlement_run_id')->constrained('dividend_entitlement_runs')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('dividend_declaration_id')->constrained('dividend_declarations')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('register_account_id')->constrained('shareholder_register_accounts')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('share_class_id')->constrained('share_classes')->cascadeOnUpdate()->restrictOnDelete();
            $table->decimal('eligible_shares', 18, 6)->default(0);
            $table->decimal('gross_amount', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('net_amount', 18, 2)->default(0);
            $table->boolean('is_payable')->default(true);
            $table->enum('ineligibility_reason', ['NONE', 'CAUTION', 'NO_ACTIVE_BANK_MANDATE', 'OTHER'])->default('NONE');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['entitlement_run_id', 'register_account_id', 'share_class_id'], 'uq_div_ent_run_acct_class');
            $table->index('dividend_declaration_id', 'idx_div_ent_decl_id');
            $table->index('register_account_id', 'idx_div_ent_reg_acct_id');
            $table->index('share_class_id', 'idx_div_ent_share_class_id');
            $table->index('is_payable');
        });

        if ($hadPaymentsTable) {
            $this->createCurrentDividendPayments();
        }
    }

    private function createCurrentDividendPayments(): void
    {
        Schema::create('dividend_payments', function (Blueprint $table) {
            $table->id();
            $table->string('dividend_payment_no', 64)->nullable();
            $table->foreignId('entitlement_id')->constrained('dividend_entitlements')->cascadeOnUpdate()->restrictOnDelete();
            $table->enum('payout_mode', ['edividend', 'warrant', 'bank_transfer']);
            $table->foreignId('bank_mandate_id')->nullable()->constrained('shareholder_bank_mandates')->cascadeOnUpdate()->restrictOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->string('paid_ref', 64)->nullable();
            $table->enum('status', ['initiated', 'paid', 'failed', 'disputed', 'reissued'])->default('initiated');
            $table->unsignedBigInteger('reissued_from_id')->nullable();
            $table->string('reissue_reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('admin_users')->cascadeOnUpdate()->restrictOnDelete();
            $table->timestamps();

            $table->foreign('reissued_from_id')->references('id')->on('dividend_payments')->cascadeOnUpdate()->nullOnDelete();
            $table->unique('dividend_payment_no', 'uq_dividend_payment_no');
            $table->index('entitlement_id');
            $table->index('status');
        });
    }
};
