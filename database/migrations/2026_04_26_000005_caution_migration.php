<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('share_classes', function (Blueprint $table) {
            if (!Schema::hasColumn('share_classes', 'is_caution_class')) {
                $table->boolean('is_caution_class')
                      ->default(false)
                      ->after('description')
                      ->comment('True only for the system-managed Caution share class per register');
            }
        });

        Schema::table('share_classes', function (Blueprint $table) {
            $table->index(['register_id', 'is_caution_class'], 'idx_sc_register_caution');
        });


        Schema::create('shareholder_cautions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shareholder_id')
                  ->constrained('shareholders')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete();

            // The specific SRA being cautioned (register-scoped)
            $table->foreignId('sra_id')
                  ->constrained('shareholder_register_accounts')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete()
                  ->comment('The shareholder_register_account being cautioned');

            // The caution share class for this register
            $table->foreignId('caution_share_class_id')
                  ->constrained('share_classes')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete()
                  ->comment('The is_caution_class=true share class of the register');

            $table->enum('scope', ['global', 'company'])->default('company');

            // NULL = global caution. Populated = company-level.
            $table->foreignId('company_id')
                  ->nullable()
                  ->constrained('companies')
                  ->cascadeOnUpdate()
                  ->nullOnDelete();

            $table->enum('caution_type', ['regulatory', 'legal', 'operational']);

            $table->enum('instruction_source', ['sec', 'court', 'exchange', 'bank', 'internal']);

            $table->string('reason', 500);

            $table->date('effective_date');

            // Removal fields — populated when caution is lifted (NEVER delete the row)
            $table->timestamp('removed_at')->nullable();
            $table->string('removal_reason', 500)->nullable();
            $table->foreignId('removed_by')
                  ->nullable()
                  ->constrained('admin_users')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete();

            $table->foreignId('created_by')
                  ->constrained('admin_users')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete();

            $table->timestamps();

            // Performance indexes
            $table->index(['shareholder_id', 'removed_at'],  'idx_caution_shareholder_active');
            $table->index(['sra_id',         'removed_at'],  'idx_caution_sra_active');
            $table->index(['company_id',     'removed_at'],  'idx_caution_company_active');
            $table->index('caution_type');
            $table->index('scope');
            $table->index('effective_date');
        });

        // shareholder_caution_logs (immutable audit) 
        Schema::create('shareholder_caution_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('caution_id')
                  ->constrained('shareholder_cautions')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete();

            $table->foreignId('shareholder_id')
                  ->constrained('shareholders')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete();

            $table->foreignId('sra_id')
                  ->constrained('shareholder_register_accounts')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete();

            $table->enum('action', ['applied', 'removed']);
            $table->enum('caution_type', ['regulatory', 'legal', 'operational']);
            $table->enum('instruction_source', ['sec', 'court', 'exchange', 'bank', 'internal']);
            $table->string('reason', 500);
            $table->enum('scope', ['global', 'company']);

            $table->foreignId('company_id')
                  ->nullable()
                  ->constrained('companies')
                  ->cascadeOnUpdate()
                  ->nullOnDelete();

            $table->foreignId('caution_share_class_id')
                  ->constrained('share_classes')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete();

            $table->foreignId('actor_id')
                  ->constrained('admin_users')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['shareholder_id', 'created_at'], 'idx_clog_shareholder_time');
            $table->index(['caution_id',     'action'],     'idx_clog_caution_action');
            $table->index('sra_id',                         'idx_clog_sra');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shareholder_caution_logs');
        Schema::dropIfExists('shareholder_cautions');

        Schema::table('share_classes', function (Blueprint $table) {
            if (Schema::hasIndex('share_classes', 'idx_sc_register_caution')) {
                $table->dropIndex('idx_sc_register_caution');
            }
            if (Schema::hasColumn('share_classes', 'is_caution_class')) {
                $table->dropColumn('is_caution_class');
            }
        });
    }
};