<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\CscsUploadController;
use App\Jobs\ProcessCscsImportJob;
use App\Models\AdminUser;
use App\Models\CscsApprovalPolicy;
use App\Models\CscsBatchSnapshot;
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
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
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
            'cscs_batch_snapshots',
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
        $verification = CscsUploadBatch::findOrFail($result['batch_id'])->reconciliation['post_verification'];
        $this->assertSame('VERIFIED', $verification['status']);
        $this->assertNotContains(false, $verification['checks'], true);

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

    public function test_import_can_be_staged_and_processed_by_the_queue_job(): void
    {
        $staged = $this->service->stageImport(
            $this->files(),
            $this->register->id,
            $this->maker->id,
            'Queued CSCS batch',
            'TEST-CSCS-QUEUED'
        );

        $this->assertSame('PROCESSING', $staged['status']);
        $this->assertDatabaseCount('cscs_upload_rows', 0);

        $job = new ProcessCscsImportJob($staged['batch_id']);
        $job->handle($this->service);

        $this->assertSame('DRAFT_REVIEW', CscsUploadBatch::findOrFail($staged['batch_id'])->workflow_status);
        $this->assertDatabaseHas('cscs_upload_rows', [
            'batch_id' => $staged['batch_id'],
            'tran_seq' => '0',
            'resolution_status' => 'READY',
        ]);
    }

    public function test_queued_import_reports_monotonic_row_and_validation_progress(): void
    {
        $staged = $this->service->stageImport(
            $this->files(),
            $this->register->id,
            $this->maker->id,
            'Progress-tracked CSCS batch',
            'TEST-CSCS-PROGRESS'
        );
        $updates = [];

        Event::listen('eloquent.updated: '.CscsUploadBatch::class, function (CscsUploadBatch $batch) use (&$updates): void {
            if (! $batch->wasChanged('summary')) {
                return;
            }

            $summary = $batch->summary ?? [];
            if (isset($summary['processing_percent'], $summary['processing_stage'])) {
                $updates[] = [
                    'percent' => (int) $summary['processing_percent'],
                    'stage' => (string) $summary['processing_stage'],
                ];
            }
        });

        (new ProcessCscsImportJob($staged['batch_id']))->handle($this->service);

        $percentages = array_column($updates, 'percent');
        $sortedPercentages = $percentages;
        sort($sortedPercentages);
        $stages = array_values(array_unique(array_column($updates, 'stage')));
        $summary = CscsUploadBatch::findOrFail($staged['batch_id'])->summary;

        $this->assertGreaterThan(5, count($updates));
        $this->assertSame($sortedPercentages, $percentages, 'Processing percentage must never move backwards.');
        $this->assertContains('PARSING', $stages);
        $this->assertContains('VALIDATING', $stages);
        $this->assertContains('VALIDATING_ROWS', $stages);
        $this->assertContains('VALIDATING_TRANSACTIONS', $stages);
        $this->assertContains('CALCULATING_EFFECTS', $stages);
        $this->assertContains('FINALIZING', $stages);
        $this->assertContains('READY', $stages);
        $this->assertContains(100, $percentages);
        $this->assertSame($summary['source_rows_total'], $summary['source_rows_processed']);
    }

    public function test_processing_batch_can_be_cancelled_before_the_queue_job_starts(): void
    {
        $staged = $this->service->stageImport(
            $this->files(),
            $this->register->id,
            $this->maker->id,
            'Queued batch to cancel',
            'TEST-CSCS-CANCEL-QUEUED'
        );

        $cancelled = $this->service->cancel(
            $staged['batch_id'],
            $this->maker->id,
            'The queued upload was cancelled by its maker.'
        );
        (new ProcessCscsImportJob($staged['batch_id']))->handle($this->service);

        $batch = CscsUploadBatch::findOrFail($staged['batch_id']);
        $this->assertSame('CANCELLED', $cancelled['status']);
        $this->assertSame('CANCELLED', $batch->workflow_status);
        $this->assertSame('CANCELLED', $batch->summary['processing_stage']);
        $this->assertDatabaseCount('cscs_upload_rows', 0);
        $this->assertDatabaseMissing('cscs_workflow_events', [
            'batch_id' => $batch->id,
            'event_type' => 'PARSED',
        ]);
    }

    public function test_active_import_worker_stops_without_overwriting_cancellation(): void
    {
        $staged = $this->service->stageImport(
            $this->files(),
            $this->register->id,
            $this->maker->id,
            'Active batch to cancel',
            'TEST-CSCS-CANCEL-ACTIVE'
        );
        $cancelRequested = false;

        Event::listen('eloquent.updated: '.CscsUploadBatch::class, function (CscsUploadBatch $batch) use ($staged, &$cancelRequested): void {
            if ($cancelRequested || (int) $batch->id !== (int) $staged['batch_id'] || ! $batch->wasChanged('summary')) {
                return;
            }

            if ((int) ($batch->summary['movement_rows'] ?? 0) > 0) {
                $cancelRequested = true;
                $this->service->cancel(
                    $batch->id,
                    $this->maker->id,
                    'The active import was cancelled by its maker.'
                );
            }
        });

        (new ProcessCscsImportJob($staged['batch_id']))->handle($this->service);

        $batch = CscsUploadBatch::findOrFail($staged['batch_id']);
        $movementRows = CscsUploadRow::where('batch_id', $batch->id)->where('file_type', 'movement');
        $this->assertTrue($cancelRequested);
        $this->assertSame('CANCELLED', $batch->workflow_status);
        $this->assertSame('CANCELLED', $batch->summary['processing_stage']);
        $this->assertGreaterThan(0, $movementRows->count());
        $this->assertSame(
            $movementRows->count(),
            (clone $movementRows)->where('resolution_status', 'CANCELLED_WITH_BATCH')->count()
        );
        $this->assertDatabaseMissing('cscs_workflow_events', [
            'batch_id' => $batch->id,
            'event_type' => 'PARSED',
        ]);
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

    public function test_individual_transaction_exposes_balance_and_flag_information(): void
    {
        $balanced = $this->stageBatch();
        $controller = app(CscsUploadController::class);
        $balancedPayload = $controller->transaction($balanced['batch_id'], '2606160005615022')->getData(true)['data'];

        $this->assertSame('BALANCED', $balancedPayload['balance_status']);
        $this->assertFalse($balancedPayload['is_flagged']);
        $this->assertSame([], $balancedPayload['flag_reasons']);

        $listPayload = $controller->transactions(Request::create('/api/cscs/transactions'), $balanced['batch_id'])->getData(true);
        $this->assertSame('BALANCED', $listPayload['data'][0]['balance_status']);
        $this->assertFalse($listPayload['data'][0]['is_flagged']);
        $this->assertSame([], $listPayload['data'][0]['flag_reasons']);
    }

    public function test_individual_unbalanced_transaction_exposes_flag_reasons(): void
    {
        $unbalanced = $this->service->import(
            $this->files('248889', '248888'),
            $this->register->id,
            $this->maker->id,
            'Unbalanced CSCS batch',
            'TEST-CSCS-UNBALANCED-DETAIL'
        );
        $controller = app(CscsUploadController::class);
        $unbalancedPayload = $controller->transaction($unbalanced['batch_id'], '2606160005615022')->getData(true)['data'];

        $this->assertSame('UNBALANCED', $unbalancedPayload['balance_status']);
        $this->assertTrue($unbalancedPayload['is_flagged']);
        $this->assertContains('UNBALANCED_TRANSACTION', $unbalancedPayload['flag_reasons']);
        $this->assertContains('UNBALANCED_QUANTITY', $unbalancedPayload['flag_reasons']);
        $this->assertContains('UNRESOLVED', $unbalancedPayload['flag_reasons']);

        $listPayload = $controller->transactions(Request::create('/api/cscs/transactions'), $unbalanced['batch_id'])->getData(true);
        $this->assertSame('UNBALANCED', $listPayload['data'][0]['balance_status']);
        $this->assertTrue($listPayload['data'][0]['is_flagged']);
        $this->assertContains('UNBALANCED_QUANTITY', $listPayload['data'][0]['flag_reasons']);
    }

    public function test_transactions_support_workspace_search_filters_and_full_batch_counts(): void
    {
        $batch = $this->stageBatch();
        $controller = app(CscsUploadController::class);

        $cases = [
            [['search' => '2606160005615022'], 1],
            [['search' => 'C111111111'], 1],
            [['search' => 'DOES-NOT-EXIST'], 0],
            [['balance_status' => 'balanced'], 1],
            [['balance_status' => 'unbalanced'], 0],
            [['is_flagged' => 'false'], 1],
            [['is_flagged' => 'true'], 0],
            [['resolution_status' => 'ready'], 1],
            [['resolution_status' => 'invalid'], 0],
            [['security_code' => 'stanbic'], 1],
            [['trade_date_from' => '2026-06-16', 'trade_date_to' => '2026-06-16'], 1],
            [['trade_date_from' => '2026-06-17'], 0],
            [['trade_date_to' => '2026-06-15'], 0],
        ];

        foreach ($cases as [$query, $expectedTotal]) {
            $payload = $controller->transactions(
                Request::create('/api/cscs/transactions', 'GET', $query),
                $batch['batch_id']
            )->getData(true);
            $this->assertSame($expectedTotal, $payload['total'], json_encode($query));
            $this->assertSame(1, $payload['meta']['transaction_counts']['all']);
            $this->assertSame(1, $payload['meta']['transaction_counts']['balanced']);
            $this->assertSame(0, $payload['meta']['transaction_counts']['unbalanced']);
            $this->assertSame(0, $payload['meta']['transaction_counts']['flagged']);
        }

        $filtered = $controller->transactions(
            Request::create('/api/cscs/transactions', 'GET', ['balance_status' => 'balanced', 'is_flagged' => 'false']),
            $batch['batch_id']
        )->getData(true);
        $this->assertSame('BALANCED', $filtered['meta']['applied_filters']['balance_status']);
        $this->assertFalse($filtered['meta']['applied_filters']['is_flagged']);
    }

    public function test_balanced_transaction_can_still_be_filtered_as_flagged(): void
    {
        $batch = $this->stageBatch();
        CscsUploadRow::where('batch_id', $batch['batch_id'])->where('file_type', 'movement')->update([
            'resolution_status' => 'UNRESOLVED',
            'exception_code' => 'ACCOUNT_REVIEW_REQUIRED',
            'error_message' => 'Account review is required.',
        ]);

        $payload = app(CscsUploadController::class)->transactions(
            Request::create('/api/cscs/transactions', 'GET', [
                'balance_status' => 'BALANCED',
                'is_flagged' => 'true',
            ]),
            $batch['batch_id']
        )->getData(true);

        $this->assertSame(1, $payload['total']);
        $this->assertSame('BALANCED', $payload['data'][0]['balance_status']);
        $this->assertTrue($payload['data'][0]['is_flagged']);
        $this->assertContains('ACCOUNT_REVIEW_REQUIRED', $payload['data'][0]['flag_reasons']);
        $this->assertContains('UNRESOLVED', $payload['data'][0]['flag_reasons']);
        $this->assertSame([
            'all' => 1,
            'balanced' => 1,
            'unbalanced' => 0,
            'flagged' => 1,
        ], $payload['meta']['transaction_counts']);
    }

    public function test_duplicate_file_is_rejected_before_a_second_batch_is_processed(): void
    {
        $this->stageBatch();

        try {
            $this->service->stageImport($this->files(), $this->register->id, $this->maker->id);
            $this->fail('Expected duplicate-file validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('duplicates a file already staged', $exception->getMessage());
            $this->assertSame('PROCESSING_FAILED', CscsUploadBatch::latest('id')->first()->workflow_status);
        }
    }

    public function test_duplicate_movement_row_is_a_blocking_structural_exception(): void
    {
        $files = $this->files();
        $movement = $files[0]->getContent();
        $firstLine = preg_split('/\r\n|\n|\r/', trim($movement))[0];
        $files[0] = UploadedFile::fake()->createWithContent('STANBICs6-duplicate.txt', $movement.$firstLine."\r\n");

        $result = $this->service->import($files, $this->register->id, $this->maker->id);

        $this->assertDatabaseHas('cscs_upload_rows', [
            'batch_id' => $result['batch_id'],
            'exception_code' => 'DUPLICATE_SOURCE_ROW',
            'resolution_status' => 'INVALID',
        ]);
        $this->assertSame(1, $result['summary']['duplicate_rows']);
        $this->assertGreaterThan(0, $result['summary']['unresolved_exceptions']);
    }

    public function test_submitted_revision_keeps_an_immutable_snapshot_after_a_query(): void
    {
        $batch = $this->stageAndSubmit();
        $snapshot = CscsBatchSnapshot::where('batch_id', $batch->id)->where('revision', 1)->firstOrFail();
        $originalHash = $snapshot->snapshot_hash;
        $originalPayload = $snapshot->payload;

        $this->service->raiseQuery($batch->id, $this->checker->id, 'Please confirm the supplied CSCS instruction reference.');
        $this->service->respondToQuery($batch->id, $this->maker->id, 'The source instruction has now been confirmed.');

        $snapshot->refresh();
        $this->assertSame($originalHash, $snapshot->snapshot_hash);
        $this->assertSame($originalPayload, $snapshot->payload);
        $this->assertSame(2, $batch->fresh()->revision);
    }

    public function test_internally_inconsistent_file_is_rejected_during_detection(): void
    {
        $master = $this->masterLine('C111111111', 'Debit Holder', 'debit@example.test', '08000000001')."\r\n"
            .$this->movementLine('2606160005615022', '0', '-', 'C111111111', '248889')."\r\n";

        $this->expectException(ValidationException::class);
        $this->service->stageImport(
            [UploadedFile::fake()->createWithContent('mixed.txt', $master)],
            $this->register->id,
            $this->maker->id
        );
    }

    public function test_maker_cannot_post_an_independently_approved_batch(): void
    {
        $batch = $this->stageAndSubmit();
        $this->service->approve($batch->id, $this->checker, 'Independent approval completed.');

        $this->expectException(HttpException::class);
        $this->service->post($batch->id, $this->maker, 'Maker must not release their own batch.');
    }

    public function test_cancelling_a_draft_marks_unposted_rows_with_a_final_disposition(): void
    {
        $result = $this->stageBatch();
        $cancelled = $this->service->cancel($result['batch_id'], $this->maker->id, 'A corrected source file will replace this batch.');

        $this->assertSame('CANCELLED', $cancelled['status']);
        $this->assertSame(
            2,
            CscsUploadRow::where('batch_id', $result['batch_id'])->where('resolution_status', 'CANCELLED_WITH_BATCH')->count()
        );
    }

    public function test_non_utf8_source_file_is_rejected_before_storage(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->stageImport(
            [UploadedFile::fake()->createWithContent('binary.txt', "\xFF\xFE\x00\x01")],
            $this->register->id,
            $this->maker->id
        );
    }

    public function test_duplicate_master_identifier_blocks_account_resolution(): void
    {
        $master = $this->masterLine('C444444444', 'Unknown Holder', 'unknown@example.test', '08000000008')."\r\n"
            .$this->masterLine('C444444444', 'Different Holder', 'different@example.test', '08000000009')."\r\n"
            .$this->masterLine('C222222222', 'Credit Holder', 'credit@example.test', '08000000002')."\r\n";
        $movement = $this->movementLine('2606160005615022', '0', '-', 'C444444444', '248889')."\r\n"
            .$this->movementLine('2606160005615022', '11', '+', 'C222222222', '248889')."\r\n";

        $result = $this->service->import([
            UploadedFile::fake()->createWithContent('master-duplicates.txt', $master),
            UploadedFile::fake()->createWithContent('movement.txt', $movement),
        ], $this->register->id, $this->maker->id);

        $this->assertDatabaseHas('cscs_upload_rows', [
            'batch_id' => $result['batch_id'],
            'identifier_value' => 'C444444444',
            'exception_code' => 'AMBIGUOUS_MASTER_RECORD',
        ]);
        $this->assertSame(1, $result['summary']['duplicate_master_identifiers']);
    }

    public function test_new_account_risk_adds_an_oversight_approval_step(): void
    {
        CscsApprovalPolicy::create([
            'name' => 'Risk policy',
            'is_active' => true,
            'checker_roles' => [],
            'additional_approval_roles' => [],
            'checker_can_post' => true,
        ]);
        $master = $this->masterLine('C111111111', 'Debit Holder', 'debit@example.test', '08000000001')."\r\n"
            .$this->masterLine('C333333333', 'New Credit Holder', 'new-credit@example.test', '08000000003')."\r\n";
        $movement = $this->movementLine('2606160005615033', '0', '-', 'C111111111', '100')."\r\n"
            .$this->movementLine('2606160005615033', '1', '+', 'C333333333', '100')."\r\n";
        $result = $this->service->import([
            UploadedFile::fake()->createWithContent('new-account-master.txt', $master),
            UploadedFile::fake()->createWithContent('new-account-movement.txt', $movement),
        ], $this->register->id, $this->maker->id);

        $this->service->reconcile($result['batch_id'], $this->maker->id);
        $submitted = $this->service->submit($result['batch_id'], $this->maker->id);

        $this->assertContains('NEW_ACCOUNT', $submitted['risk_flags']);
        $this->assertCount(2, $submitted['required_approval_steps']);
        $this->assertSame('OVERSIGHT', $submitted['required_approval_steps'][1]['code']);
    }

    public function test_posting_readiness_reports_passes_and_detects_changed_holdings(): void
    {
        $batch = $this->stageAndSubmit();
        $this->service->approve($batch->id, $this->checker, 'Approved after independent review.');

        $ready = $this->service->postingReadiness($batch->id);

        $this->assertTrue($ready['ready']);
        $this->assertSame(2, $ready['summary']['records_to_post']);
        $this->assertTrue($ready['checks']['snapshot_hash_unchanged']['passed']);
        $this->assertTrue($ready['checks']['holdings_current']['passed']);

        SharePosition::where('sra_id', $this->debitAccount->id)->update(['quantity' => '299999.000000']);
        $stale = $this->service->postingReadiness($batch->id);

        $this->assertFalse($stale['ready']);
        $this->assertFalse($stale['checks']['holdings_current']['passed']);
    }

    public function test_exception_filter_accepts_resolution_status_alias(): void
    {
        $result = $this->stageBatch();
        CscsUploadRow::where('batch_id', $result['batch_id'])->where('tran_seq', '0')->update([
            'resolution_status' => 'UNRESOLVED',
            'exception_code' => 'ACCOUNT_REVIEW_REQUIRED',
        ]);

        $payload = app(CscsUploadController::class)->exceptions(
            Request::create('/api/cscs/exceptions', 'GET', ['resolution_status' => 'unresolved']),
            $result['batch_id']
        )->getData(true);

        $this->assertSame(1, $payload['total']);
        $this->assertSame('UNRESOLVED', $payload['data'][0]['resolution_status']);
    }

    public function test_review_comments_are_stored_and_returned_with_workflow_notes(): void
    {
        $result = $this->stageBatch();
        $request = Request::create('/api/cscs/comments', 'POST', ['comment' => 'Please verify the attached CSCS instruction reference.']);
        $request->setUserResolver(fn () => $this->checker);

        $response = app(CscsUploadController::class)->storeComment($request, $result['batch_id']);
        $comments = app(CscsUploadController::class)->comments(
            Request::create('/api/cscs/comments', 'GET'),
            $result['batch_id']
        )->getData(true);

        $this->assertSame(201, $response->status());
        $this->assertSame('COMMENT_ADDED', $response->getData(true)['data']['event_type']);
        $this->assertGreaterThanOrEqual(1, $comments['total']);
        $this->assertDatabaseHas('cscs_workflow_events', [
            'batch_id' => $result['batch_id'],
            'event_type' => 'COMMENT_ADDED',
            'actor_id' => $this->checker->id,
        ]);
    }

    public function test_posted_batch_exposes_verification_summary_and_report_downloads(): void
    {
        $batch = $this->stageAndSubmit();
        $this->service->approve($batch->id, $this->checker, 'Approved after independent review.');
        $this->service->post($batch->id, $this->poster, 'Authorized for posting.');
        $controller = app(CscsUploadController::class);

        $summary = $controller->verificationSummary($batch->id)->getData(true)['data'];
        $this->assertSame('VERIFIED', $summary['verification_status']);
        $this->assertSame(2, $summary['metrics']['records_posted']);
        $this->assertSame(1, $summary['metrics']['transaction_groups_posted']);

        $pdf = $controller->export(
            Request::create('/api/cscs/export', 'GET', ['type' => 'audit', 'format' => 'pdf']),
            $batch->id
        );
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->assertStringContainsString('.pdf', (string) $pdf->headers->get('content-disposition'));

        $excel = $controller->export(
            Request::create('/api/cscs/export', 'GET', ['type' => 'activity', 'format' => 'xls']),
            $batch->id
        );
        $this->assertStringContainsString('.xls', (string) $excel->headers->get('content-disposition'));
        $this->assertGreaterThan(0, $excel->getFile()->getSize());
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
        Schema::create('cscs_batch_snapshots', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('batch_id');
            $t->unsignedInteger('revision');
            $t->string('snapshot_hash');
            $t->json('payload');
            $t->json('reconciliation');
            $t->json('risk_flags')->nullable();
            $t->json('source_files');
            $t->unsignedBigInteger('submitted_by')->nullable();
            $t->timestamp('submitted_at');
            $t->timestamps();
            $t->unique(['batch_id', 'revision']);
        });
    }
}
