<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dividend_declarations', function (Blueprint $table) {
            $table->string('dividend_declaration_no', 100)->nullable()->after('id');
            $table->enum('initiator', ['operations', 'mutual_funds'])->nullable()->after('description');
            $table->foreignId('supplementary_of_declaration_id')
                ->nullable()
                ->after('register_id')
                ->constrained('dividend_declarations')
                ->cascadeOnUpdate()
                ->nullOnDelete();
            $table->unsignedTinyInteger('current_approval_step')->nullable()->after('status');
            $table->boolean('is_frozen')->default(false)->after('eligible_shareholders_count');
            $table->timestamp('live_at')->nullable()->after('approved_at');
            $table->timestamp('archived_at')->nullable()->after('live_at');
            $table->string('archived_from_status', 30)->nullable()->after('archived_at');
        });

        // Expand status enum to support query/live/archive lifecycle.
        DB::statement("ALTER TABLE dividend_declarations MODIFY COLUMN status ENUM('DRAFT','SUBMITTED','VERIFIED','QUERY_RAISED','APPROVED','LIVE','REJECTED','ARCHIVED') NOT NULL DEFAULT 'DRAFT'");

        Schema::table('dividend_declarations', function (Blueprint $table) {
            $table->unique('dividend_declaration_no', 'uq_dividend_declaration_no');
            $table->index('current_approval_step', 'idx_div_current_step');
            $table->index('initiator', 'idx_div_initiator');
        });

        Schema::table('dividend_workflow_events', function () {
            DB::statement("ALTER TABLE dividend_workflow_events MODIFY COLUMN event_type ENUM('CREATED','UPDATED','SUBMITTED','VERIFIED','STEP_APPROVED','APPROVED','QUERY_RAISED','QUERY_RESPONDED','REJECTED','GO_LIVE','EXPORTED','EXPORTED_ENTITLEMENTS','EXPORTED_PAYMENTS','EXPORTED_SUMMARY','PAYMENT_REISSUED','SUPPLEMENTARY_CREATED','ARCHIVED','RESUMED','DELEGATION_ASSIGNED') NOT NULL");
        });
    }

    public function down(): void
    {
        Schema::table('dividend_workflow_events', function () {
            DB::statement("ALTER TABLE dividend_workflow_events MODIFY COLUMN event_type ENUM('CREATED','UPDATED','SUBMITTED','VERIFIED','APPROVED','REJECTED','EXPORTED','PAYMENT_REISSUED') NOT NULL");
        });

        Schema::table('dividend_declarations', function (Blueprint $table) {
            $table->dropIndex('idx_div_initiator');
            $table->dropIndex('idx_div_current_step');
            $table->dropUnique('uq_dividend_declaration_no');
            $table->dropConstrainedForeignId('supplementary_of_declaration_id');
            $table->dropColumn([
                'dividend_declaration_no',
                'initiator',
                'current_approval_step',
                'is_frozen',
                'live_at',
                'archived_at',
                'archived_from_status',
            ]);
        });

        DB::statement("ALTER TABLE dividend_declarations MODIFY COLUMN status ENUM('DRAFT','SUBMITTED','VERIFIED','APPROVED','REJECTED') NOT NULL DEFAULT 'DRAFT'");
    }
};
