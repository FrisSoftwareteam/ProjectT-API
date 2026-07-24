<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cscs_upload_batches', function (Blueprint $table) {
            $table->string('workflow_status', 40)->default('PROCESSING')->after('status')->index();
            $table->unsignedInteger('revision')->default(1)->after('workflow_status');
            $table->string('batch_type', 20)->default('STANDARD')->after('revision');
            $table->foreignId('source_batch_id')->nullable()->after('batch_type')->constrained('cscs_upload_batches')->nullOnDelete();
            $table->string('business_reference', 100)->nullable()->after('revision')->index();
            $table->string('description', 500)->nullable()->after('business_reference');
            $table->string('snapshot_hash', 64)->nullable()->after('description');
            $table->json('reconciliation')->nullable()->after('summary');
            $table->json('risk_flags')->nullable()->after('reconciliation');
            $table->json('required_approval_steps')->nullable()->after('risk_flags');
            $table->unsignedTinyInteger('current_approval_step')->nullable()->after('required_approval_steps');
            $table->foreignId('reconciled_by')->nullable()->after('current_approval_step')->constrained('admin_users')->nullOnDelete();
            $table->timestamp('reconciled_at')->nullable()->after('reconciled_by');
            $table->foreignId('submitted_by')->nullable()->after('reconciled_at')->constrained('admin_users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable()->after('submitted_by');
            $table->foreignId('approved_by')->nullable()->after('submitted_at')->constrained('admin_users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->foreignId('rejected_by')->nullable()->after('approved_at')->constrained('admin_users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable()->after('rejected_by');
            $table->foreignId('posted_by')->nullable()->after('rejected_at')->constrained('admin_users')->nullOnDelete();
            $table->timestamp('posting_started_at')->nullable()->after('posted_by');
            $table->timestamp('posted_at')->nullable()->after('posting_started_at');
            $table->text('failure_reason')->nullable()->after('posted_at');
        });

        Schema::table('cscs_upload_rows', function (Blueprint $table) {
            $table->string('transaction_group_key', 64)->nullable()->after('tran_seq')->index();
            $table->string('resolution_status', 40)->default('UNRESOLVED')->after('status')->index();
            $table->string('exception_code', 60)->nullable()->after('resolution_status')->index();
            $table->string('match_method', 50)->nullable()->after('matched_by');
            $table->foreignId('proposed_sra_id')->nullable()->after('sra_id')->constrained('shareholder_register_accounts')->nullOnDelete();
            $table->foreignId('proposed_share_class_id')->nullable()->after('share_class_id')->constrained('share_classes')->nullOnDelete();
            $table->decimal('proposed_before_qty', 28, 6)->nullable()->after('after_qty');
            $table->decimal('proposed_delta_qty', 28, 6)->nullable()->after('proposed_before_qty');
            $table->decimal('proposed_after_qty', 28, 6)->nullable()->after('proposed_delta_qty');
            $table->decimal('actual_before_qty', 28, 6)->nullable()->after('proposed_after_qty');
            $table->decimal('actual_after_qty', 28, 6)->nullable()->after('actual_before_qty');
            $table->string('replay_key', 64)->nullable()->after('fingerprint')->index();
            $table->foreignId('resolved_by')->nullable()->after('extra_details')->constrained('admin_users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable()->after('resolved_by');
            $table->text('resolution_reason')->nullable()->after('resolved_at');
        });

        Schema::create('cscs_security_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('security_code', 20);
            $table->foreignId('register_id')->constrained('registers')->restrictOnDelete();
            $table->foreignId('share_class_id')->constrained('share_classes')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();
            $table->unique('security_code');
            $table->index(['register_id', 'share_class_id', 'is_active'], 'idx_cscs_security_mapping');
        });

        Schema::create('cscs_approval_policies', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->default('Default CSCS policy');
            $table->boolean('is_active')->default(true);
            $table->json('checker_roles')->nullable();
            $table->decimal('additional_approval_quantity', 28, 6)->nullable();
            $table->json('additional_approval_roles')->nullable();
            $table->boolean('checker_can_post')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('cscs_approval_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('cscs_upload_batches')->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->unsignedTinyInteger('step_no')->nullable();
            $table->string('role_code', 100)->nullable();
            $table->string('decision', 30);
            $table->foreignId('actor_id')->constrained('admin_users')->restrictOnDelete();
            $table->text('comment')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('acted_at')->useCurrent();
            $table->timestamps();
            $table->index(['batch_id', 'revision', 'step_no'], 'idx_cscs_approval_step');
        });

        Schema::create('cscs_workflow_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('cscs_upload_batches')->cascadeOnDelete();
            $table->string('event_type', 50);
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40)->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->text('comment')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['batch_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cscs_workflow_events');
        Schema::dropIfExists('cscs_approval_actions');
        Schema::dropIfExists('cscs_approval_policies');
        Schema::dropIfExists('cscs_security_mappings');

        Schema::table('cscs_upload_rows', function (Blueprint $table) {
            $table->dropIndex(['transaction_group_key']);
            $table->dropIndex(['resolution_status']);
            $table->dropIndex(['exception_code']);
            $table->dropIndex(['replay_key']);
            $table->dropForeign(['proposed_sra_id']);
            $table->dropForeign(['proposed_share_class_id']);
            $table->dropForeign(['resolved_by']);
            $table->dropColumn([
                'transaction_group_key', 'resolution_status', 'exception_code', 'match_method',
                'proposed_sra_id', 'proposed_share_class_id', 'proposed_before_qty',
                'proposed_delta_qty', 'proposed_after_qty', 'actual_before_qty',
                'actual_after_qty', 'replay_key', 'resolved_by', 'resolved_at', 'resolution_reason',
            ]);
        });

        Schema::table('cscs_upload_batches', function (Blueprint $table) {
            $table->dropIndex(['workflow_status']);
            $table->dropIndex(['business_reference']);
            foreach (['reconciled_by', 'submitted_by', 'approved_by', 'rejected_by', 'posted_by'] as $foreign) {
                $table->dropForeign([$foreign]);
            }
            $table->dropForeign(['source_batch_id']);
            $table->dropColumn([
                'workflow_status', 'revision', 'batch_type', 'source_batch_id', 'business_reference', 'description', 'snapshot_hash',
                'reconciliation', 'risk_flags', 'required_approval_steps', 'current_approval_step',
                'reconciled_by', 'reconciled_at', 'submitted_by', 'submitted_at', 'approved_by',
                'approved_at', 'rejected_by', 'rejected_at', 'posted_by', 'posting_started_at',
                'posted_at', 'failure_reason',
            ]);
        });
    }
};
