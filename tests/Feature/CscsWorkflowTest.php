<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\CscsSecurityMapping;
use App\Models\CscsUploadBatch;
use App\Models\CscsUploadRow;
use App\Models\Register;
use App\Models\ShareClass;
use App\Models\Shareholder;
use App\Models\ShareholderRegisterAccount;
use App\Models\SharePosition;
use App\Services\CscsImportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CscsWorkflowTest extends TestCase
{
    private CscsImportService $service;

    private AdminUser $maker;

    private AdminUser $checker;

    private AdminUser $poster;

    private Register $register;

    private ShareClass $shareClass;

    private ShareholderRegisterAccount $debitAccount;

    private ShareholderRegisterAccount $creditAccount;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->createSchema();
        $this->service = app(CscsImportService::class);
        $this->maker = $this->admin('maker@example.test');
        $this->checker = $this->admin('checker@example.test');
        $this->poster = $this->admin('poster@example.test');
        $this->register = Register::create(['company_id' => 1, 'register_code' => 'STANBIC', 'name' => 'Stanbic Register', 'status' => 'active']);
        $this->shareClass = ShareClass::create(['register_id' => $this->register->id, 'class_code' => 'ORD', 'name' => 'Ordinary Shares']);
        CscsSecurityMapping::create(['security_code' => 'STANBIC', 'register_id' => $this->register->id, 'share_class_id' => $this->shareClass->id, 'is_active' => true]);
        $this->debitAccount = $this->account('C111111111', 'debit@example.test', '08000000001', '300000.000000');
        $this->creditAccount = $this->account('C222222222', 'credit@example.test', '08000000002', '1000.000000');
    }

    protected function tearDown(): void
    {
        foreach (array_reverse([
            'admin_users', 'registers', 'share_classes', 'shareholders', 'shareholder_register_accounts',
            'sra_external_identifiers', 'share_positions', 'share_transactions', 'cscs_upload_batches',
            'cscs_upload_rows', 'cscs_security_mappings', 'cscs_approval_policies',
            'cscs_approval_actions', 'cscs_workflow_events',
        ]) as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_upload_is_staged_sequence_zero_is_retained_and_approved_batch_posts_atomically(): void
    {
        $result = $this->stageBatch();

        $this->assertSame('DRAFT_REVIEW', $result['status']);
        $this->assertSame('300000.000000', SharePosition::where('sra_id', $this->debitAccount->id)->value('quantity'));
        $this->assertSame('1000.000000', SharePosition::where('sra_id', $this->creditAccount->id)->value('quantity'));
        $this->assertDatabaseHas('cscs_upload_rows', ['batch_id' => $result['batch_id'], 'tran_seq' => '0', 'resolution_status' => 'READY']);
        $this->assertDatabaseCount('share_transactions', 0);

        $reconciled = $this->service->reconcile($result['batch_id'], $this->maker->id, 'Balanced sample reviewed');
        $this->assertSame('RECONCILED', $reconciled['status']);
        $this->assertSame('248889.000000', $reconciled['summary']['total_debit']);
        $this->assertSame('248889.000000', $reconciled['summary']['total_credit']);
        $this->assertSame('0.000000', $reconciled['summary']['net_movement']);

        $this->service->submit($result['batch_id'], $this->maker->id, 'Submit balanced batch');
        $approved = $this->service->approve($result['batch_id'], $this->checker, 'Independent review complete');
        $this->assertSame('APPROVED_AWAITING_POST', $approved['status']);
        $posted = $this->service->post($result['batch_id'], $this->poster, 'Authorized release');

        $this->assertSame('POSTED', $posted['status']);
        $this->assertSame('51111.000000', SharePosition::where('sra_id', $this->debitAccount->id)->value('quantity'));
        $this->assertSame('249889.000000', SharePosition::where('sra_id', $this->creditAccount->id)->value('quantity'));
        $this->assertDatabaseCount('share_transactions', 2);
        $this->assertSame(2, CscsUploadRow::where('batch_id', $result['batch_id'])->where('status', 'posted')->count());
        $this->assertCount(2, $this->service->accountEffects($result['batch_id']));

        $reversal = $this->service->createReversal(
            $result['batch_id'],
            $this->maker->id,
            'Correcting the posted source advice through an approved reversal',
            '2026-07-22'
        );
        $this->assertSame('DRAFT_REVIEW', $reversal['status']);
        $this->assertDatabaseHas('cscs_upload_batches', ['id' => $reversal['batch_id'], 'batch_type' => 'REVERSAL', 'source_batch_id' => $result['batch_id']]);
        $this->assertDatabaseHas('cscs_upload_rows', ['batch_id' => $reversal['batch_id'], 'tran_seq' => '0', 'sign' => '+']);
        $this->assertDatabaseCount('share_transactions', 2);
    }

    public function test_maker_cannot_approve_their_own_batch(): void
    {
        $batch = $this->stageAndSubmit();

        $this->expectException(HttpException::class);
        $this->service->approve($batch->id, $this->maker, 'Self approval must fail');
    }

    public function test_changed_holding_marks_approved_batch_stale_and_does_not_post(): void
    {
        $batch = $this->stageAndSubmit();
        $this->service->approve($batch->id, $this->checker, 'Approved snapshot');
        SharePosition::where('sra_id', $this->debitAccount->id)->update(['quantity' => '299999.000000']);

        try {
            $this->service->post($batch->id, $this->poster, 'Attempt stale posting');
            $this->fail('Expected stale validation failure.');
        } catch (ValidationException) {
            $this->assertSame('STALE', $batch->fresh()->workflow_status);
            $this->assertDatabaseCount('share_transactions', 0);
            $this->assertSame(0, CscsUploadRow::where('batch_id', $batch->id)->where('status', 'posted')->count());
        }
    }

    public function test_unbalanced_transaction_group_cannot_be_reconciled(): void
    {
        $files = $this->files('248889', '248888');
        $result = $this->service->import($files, $this->register->id, $this->maker->id);

        $this->expectException(ValidationException::class);
        try {
            $this->service->reconcile($result['batch_id'], $this->maker->id);
        } finally {
            $this->assertSame(2, CscsUploadRow::where('batch_id', $result['batch_id'])->where('exception_code', 'UNBALANCED_QUANTITY')->count());
            $this->assertSame('DRAFT_REVIEW', CscsUploadBatch::find($result['batch_id'])->workflow_status);
        }
    }

    private function stageAndSubmit(): CscsUploadBatch
    {
        $result = $this->stageBatch();
        $this->service->reconcile($result['batch_id'], $this->maker->id);
        $this->service->submit($result['batch_id'], $this->maker->id);

        return CscsUploadBatch::findOrFail($result['batch_id']);
    }

    private function stageBatch(): array
    {
        return $this->service->import($this->files(), $this->register->id, $this->maker->id, 'Sample CSCS batch', 'TEST-CSCS-1');
    }

    /** @return array<int, UploadedFile> */
    private function files(string $debit = '248889', string $credit = '248889'): array
    {
        $master = $this->masterLine('C111111111', 'Debit Holder', 'debit@example.test', '08000000001')."\r\n"
            .$this->masterLine('C222222222', 'Credit Holder', 'credit@example.test', '08000000002')."\r\n";
        $movement = $this->movementLine('2606160005615022', '0', '-', 'C111111111', $debit)."\r\n"
            .$this->movementLine('2606160005615022', '11', '+', 'C222222222', $credit)."\r\n";

        // Movement first proves file processing does not depend on multipart order.
        return [
            UploadedFile::fake()->createWithContent('STANBICs6.txt', $movement),
            UploadedFile::fake()->createWithContent('STANBICmast.txt', $master),
        ];
    }

    private function movementLine(string $transaction, string $sequence, string $sign, string $identifier, string $quantity): string
    {
        $line = str_pad($transaction, 16)
            .' '.str_pad($sequence, 6)
            .'20260616'
            .str_pad('STANBIC', 21)
            .str_pad($quantity, 18)
            .'  '.'0'.$sign.str_pad($identifier, 40);
        $this->assertSame(114, strlen($line));

        return $line;
    }

    private function masterLine(string $identifier, string $name, string $email, string $phone): string
    {
        $line = str_repeat(' ', 393);
        $line = substr_replace($line, str_pad($identifier, 12), 0, 12);
        $line = substr_replace($line, str_pad($name, 80), 12, 80);
        $line = substr_replace($line, str_pad($email, 39), 273, 39);
        $line = substr_replace($line, str_pad($phone, 14), 313, 14);

        return $line;
    }

    private function admin(string $email): AdminUser
    {
        return AdminUser::create(['email' => $email, 'first_name' => 'Test', 'last_name' => 'User', 'is_active' => true]);
    }

    private function account(string $chn, string $email, string $phone, string $quantity): ShareholderRegisterAccount
    {
        $shareholder = Shareholder::create([
            'account_no' => str_pad((string) Shareholder::count(), 10, '0', STR_PAD_LEFT),
            'holder_type' => 'individual',
            'first_name' => 'Test',
            'last_name' => 'Holder',
            'full_name' => 'Test Holder',
            'email' => $email,
            'phone' => $phone,
            'status' => 'active',
        ]);
        $sra = ShareholderRegisterAccount::create(['shareholder_id' => $shareholder->id, 'register_id' => $this->register->id, 'shareholder_no' => 'SRA-'.$shareholder->id, 'chn' => $chn, 'status' => 'active']);
        SharePosition::create(['sra_id' => $sra->id, 'share_class_id' => $this->shareClass->id, 'quantity' => $quantity, 'holding_mode' => 'demat']);

        return $sra;
    }

    private function createSchema(): void
    {
        Schema::create('admin_users', function (Blueprint $t) {
            $t->id();
            $t->string('email')->unique();
            $t->string('first_name');
            $t->string('last_name');
            $t->boolean('is_active');
            $t->timestamps();
        });
        Schema::create('registers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('register_code');
            $t->string('name');
            $t->string('status');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('share_classes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('register_id');
            $t->string('class_code');
            $t->string('name')->nullable();
            $t->string('currency')->default('NGN');
            $t->decimal('par_value', 18, 6)->default(0);
            $t->decimal('withholding_tax_rate', 8, 4)->nullable();
            $t->boolean('is_caution_class')->default(false);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('shareholders', function (Blueprint $t) {
            $t->id();
            $t->string('account_no')->unique();
            $t->string('holder_type');
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('middle_name')->nullable();
            $t->string('full_name');
            $t->string('email')->unique();
            $t->string('phone')->unique();
            $t->string('status');
            $t->timestamps();
        });
        Schema::create('shareholder_register_accounts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('shareholder_id');
            $t->unsignedBigInteger('register_id');
            $t->string('shareholder_no')->nullable();
            $t->string('chn')->nullable();
            $t->string('cscs_account_no')->nullable();
            $t->string('status');
            $t->timestamps();
        });
        Schema::create('sra_external_identifiers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('sra_id');
            $t->string('identifier_type');
            $t->string('identifier_value');
            $t->string('source');
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });
        Schema::create('share_positions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('sra_id');
            $t->unsignedBigInteger('share_class_id');
            $t->decimal('quantity', 28, 6);
            $t->string('holding_mode');
            $t->timestamp('last_updated_at')->nullable();
            $t->timestamps();
            $t->unique(['sra_id', 'share_class_id']);
        });
        Schema::create('share_transactions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('sra_id');
            $t->unsignedBigInteger('share_class_id');
            $t->string('tx_type');
            $t->decimal('quantity', 28, 6);
            $t->string('tx_ref')->nullable();
            $t->timestamp('tx_date');
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('cscs_upload_batches', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('uploaded_by')->nullable();
            $t->unsignedBigInteger('register_id')->nullable();
            $t->string('status');
            $t->string('workflow_status');
            $t->unsignedInteger('revision')->default(1);
            $t->string('batch_type')->default('STANDARD');
            $t->unsignedBigInteger('source_batch_id')->nullable();
            $t->string('business_reference')->nullable();
            $t->string('description')->nullable();
            $t->string('snapshot_hash')->nullable();
            $t->json('uploaded_files');
            $t->json('summary')->nullable();
            $t->json('reconciliation')->nullable();
            $t->json('risk_flags')->nullable();
            $t->json('required_approval_steps')->nullable();
            $t->unsignedTinyInteger('current_approval_step')->nullable();
            $t->unsignedBigInteger('reconciled_by')->nullable();
            $t->timestamp('reconciled_at')->nullable();
            $t->unsignedBigInteger('submitted_by')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->unsignedBigInteger('rejected_by')->nullable();
            $t->timestamp('rejected_at')->nullable();
            $t->unsignedBigInteger('posted_by')->nullable();
            $t->timestamp('posting_started_at')->nullable();
            $t->timestamp('posted_at')->nullable();
            $t->text('failure_reason')->nullable();
            $t->timestamps();
        });
        Schema::create('cscs_upload_rows', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('batch_id');
            $t->string('file_type');
            $t->string('source_filename');
            $t->unsignedInteger('row_number');
            $t->string('tran_no')->nullable();
            $t->string('tran_seq')->nullable();
            $t->string('transaction_group_key')->nullable();
            $t->date('trade_date')->nullable();
            $t->string('sec_code')->nullable();
            $t->string('identifier_type')->nullable();
            $t->string('identifier_value')->nullable();
            $t->string('sign')->nullable();
            $t->decimal('volume', 28, 6)->nullable();
            $t->string('status');
            $t->string('resolution_status');
            $t->string('exception_code')->nullable();
            $t->string('matched_by')->nullable();
            $t->string('match_method')->nullable();
            $t->text('error_message')->nullable();
            $t->decimal('before_qty', 28, 6)->nullable();
            $t->decimal('delta_qty', 28, 6)->nullable();
            $t->decimal('after_qty', 28, 6)->nullable();
            $t->decimal('proposed_before_qty', 28, 6)->nullable();
            $t->decimal('proposed_delta_qty', 28, 6)->nullable();
            $t->decimal('proposed_after_qty', 28, 6)->nullable();
            $t->decimal('actual_before_qty', 28, 6)->nullable();
            $t->decimal('actual_after_qty', 28, 6)->nullable();
            $t->unsignedBigInteger('shareholder_id')->nullable();
            $t->unsignedBigInteger('sra_id')->nullable();
            $t->unsignedBigInteger('proposed_sra_id')->nullable();
            $t->unsignedBigInteger('share_class_id')->nullable();
            $t->unsignedBigInteger('proposed_share_class_id')->nullable();
            $t->unsignedBigInteger('share_transaction_id')->nullable();
            $t->string('fingerprint')->nullable()->unique();
            $t->string('replay_key')->nullable();
            $t->text('raw_line');
            $t->json('extra_details')->nullable();
            $t->unsignedBigInteger('resolved_by')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->text('resolution_reason')->nullable();
            $t->timestamps();
        });
        Schema::create('cscs_security_mappings', function (Blueprint $t) {
            $t->id();
            $t->string('security_code')->unique();
            $t->unsignedBigInteger('register_id');
            $t->unsignedBigInteger('share_class_id');
            $t->boolean('is_active');
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
        });
        Schema::create('cscs_approval_policies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->boolean('is_active');
            $t->json('checker_roles')->nullable();
            $t->decimal('additional_approval_quantity', 28, 6)->nullable();
            $t->json('additional_approval_roles')->nullable();
            $t->boolean('checker_can_post');
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
        });
        Schema::create('cscs_approval_actions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('batch_id');
            $t->unsignedInteger('revision');
            $t->unsignedTinyInteger('step_no')->nullable();
            $t->string('role_code')->nullable();
            $t->string('decision');
            $t->unsignedBigInteger('actor_id');
            $t->text('comment')->nullable();
            $t->json('context')->nullable();
            $t->timestamp('acted_at');
            $t->timestamps();
        });
        Schema::create('cscs_workflow_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('batch_id');
            $t->string('event_type');
            $t->string('from_status')->nullable();
            $t->string('to_status')->nullable();
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->text('comment')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamp('created_at');
        });
    }
}
