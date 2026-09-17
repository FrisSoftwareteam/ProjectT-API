<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Admin\ShareholderController;
use App\Http\Controllers\Api\CscsUploadController;
use App\Http\Middleware\CscsApiContract;
use App\Jobs\PostCscsBatchJob;
use App\Jobs\ProcessCscsImportJob;
use App\Models\AdminUser;
use App\Models\Company;
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
use App\Services\AdminNotificationService;
use App\Services\CscsImportService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
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
        $company = Company::create(['issuer_code' => 'STANBIC', 'name' => 'Stanbic Test PLC', 'status' => 'active']);
        $this->register = Register::create(['company_id' => $company->id, 'register_code' => 'STANBIC', 'name' => 'Stanbic Register', 'status' => 'active']);
        $this->shareClass = ShareClass::create(['register_id' => $this->register->id, 'class_code' => 'ORD', 'name' => 'Ordinary Shares']);
        CscsSecurityMapping::create(['security_code' => 'STANBIC', 'register_id' => $this->register->id, 'share_class_id' => $this->shareClass->id, 'is_active' => true]);
        $this->debitAccount = $this->account('C111111111', 'debit@example.test', '08000000001', '300000.000000');
        $this->creditAccount = $this->account('C222222222', 'credit@example.test', '08000000002', '1000.000000');
    }

    protected function tearDown(): void
    {
        foreach (array_reverse([
            'admin_users', 'companies', 'registers', 'share_classes', 'shareholders', 'shareholder_cautions', 'shareholder_register_accounts',
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

        $this->service->confirmFinancialPreview($result['batch_id'], $this->maker->id, CscsUploadBatch::findOrFail($result['batch_id'])->snapshot_hash);
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
        $this->assertSame('248889.000000', $balancedPayload['quantity']);
        $this->assertSame('LOW', $balancedPayload['risk']['level']);
        $this->assertSame('READY', $balancedPayload['resolution']['status']);
        $this->assertFalse($balancedPayload['resolution']['action_required']);
        $this->assertSame($this->debitAccount->id, $balancedPayload['debit_account']['register_account_id']);
        $this->assertSame('C111111111', $balancedPayload['debit_account']['chn']);
        $this->assertSame($this->creditAccount->id, $balancedPayload['credit_account']['register_account_id']);

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
        $this->assertTrue($unbalancedPayload['quantity_mismatch']);
        $this->assertSame('HIGH', $unbalancedPayload['risk']['level']);
        $this->assertTrue($unbalancedPayload['resolution']['action_required']);

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

    public function test_preview_exposes_ui_ready_review_objects(): void
    {
        $batch = $this->stageBatch();
        $payload = app(CscsUploadController::class)->preview(
            Request::create('/api/cscs/preview', 'GET'),
            $batch['batch_id']
        )->getData(true)['data'];

        $this->assertSame('Stanbic Test PLC', $payload['batch']['register']['company']['name']);
        $this->assertSame('maker@example.test', $payload['batch']['uploader']['email']);
        $this->assertCount(2, $payload['account_effects']);
        $this->assertSame('Test Holder', $payload['account_effects'][0]['shareholder_name']);
        $this->assertSame('ORD', $payload['account_effects'][0]['share_class_code']);
        $this->assertSame(2, $payload['review_summary']['affected_accounts']);
        $this->assertSame(1, $payload['review_summary']['security_mappings']['verified']);
        $this->assertTrue($payload['review_summary']['checks']['security_mappings_complete']);
        $this->assertArrayHasKey('approval_timeline', $payload);
        $this->assertArrayHasKey('comments', $payload);
    }

    public function test_shareholder_search_finds_register_accounts_by_chn(): void
    {
        $payload = app(ShareholderController::class)->index(
            Request::create('/api/shareholders', 'GET', [
                'register_id' => $this->register->id,
                'search' => 'C111111111',
            ])
        )->getData(true);

        $this->assertSame(1, $payload['total']);
        $this->assertSame($this->debitAccount->shareholder_id, $payload['data'][0]['id']);
        $this->assertSame('C111111111', $payload['data'][0]['register_accounts'][0]['chn']);
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
        $this->service->confirmFinancialPreview($result['batch_id'], $this->maker->id, CscsUploadBatch::findOrFail($result['batch_id'])->snapshot_hash);
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
        $this->assertSame('CONTROLLED_REVERSAL', $ready['posting_policy']['correction_method']);
        $this->assertNull($ready['posting_policy']['rollback_window_hours']);

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
        $this->assertSame('WARNING', $payload['data'][0]['severity']);
        $this->assertTrue($payload['data'][0]['is_blocking']);
        $this->assertSame('DEBIT', $payload['data'][0]['parsed_record']['direction']);
        $this->assertContains('MAP_ACCOUNT', $payload['data'][0]['allowed_resolution_types']);
        $this->assertSame(1, $payload['meta']['exception_counts']['warnings']);
        $this->assertSame(1, $payload['meta']['exception_counts']['remaining']);
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

    public function test_resolved_exceptions_remain_visible_with_counts_and_history(): void
    {
        $result = $this->stageBatch();
        $row = CscsUploadRow::where('batch_id', $result['batch_id'])->where('tran_seq', '0')->firstOrFail();
        $row->update([
            'resolution_status' => 'UNRESOLVED',
            'exception_code' => 'ACCOUNT_REVIEW_REQUIRED',
            'error_message' => 'The account requires a manual decision.',
        ]);
        $request = Request::create('/api/cscs/exceptions/resolve', 'POST', [
            'resolution_type' => 'RULE_EXCLUDED',
            'reason' => 'The source instruction explicitly excludes this transaction group.',
        ]);
        $request->setUserResolver(fn () => $this->maker);
        app(CscsUploadController::class)->resolveException($request, $result['batch_id'], $row->id);

        $payload = app(CscsUploadController::class)->exceptions(
            Request::create('/api/cscs/exceptions', 'GET', ['resolution_status' => 'RULE_EXCLUDED']),
            $result['batch_id']
        )->getData(true);
        $resolved = collect($payload['data'])->firstWhere('id', $row->id);

        $this->assertSame(2, $payload['meta']['exception_counts']['resolved']);
        $this->assertSame(0, $payload['meta']['exception_counts']['remaining']);
        $this->assertSame('INFO', $resolved['severity']);
        $this->assertFalse($resolved['is_blocking']);
        $this->assertSame('EXCEPTION_RESOLVED', $resolved['resolution_history'][0]['event_type']);
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
        $this->assertSame(0, $summary['metrics']['duplicate_prevention_blocks']);
        $this->assertTrue($summary['all_checks_passed']);
        $this->assertCount(7, $summary['comparison']);
        $this->assertNotContains(false, collect($summary['comparison'])->pluck('matched')->all(), true);

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

    public function test_manual_mapping_survives_revalidation(): void
    {
        $batch = $this->stageBatch();
        $target = $this->account('C333333333', 'other@example.test', '08000000003', '500000.000000');
        $row = CscsUploadRow::where('batch_id', $batch['batch_id'])->where('sign', '-')->firstOrFail();
        $this->service->resolveException($batch['batch_id'], $row->id, $this->maker->id, [
            'resolution_type' => 'MAP_ACCOUNT', 'register_account_id' => $target->id, 'reason' => 'Verify manual mapping selection',
        ]);
        $this->service->reconcile($batch['batch_id'], $this->maker->id);
        $this->assertSame($target->id, $row->fresh()->proposed_sra_id);
        $this->assertSame('READY', $row->fresh()->resolution_status);
        $this->assertSame('251111.000000', $row->fresh()->proposed_after_qty);
    }

    public function test_partial_replay_must_be_rejected_at_confirmation(): void
    {
        $batch = $this->stageBatch();
        $row = CscsUploadRow::where('batch_id', $batch['batch_id'])->where('sign', '-')->firstOrFail();
        $prior = $row->replicate();
        $prior->fingerprint = $row->replay_key;
        $prior->status = 'posted';
        $prior->tran_no = 'prior-posted-transaction';
        $prior->save();
        $this->expectException(ValidationException::class);
        $this->service->resolveException($batch['batch_id'], $row->id, $this->maker->id, [
            'resolution_type' => 'CONFIRM_REPLAY', 'reason' => 'Verify every leg was previously posted',
        ]);
    }

    public function test_manual_mapping_does_not_bypass_insufficient_holdings(): void
    {
        $batch = $this->stageBatch();
        $row = CscsUploadRow::where('batch_id', $batch['batch_id'])->where('sign', '-')->firstOrFail();
        $this->service->resolveException($batch['batch_id'], $row->id, $this->maker->id, [
            'resolution_type' => 'MAP_ACCOUNT', 'register_account_id' => $this->creditAccount->id,
            'reason' => 'Verify insufficient holdings remain blocked',
        ]);
        // Remove the credit so the selected account cannot fund the debit from this batch.
        CscsUploadRow::where('batch_id', $batch['batch_id'])->where('sign', '+')->update([
            'proposed_sra_id' => $this->debitAccount->id, 'match_method' => 'manual_mapping',
        ]);
        try {
            $this->service->reconcile($batch['batch_id'], $this->maker->id);
            $this->fail('Insufficient holdings must block reconciliation.');
        } catch (ValidationException $e) {
            $this->assertSame('INSUFFICIENT_HOLDING', $row->fresh()->exception_code);
        }
        $this->assertDatabaseCount('share_transactions', 0);
    }

    public function test_manual_mapping_can_split_a_debit_across_same_shareholder_accounts(): void
    {
        $batch = $this->stageBatch();
        $row = CscsUploadRow::where('batch_id', $batch['batch_id'])->where('sign', '-')->firstOrFail();
        SharePosition::where('sra_id', $this->debitAccount->id)->where('share_class_id', $this->shareClass->id)
            ->update(['quantity' => '200000.000000']);
        $otherDebitAccount = ShareholderRegisterAccount::create([
            'shareholder_id' => $this->debitAccount->shareholder_id,
            'register_id' => $this->register->id,
            'shareholder_no' => 'SRA-SPLIT',
            'chn' => 'C111111112',
            'status' => 'active',
        ]);
        SharePosition::create([
            'sra_id' => $otherDebitAccount->id,
            'share_class_id' => $this->shareClass->id,
            'quantity' => '100000.000000',
            'holding_mode' => 'demat',
        ]);

        $this->service->resolveException($batch['batch_id'], $row->id, $this->maker->id, [
            'resolution_type' => 'MAP_ACCOUNT',
            'reason' => 'Split debit across verified accounts for the same shareholder',
            'account_allocations' => [
                ['register_account_id' => $this->debitAccount->id, 'quantity' => '200000.000000'],
                ['register_account_id' => $otherDebitAccount->id, 'quantity' => '48889.000000'],
            ],
        ]);

        $this->assertSame('RECONCILED', $this->service->reconcile($batch['batch_id'], $this->maker->id)['status']);
        $effects = $this->service->accountEffects($batch['batch_id']);
        $this->assertSame('200000.000000', $effects->firstWhere('register_account_id', $this->debitAccount->id)['total_debit']);
        $this->assertSame('48889.000000', $effects->firstWhere('register_account_id', $otherDebitAccount->id)['total_debit']);
    }

    public function test_split_manual_mapping_requires_one_shareholder(): void
    {
        $batch = $this->stageBatch();
        $row = CscsUploadRow::where('batch_id', $batch['batch_id'])->where('sign', '-')->firstOrFail();

        $this->expectException(ValidationException::class);
        $this->service->resolveException($batch['batch_id'], $row->id, $this->maker->id, [
            'resolution_type' => 'MAP_ACCOUNT',
            'reason' => 'Attempt invalid cross shareholder split mapping',
            'account_allocations' => [
                ['register_account_id' => $this->debitAccount->id, 'quantity' => '200000.000000'],
                ['register_account_id' => $this->creditAccount->id, 'quantity' => '48889.000000'],
            ],
        ]);
    }

    public function test_manual_mapping_rejects_an_account_from_another_register(): void
    {
        $batch = $this->stageBatch();
        $register = Register::create(['company_id' => $this->register->company_id, 'register_code' => 'OTHER', 'name' => 'Other Register', 'status' => 'active']);
        $other = ShareholderRegisterAccount::create([
            'shareholder_id' => $this->debitAccount->shareholder_id, 'register_id' => $register->id,
            'shareholder_no' => 'OTHER-ACCOUNT', 'status' => 'active',
        ]);
        $row = CscsUploadRow::where('batch_id', $batch['batch_id'])->where('sign', '-')->firstOrFail();
        try {
            $this->service->resolveException($batch['batch_id'], $row->id, $this->maker->id, [
                'resolution_type' => 'MAP_ACCOUNT', 'register_account_id' => $other->id,
                'reason' => 'Verify cross register mapping is rejected',
            ]);
            $this->fail('Accounts from another register must not be mapped.');
        } catch (ModelNotFoundException $e) {
            $this->assertSame($this->debitAccount->id, $row->fresh()->proposed_sra_id);
            $this->assertNull($row->fresh()->resolved_at);
        }
    }

    public function test_complete_replay_is_confirmed_without_changing_holdings(): void
    {
        $source = $this->stageAndSubmit();
        $this->service->approve($source->id, $this->checker);
        $this->service->post($source->id, $this->poster);
        $batch = $source->replicate();
        $batch->workflow_status = 'DRAFT_REVIEW';
        $batch->business_reference = 'REPLAY-TEST';
        $batch->save();
        foreach (CscsUploadRow::where('batch_id', $source->id)->where('file_type', 'movement')->get() as $posted) {
            $copy = $posted->replicate();
            $copy->batch_id = $batch->id;
            $copy->fingerprint = null;
            $copy->status = 'pending';
            $copy->resolution_status = 'UNRESOLVED';
            $copy->save();
        }
        $this->service->resolveException($batch->id, $copy->id, $this->maker->id, [
            'resolution_type' => 'CONFIRM_REPLAY', 'reason' => 'Confirm both previously posted legs',
        ]);
        $this->assertSame(2, CscsUploadRow::where('batch_id', $batch->id)->where('resolution_status', 'CONFIRMED_REPLAY')->count());
        $this->service->reconcile($batch->id, $this->maker->id);
        $this->assertCount(0, $this->service->accountEffects($batch->id));
        $this->assertDatabaseCount('share_transactions', 2);
        $this->assertSame('51111.000000', SharePosition::where('sra_id', $this->debitAccount->id)->value('quantity'));
    }

    public function test_replay_without_a_posted_match_leaves_rows_unchanged(): void
    {
        $batch = $this->stageBatch();
        $row = CscsUploadRow::where('batch_id', $batch['batch_id'])->where('file_type', 'movement')->firstOrFail();
        try {
            $this->service->resolveException($batch['batch_id'], $row->id, $this->maker->id, [
                'resolution_type' => 'CONFIRM_REPLAY', 'reason' => 'Attempt to confirm unposted movement',
            ]);
            $this->fail('Unposted movements must not be confirmed as replay.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('resolution_type', $e->errors());
        }
        $this->assertNull($row->fresh()->resolved_at);
        $this->assertSame('READY', $row->fresh()->resolution_status);
        $this->assertDatabaseCount('share_transactions', 0);
    }

    public function test_empty_account_effects_explain_blocking_exceptions_and_recover_after_reconciliation(): void
    {
        CscsSecurityMapping::query()->update(['is_active' => false]);
        $batch = $this->stageBatch();
        $controller = app(CscsUploadController::class);
        $payload = $controller->accountEffects(Request::create('/'), $batch['batch_id'])->getData(true);
        $this->assertSame([], $payload['data']);
        $this->assertSame('UNRESOLVED_EXCEPTIONS', $payload['meta']['empty_reason']);
        $this->assertSame('UNRESOLVED_EXCEPTIONS', $payload['meta']['account_effects']['empty_reason']);
        $this->assertSame(2, $payload['meta']['account_effects']['exception_counts']['UNKNOWN_SECURITY']);
        $preview = $controller->preview(Request::create('/'), $batch['batch_id'])->getData(true);
        $this->assertSame($payload['meta']['account_effects']['empty_reason'], $preview['data']['account_effects_meta']['empty_reason']);

        CscsSecurityMapping::query()->update(['is_active' => true]);
        $this->service->reconcile($batch['batch_id'], $this->maker->id);
        $payload = $controller->accountEffects(Request::create('/'), $batch['batch_id'])->getData(true);
        $this->assertCount(2, $payload['data']);
        $this->assertNull($payload['meta']['empty_reason']);
        $this->assertNull($payload['meta']['account_effects']['empty_reason']);
        $this->assertFalse($payload['meta']['account_effects']['paginated']);
    }

    public function test_balancing_preview_includes_only_same_shareholders_other_accounts_without_combining_holdings(): void
    {
        $batch = $this->stageBatch();
        $register = Register::create(['company_id' => $this->register->company_id, 'register_code' => 'OTHER', 'name' => 'Other Register', 'status' => 'active']);
        $class = ShareClass::create(['register_id' => $register->id, 'class_code' => 'PREF', 'name' => 'Preference']);
        $other = ShareholderRegisterAccount::create([
            'shareholder_id' => $this->debitAccount->shareholder_id, 'register_id' => $register->id,
            'shareholder_no' => 'OTHER-ACCOUNT', 'chn' => 'C333333333', 'status' => 'active',
        ]);
        SharePosition::create(['sra_id' => $other->id, 'share_class_id' => $class->id, 'quantity' => '900000.123456', 'holding_mode' => 'demat']);
        $payload = app(CscsUploadController::class)->preview(Request::create('/api/cscs/preview'), $batch['batch_id'])->getData(true)['data'];
        $effects = collect($payload['account_effects']);
        $debit = $effects->firstWhere('register_account_id', $this->debitAccount->id);
        $this->assertCount(2, $effects);
        $this->assertCount(1, $debit['other_accounts']);
        $this->assertSame($other->id, $debit['other_accounts'][0]['register_account_id']);
        $this->assertFalse($debit['other_accounts'][0]['same_register']);
        $this->assertSame('900000.123456', $debit['other_accounts'][0]['holdings'][0]['quantity']);
        $this->assertSame('300000.000000', $debit['current_quantity']);
        $this->assertSame('51111.000000', $debit['proposed_quantity']);
        $this->assertSame([], $effects->firstWhere('register_account_id', $this->creditAccount->id)['other_accounts']);
    }

    public function test_posting_request_rejects_failed_readiness_without_queuing(): void
    {
        $batch = $this->stageAndSubmit();
        $this->service->approve($batch->id, $this->checker);
        SharePosition::where('sra_id', $this->debitAccount->id)->update(['quantity' => '299999.000000']);
        try {
            $this->service->queueForPosting($batch->id, $this->poster);
            $this->fail('Stale holdings must not be queued.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('pre_posting_checks', $e->errors());
            $this->assertSame('APPROVED_AWAITING_POST', $batch->fresh()->workflow_status);
            $this->assertDatabaseCount('share_transactions', 0);
        }
    }

    public function test_worker_rechecks_account_register_after_queueing(): void
    {
        $batch = $this->stageAndSubmit();
        $this->service->approve($batch->id, $this->checker);
        $this->service->queueForPosting($batch->id, $this->poster);
        $other = Register::create(['company_id' => $this->register->company_id, 'register_code' => 'OTHER', 'name' => 'Other', 'status' => 'active']);
        $this->debitAccount->update(['register_id' => $other->id]);
        try {
            $this->service->post($batch->id, $this->poster);
            $this->fail('Changed mappings must prevent posting.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('pre_posting_checks', $e->errors());
            $this->assertSame('STALE', $batch->fresh()->workflow_status);
            $this->assertDatabaseCount('share_transactions', 0);
            $this->assertSame('300000.000000', SharePosition::where('sra_id', $this->debitAccount->id)->value('quantity'));
        }
    }

    public function test_account_identity_change_requires_a_new_review(): void
    {
        $batch = $this->stageAndSubmit();
        $this->service->approve($batch->id, $this->checker);
        $this->debitAccount->update(['chn' => 'C999999999']);
        $this->assertFalse($this->service->postingReadiness($batch->id)['checks']['account_mappings_valid']['passed']);
        $this->expectException(ValidationException::class);
        $this->service->queueForPosting($batch->id, $this->poster);
    }

    public function test_frozen_account_is_blocked_during_reconciliation(): void
    {
        $this->debitAccount->update(['status' => 'frozen']);
        $batch = $this->stageBatch();
        $this->assertDatabaseHas('cscs_upload_rows', ['batch_id' => $batch['batch_id'], 'exception_code' => 'ACCOUNT_FROZEN']);
        $this->assertDatabaseCount('share_transactions', 0);
    }

    public function test_posting_redelivery_does_not_duplicate_movements(): void
    {
        $batch = $this->stageAndSubmit();
        $this->service->approve($batch->id, $this->checker);
        $this->service->queueForPosting($batch->id, $this->poster);
        $this->service->post($batch->id, $this->poster);
        $this->assertSame('POSTED', $this->service->post($batch->id, $this->poster)['status']);
        $this->assertDatabaseCount('share_transactions', 2);
        $this->assertSame('51111.000000', SharePosition::where('sra_id', $this->debitAccount->id)->value('quantity'));
    }

    public function test_unconfirmed_preview_cannot_be_submitted(): void
    {
        $batch = $this->stageBatch();
        $this->service->reconcile($batch['batch_id'], $this->maker->id);
        $this->expectException(ValidationException::class);
        $this->service->submit($batch['batch_id'], $this->maker->id);
    }

    public function test_confirmation_rejects_the_wrong_snapshot_hash(): void
    {
        $batch = $this->stageBatch();
        $this->service->reconcile($batch['batch_id'], $this->maker->id);
        $this->expectException(ValidationException::class);
        $this->service->confirmFinancialPreview($batch['batch_id'], $this->maker->id, str_repeat('0', 64));
    }

    public function test_revalidation_invalidates_previous_preview_confirmation(): void
    {
        $batch = $this->stageBatch();
        $this->service->reconcile($batch['batch_id'], $this->maker->id);
        $this->service->confirmFinancialPreview($batch['batch_id'], $this->maker->id, CscsUploadBatch::find($batch['batch_id'])->snapshot_hash);
        $this->service->reconcile($batch['batch_id'], $this->maker->id);
        $this->expectException(ValidationException::class);
        $this->service->submit($batch['batch_id'], $this->maker->id);
    }

    public function test_query_references_must_belong_to_the_batch(): void
    {
        $batch = $this->stageAndSubmit();
        $this->expectException(ValidationException::class);
        $this->service->raiseQuery($batch->id, $this->checker->id, 'Review the referenced row', ['scope' => 'ROW', 'reference_id' => '999999']);
    }

    public function test_query_resolution_does_not_bypass_revision_increment(): void
    {
        $batch = $this->stageAndSubmit();
        $row = CscsUploadRow::where('batch_id', $batch->id)->where('sign', '-')->firstOrFail();
        $this->service->raiseQuery($batch->id, $this->checker->id, 'Review the selected account', ['scope' => 'ACCOUNT', 'reference_id' => (string) $this->debitAccount->id]);
        $this->service->resolveException($batch->id, $row->id, $this->maker->id, ['resolution_type' => 'MAP_ACCOUNT', 'register_account_id' => $this->debitAccount->id, 'reason' => 'Account mapping checked again']);
        $this->assertSame('QUERY_RAISED', $batch->fresh()->workflow_status);
        $this->service->respondToQuery($batch->id, $this->maker->id, 'The selected account has been checked');
        $this->assertSame(2, $batch->fresh()->revision);
        $this->assertNull($batch->fresh()->snapshot_hash);
    }

    public function test_group_resolution_audit_is_available_on_both_legs_with_actor_role(): void
    {
        $batch = $this->stageBatch();
        $rows = CscsUploadRow::where('batch_id', $batch['batch_id'])->where('file_type', 'movement')->get();
        $this->service->resolveException($batch['batch_id'], $rows->first()->id, $this->maker->id, ['resolution_type' => 'RULE_EXCLUDED', 'reason' => 'Documented transaction group exclusion']);
        foreach ($rows as $row) {
            $data = app(CscsUploadController::class)->row($batch['batch_id'], $row->id)->getData(true)['data'];
            $this->assertCount(1, $data['resolution_history']);
            $this->assertSame('MAKER', $data['resolution_history'][0]['actor_role']);
            $this->assertSame(1, $data['resolution_history'][0]['metadata']['revision']);
        }
    }

    public function test_dashboard_security_totals_and_exception_filters_are_batch_wide(): void
    {
        $batch = $this->stageBatch();
        $controller = app(CscsUploadController::class);
        $summary = $controller->dashboardSummary(Request::create('/'))->getData(true)['data'];
        $this->assertSame(1, $summary['counts']['DRAFT_REVIEW']);
        $preview = $controller->preview(Request::create('/'), $batch['batch_id'])->getData(true)['data'];
        $this->assertSame('248889.000000', $preview['security_totals'][0]['total_debit']);
        $this->assertSame('0.000000', $preview['security_totals'][0]['net_movement']);
        $row = CscsUploadRow::where('batch_id', $batch['batch_id'])->where('sign', '-')->firstOrFail();
        $row->update(['exception_code' => 'INSUFFICIENT_HOLDING', 'resolution_status' => 'UNRESOLVED']);
        $exceptions = $controller->exceptions(Request::create('/', 'GET', ['severity' => 'CRITICAL', 'status' => 'PENDING']), $batch['batch_id'])->getData(true);
        $this->assertSame(1, $exceptions['pagination']['total']);
        $this->assertSame($row->id, $exceptions['data'][0]['id']);
    }

    public function test_failed_atomic_batch_exposes_retry_even_when_failed_row_count_is_zero(): void
    {
        $batch = $this->stageAndSubmit();
        $batch->update(['workflow_status' => 'POSTING_FAILED', 'failure_reason' => 'Temporary database failure']);
        $data = app(CscsUploadController::class)->verificationSummary($batch->id)->getData(true)['data'];
        $this->assertTrue($data['retry']['can_retry']);
        $this->assertSame('BATCH', $data['retry']['scope']);
        $this->assertSame(2, $data['retry']['unposted_rows']);
        $this->assertSame(0, $data['metrics']['failed_rows']);
    }

    public function test_staged_shareholder_creation_posts_only_after_confirmation_and_approval(): void
    {
        $batch = $this->stageUnknownCredit();
        $row = CscsUploadRow::where('batch_id', $batch['batch_id'])->where('sign', '+')->firstOrFail();
        $this->service->resolveException($batch['batch_id'], $row->id, $this->maker->id, [
            'resolution_type' => 'CREATE_SHAREHOLDER', 'reason' => 'Verified new shareholder identity',
            'profile' => ['full_name' => 'New Holder', 'email' => 'new@example.test', 'phone' => '08000000009'],
        ]);
        $this->service->reconcile($batch['batch_id'], $this->maker->id);
        $this->assertDatabaseCount('shareholders', 2);
        $this->assertDatabaseCount('shareholder_register_accounts', 2);
        $this->assertTrue($this->service->accountEffects($batch['batch_id'])->firstWhere('identifier_value', 'C999999999')['is_new_account']);
        $this->service->confirmFinancialPreview($batch['batch_id'], $this->maker->id, CscsUploadBatch::find($batch['batch_id'])->snapshot_hash);
        $this->service->submit($batch['batch_id'], $this->maker->id);
        $this->service->approve($batch['batch_id'], $this->checker);
        $this->service->post($batch['batch_id'], $this->poster);
        $this->assertDatabaseCount('shareholders', 3);
        $this->assertDatabaseCount('shareholder_register_accounts', 3);
        $this->assertDatabaseCount('share_transactions', 2);
    }

    public function test_staged_account_creation_uses_the_selected_existing_shareholder(): void
    {
        $batch = $this->stageUnknownCredit();
        $holder = Shareholder::create(['account_no' => 'EXISTING-3', 'holder_type' => 'individual', 'full_name' => 'Existing Holder', 'first_name' => 'Existing', 'last_name' => 'Holder', 'email' => 'existing@example.test', 'phone' => '08000000009', 'status' => 'active']);
        $row = CscsUploadRow::where('batch_id', $batch['batch_id'])->where('sign', '+')->firstOrFail();
        $this->service->resolveException($batch['batch_id'], $row->id, $this->maker->id, ['resolution_type' => 'CREATE_ACCOUNT', 'shareholder_id' => $holder->id, 'reason' => 'Create account for verified existing shareholder']);
        $this->service->reconcile($batch['batch_id'], $this->maker->id);
        $this->assertDatabaseCount('shareholder_register_accounts', 2);
        $this->service->confirmFinancialPreview($batch['batch_id'], $this->maker->id, CscsUploadBatch::find($batch['batch_id'])->snapshot_hash);
        $this->service->submit($batch['batch_id'], $this->maker->id);
        $this->service->approve($batch['batch_id'], $this->checker);
        $this->service->post($batch['batch_id'], $this->poster);
        $this->assertDatabaseCount('shareholders', 3);
        $this->assertDatabaseHas('shareholder_register_accounts', ['shareholder_id' => $holder->id, 'chn' => 'C999999999']);
    }

    public function test_optional_holdings_age_limit_is_enforced_without_changing_default_behavior(): void
    {
        $batch = $this->stageAndSubmit();
        $this->service->approve($batch->id, $this->checker);
        $this->assertTrue($this->service->postingReadiness($batch->id)['ready']);
        config()->set('cscs.holdings_max_age_hours', 24);
        $this->assertFalse($this->service->postingReadiness($batch->id)['checks']['holdings_current']['passed']);
        SharePosition::query()->update(['last_updated_at' => now()]);
        $this->assertTrue($this->service->postingReadiness($batch->id)['ready']);
    }

    public function test_new_api_routes_and_confirmation_aliases_are_wired(): void
    {
        $batch = $this->stageBatch();
        $this->service->reconcile($batch['batch_id'], $this->maker->id);
        $hash = CscsUploadBatch::find($batch['batch_id'])->snapshot_hash;
        $this->withoutMiddleware()->actingAs($this->maker, 'sanctum');
        $this->getJson('/api/cscs/uploads/summary')->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson('/api/cscs/uploads/'.$batch['batch_id'].'/process/status')->assertOk()->assertJsonPath('data.status', 'DONE');
        $this->getJson('/api/cscs/shareholders/'.$this->debitAccount->shareholder_id.'/accounts?register_id='.$this->register->id)
            ->assertOk()->assertJsonPath('data.accounts.0.can_map', true);
        $this->postJson('/api/cscs/uploads/'.$batch['batch_id'].'/save-draft', ['notes' => 'Review in progress'])->assertOk();
        $this->assertSame('Review in progress', data_get(CscsUploadBatch::find($batch['batch_id'])->summary, 'review_draft.notes'));
        $request = Request::create('/', 'POST', ['snapshotHash' => $hash, 'confirmedByMaker' => true, 'pageSize' => 20]);
        app(CscsApiContract::class)->handle($request, function ($request) use ($batch) {
            $request->setUserResolver(fn () => $this->maker);
            $this->assertSame(20, $request->input('per_page'));

            return app(CscsUploadController::class)->confirmFinancialPreview($request, $batch['batch_id']);
        });
        $this->postJson('/api/cscs/uploads/'.$batch['batch_id'].'/submit')->assertOk();
    }

    public function test_cscs_errors_keep_existing_fields_and_add_machine_readable_codes(): void
    {
        $batch = $this->stageBatch();
        $this->withoutMiddleware()->actingAs($this->maker, 'sanctum')
            ->postJson('/api/cscs/uploads/'.$batch['batch_id'].'/submit')
            ->assertStatus(422)->assertJsonPath('error.code', 'BATCH_STATE_CONFLICT')->assertJsonStructure(['message', 'errors']);
    }

    public function test_report_links_are_signed_and_reject_tampering(): void
    {
        $batch = $this->stageBatch();
        $data = app(CscsUploadController::class)->downloadReport(Request::create('/', 'POST', ['type' => 'reconciliation', 'format' => 'pdf']), $batch['batch_id'])->getData(true)['data'];
        $this->assertTrue(URL::hasValidSignature(Request::create($data['download_url'])));
        $this->assertFalse(URL::hasValidSignature(Request::create($data['download_url'].'&type=audit')));
        $this->assertTrue($data['requires_authentication']);
    }

    public function test_configured_trade_date_window_creates_a_blocking_exception(): void
    {
        config()->set('cscs.trade_date_min', '2099-01-01');
        $batch = $this->stageBatch();
        $this->assertSame(2, CscsUploadRow::where('batch_id', $batch['batch_id'])->where('exception_code', 'DATE_OUT_OF_RANGE')->count());
    }

    public function test_configured_name_check_requires_an_audited_manual_mapping(): void
    {
        config()->set('cscs.validate_holder_names', true);
        $batch = $this->stageBatch();
        $rows = CscsUploadRow::where('batch_id', $batch['batch_id'])->where('file_type', 'movement')->get();
        $this->assertSame(2, $rows->where('exception_code', 'NAME_MISMATCH')->count());
        foreach ($rows as $row) {
            $this->service->resolveException($batch['batch_id'], $row->id, $this->maker->id, [
                'resolution_type' => 'MAP_ACCOUNT', 'register_account_id' => $row->sign === '-' ? $this->debitAccount->id : $this->creditAccount->id,
                'reason' => 'Identity verified despite name spelling difference',
            ]);
        }
        $this->assertSame('RECONCILED', $this->service->reconcile($batch['batch_id'], $this->maker->id)['status']);
    }

    public function test_modified_creation_proposal_invalidates_the_confirmed_snapshot(): void
    {
        $batch = $this->stageUnknownCredit();
        $row = CscsUploadRow::where('batch_id', $batch['batch_id'])->where('sign', '+')->firstOrFail();
        $this->service->resolveException($batch['batch_id'], $row->id, $this->maker->id, [
            'resolution_type' => 'CREATE_SHAREHOLDER', 'reason' => 'Verified new shareholder identity',
            'profile' => ['full_name' => 'New Holder', 'email' => 'new@example.test', 'phone' => '08000000009'],
        ]);
        $this->service->reconcile($batch['batch_id'], $this->maker->id);
        $this->service->confirmFinancialPreview($batch['batch_id'], $this->maker->id, CscsUploadBatch::find($batch['batch_id'])->snapshot_hash);
        $extra = $row->fresh()->extra_details;
        $extra['account_proposal']['profile']['full_name'] = 'Another Holder';
        $row->update(['extra_details' => $extra]);
        $this->expectException(ValidationException::class);
        $this->service->submit($batch['batch_id'], $this->maker->id);
    }

    public function test_stale_notification_and_worker_redelivery_do_not_change_posted_data(): void
    {
        $notifications = \Mockery::mock(AdminNotificationService::class);
        $notifications->shouldReceive('sendToRoles')->once()->withArgs(fn ($roles, $event, ...$rest) => $event === 'CSCS_STALE');
        $this->app->instance(AdminNotificationService::class, $notifications);
        $batch = $this->stageAndSubmit();
        $this->service->approve($batch->id, $this->checker);
        $this->debitAccount->update(['status' => 'frozen']);
        try {
            $this->service->post($batch->id, $this->poster);
            $this->fail('Frozen account must not post.');
        } catch (ValidationException) {
            $this->assertSame('STALE', $batch->fresh()->workflow_status);
        }
        $this->debitAccount->update(['status' => 'active']);
        $batch->update(['workflow_status' => 'POSTED']);
        (new PostCscsBatchJob($batch->id, $this->poster->id))->handle($this->service, $notifications);
        $this->assertDatabaseCount('share_transactions', 0);
    }

    private function stageUnknownCredit(): array
    {
        $movement = $this->movementLine('2606160005615099', '0', '-', 'C111111111', '100')."\r\n"
            .$this->movementLine('2606160005615099', '1', '+', 'C999999999', '100')."\r\n";

        return $this->service->import([UploadedFile::fake()->createWithContent('unknown-credit.txt', $movement)], $this->register->id, $this->maker->id);
    }

    private function stageAndSubmit(): CscsUploadBatch
    {
        $result = $this->stageBatch();
        $this->service->reconcile($result['batch_id'], $this->maker->id);
        $this->service->confirmFinancialPreview($result['batch_id'], $this->maker->id, CscsUploadBatch::findOrFail($result['batch_id'])->snapshot_hash);
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
        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('issuer_code');
            $t->string('name');
            $t->string('status');
            $t->timestamps();
            $t->softDeletes();
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
        Schema::create('shareholder_cautions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('shareholder_id');
            $t->timestamp('removed_at')->nullable();
            $t->timestamps();
        });
        Schema::create('shareholder_register_accounts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('shareholder_id');
            $t->unsignedBigInteger('register_id');
            $t->unsignedBigInteger('shareholder_category_id')->nullable();
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
