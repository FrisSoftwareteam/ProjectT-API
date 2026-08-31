<?php

namespace App\Services;

use App\Exceptions\CscsImportCancelledException;
use App\Models\AdminUser;
use App\Models\CscsApprovalAction;
use App\Models\CscsApprovalPolicy;
use App\Models\CscsBatchSnapshot;
use App\Models\CscsSecurityMapping;
use App\Models\CscsUploadBatch;
use App\Models\CscsUploadRow;
use App\Models\CscsWorkflowEvent;
use App\Models\Shareholder;
use App\Models\ShareholderRegisterAccount;
use App\Models\SharePosition;
use App\Models\ShareTransaction;
use App\Models\SraExternalIdentifier;
use DateTimeImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CscsImportService
{
    private const SCALE = 6;

    public function __construct(
        private readonly ShareholderAccountNumberService $accountNumberService
    ) {}

    /**
     * Store and stage CSCS files. This method never changes live holdings.
     *
     * @param  array<int, UploadedFile>  $files
     * @return array<string, mixed>
     */
    public function import(
        array $files,
        int $registerId,
        ?int $uploadedBy,
        ?string $description = null,
        ?string $businessReference = null
    ): array {
        $staged = $this->stageImport($files, $registerId, $uploadedBy, $description, $businessReference);

        return $this->processStagedImport((int) $staged['batch_id']);
    }

    /**
     * Persist the source files and return immediately so processing can be queued.
     *
     * @param  array<int, UploadedFile>  $files
     * @return array<string, mixed>
     */
    public function stageImport(
        array $files,
        int $registerId,
        ?int $uploadedBy,
        ?string $description = null,
        ?string $businessReference = null
    ): array {
        $batch = CscsUploadBatch::create([
            'uploaded_by' => $uploadedBy,
            'register_id' => $registerId,
            'status' => 'processing',
            'workflow_status' => 'PROCESSING',
            'uploaded_files' => [],
            'description' => $description,
            'business_reference' => $businessReference,
        ]);

        $this->event($batch, 'UPLOADED', null, 'PROCESSING', $uploadedBy);

        $storedFiles = [];

        try {
            usort($files, fn (UploadedFile $left, UploadedFile $right) => ($this->detectFileType($left) === 'master' ? 0 : 1)
                <=> ($this->detectFileType($right) === 'master' ? 0 : 1));
            $seenTypes = [];
            foreach ($files as $file) {
                $this->assertUtf8File($file);
                $type = $this->detectFileType($file);
                if (isset($seenTypes[$type])) {
                    throw ValidationException::withMessages(['files' => ["Only one {$type} file may be uploaded in a batch."]]);
                }
                $seenTypes[$type] = true;
                $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($file->getClientOriginalName())) ?: 'cscs.txt';
                $hash = hash_file('sha256', $file->getRealPath());
                $duplicateBatchId = $this->duplicateFileBatchId($hash, $registerId, (int) $batch->id);
                if ($duplicateBatchId) {
                    throw ValidationException::withMessages([
                        'files' => ["{$safeName} duplicates a file already staged in CSCS batch #{$duplicateBatchId}."],
                    ]);
                }
                $path = $file->storeAs(
                    'private/cscs_uploads/'.$batch->id,
                    now()->format('Ymd_His_u').'_'.$safeName
                );
                $storedFiles[] = [
                    'name' => $safeName,
                    'original_name' => $file->getClientOriginalName(),
                    'path' => $path,
                    'type' => $type,
                    'size' => $file->getSize(),
                    'sha256' => $hash,
                    'encoding' => 'UTF-8',
                ];
            }

            $batch->update([
                'uploaded_files' => $storedFiles,
                'summary' => [
                    'processing_stage' => 'STAGED',
                    'processing_percent' => 0,
                    'files_total' => count($storedFiles),
                    'files_processed' => 0,
                    'duplicate_files' => 0,
                    'encoding' => 'UTF-8',
                ],
            ]);

            return $this->batchResult($batch->fresh());
        } catch (\Throwable $e) {
            $this->failImport($batch, $storedFiles, [], $e);
        }
    }

    /** @return array<string, mixed> */
    public function processStagedImport(int $batchId): array
    {
        $batch = CscsUploadBatch::findOrFail($batchId);
        if ($batch->workflow_status !== 'PROCESSING') {
            return $this->batchResult($batch);
        }

        $storedFiles = $batch->uploaded_files ?? [];
        $masterProfiles = [];
        $counts = [
            'master_rows' => 0,
            'movement_rows' => 0,
            'invalid_rows' => 0,
            'duplicate_rows' => 0,
            'duplicate_master_identifiers' => 0,
            'processing_stage' => 'PARSING',
            'processing_percent' => 0,
            'files_total' => count($storedFiles),
            'files_processed' => 0,
            'duplicate_files' => 0,
            'encoding' => 'UTF-8',
        ];
        $seenMovementRows = [];

        try {
            $totalSourceRows = $this->countSourceRows($storedFiles);
            $processedSourceRows = 0;
            $counts['source_rows_total'] = $totalSourceRows;
            $counts['source_rows_processed'] = 0;
            $this->persistProcessingProgress($batch, $counts, 'PARSING', 1, true);

            foreach ($storedFiles as $storedFile) {
                $type = (string) $storedFile['type'];
                $safeName = (string) $storedFile['name'];
                $path = (string) $storedFile['path'];

                $lines = preg_split('/\r\n|\n|\r/', (string) Storage::get($path)) ?: [];
                if ($type === 'master') {
                    foreach ($lines as $index => $line) {
                        if (trim($line) === '') {
                            continue;
                        }
                        $profile = $this->parseMasterLine($line);
                        $counts['master_rows']++;
                        $duplicateMaster = false;
                        if ($profile['identifier'] !== '') {
                            $masterProfiles[$profile['identifier']] ??= [];
                            $masterProfiles[$profile['identifier']][] = $profile;
                            $duplicateMaster = count($masterProfiles[$profile['identifier']]) > 1;
                            if ($duplicateMaster) {
                                $counts['duplicate_master_identifiers']++;
                            }
                        }
                        CscsUploadRow::create([
                            'batch_id' => $batch->id,
                            'file_type' => 'master',
                            'source_filename' => $safeName,
                            'row_number' => $index + 1,
                            'identifier_value' => $profile['identifier'] ?: null,
                            'status' => 'skipped',
                            'resolution_status' => $duplicateMaster ? 'INVALID' : 'MASTER_RECORD',
                            'exception_code' => $duplicateMaster ? 'DUPLICATE_MASTER_IDENTIFIER' : null,
                            'error_message' => $duplicateMaster ? 'The master identifier occurs more than once.' : null,
                            'raw_line' => $line,
                            'extra_details' => ['profile' => $profile],
                        ]);
                        $processedSourceRows++;
                        $this->persistParsingProgress($batch, $counts, $processedSourceRows, $totalSourceRows);
                    }

                    $counts['files_processed']++;
                    $this->persistParsingProgress($batch, $counts, $processedSourceRows, $totalSourceRows, true);

                    continue;
                }

                foreach ($lines as $index => $line) {
                    if (trim($line) === '') {
                        continue;
                    }
                    $counts['movement_rows']++;
                    $parsed = $this->parseMovementLine($line, $index + 1, $safeName);
                    if (isset($parsed['parse_error'])) {
                        $counts['invalid_rows']++;
                        $this->createInvalidRow($batch, $parsed);
                        $processedSourceRows++;
                        $this->persistParsingProgress($batch, $counts, $processedSourceRows, $totalSourceRows);

                        continue;
                    }
                    $sourceFingerprint = hash('sha256', $line);
                    $isDuplicateRow = isset($seenMovementRows[$sourceFingerprint]);
                    $seenMovementRows[$sourceFingerprint] = true;
                    if ($isDuplicateRow) {
                        $counts['duplicate_rows']++;
                    }
                    $profiles = $masterProfiles[$parsed['identifier_value']] ?? [];
                    $profile = count($profiles) === 1 ? $profiles[0] : null;
                    CscsUploadRow::create(array_merge($parsed, [
                        'batch_id' => $batch->id,
                        'file_type' => 'movement',
                        'status' => 'skipped',
                        'resolution_status' => $isDuplicateRow ? 'INVALID' : 'UNRESOLVED',
                        'exception_code' => $isDuplicateRow ? 'DUPLICATE_SOURCE_ROW' : null,
                        'error_message' => $isDuplicateRow ? 'This movement row is duplicated within the uploaded file.' : null,
                        'transaction_group_key' => $parsed['tran_no'],
                        'replay_key' => $this->replayKey($parsed),
                        'extra_details' => [
                            'master_profile' => $profile,
                            'master_profile_count' => count($profiles),
                            'source_fingerprint' => $sourceFingerprint,
                        ],
                    ]));
                    $processedSourceRows++;
                    $this->persistParsingProgress($batch, $counts, $processedSourceRows, $totalSourceRows);
                }

                $counts['files_processed']++;
                $this->persistParsingProgress($batch, $counts, $processedSourceRows, $totalSourceRows, true);
            }

            if ($counts['movement_rows'] === 0) {
                throw ValidationException::withMessages(['files' => ['A CSCS movement file is required.']]);
            }

            $this->persistProcessingProgress($batch, $counts, 'VALIDATING', 82, true);
            $batch->update(['status' => 'completed', 'uploaded_files' => $storedFiles]);
            $this->validateDraft($batch, $batch->uploaded_by, false);
            $processedBatch = $batch->fresh();
            if ($processedBatch->workflow_status === 'DRAFT_REVIEW') {
                $this->event($processedBatch, 'PARSED', 'PROCESSING', 'DRAFT_REVIEW', $processedBatch->uploaded_by, null, $counts);
            }

            return $this->batchResult($processedBatch);
        } catch (CscsImportCancelledException) {
            return $this->finalizeCancelledImport($batchId);
        } catch (\Throwable $e) {
            $this->failImport($batch, $storedFiles, $counts, $e);
        }
    }

    /**
     * Count meaningful source rows one file at a time, including CR-only source files.
     *
     * @param  array<int, array<string, mixed>>  $storedFiles
     */
    private function countSourceRows(array $storedFiles): int
    {
        $total = 0;

        foreach ($storedFiles as $storedFile) {
            $path = (string) ($storedFile['path'] ?? '');
            $lines = preg_split('/\r\n|\n|\r/', (string) Storage::get($path)) ?: [];
            $total += count(array_filter($lines, fn (string $line) => trim($line) !== ''));
        }

        return $total;
    }

    /** @param array<string, mixed> $summary */
    private function persistParsingProgress(
        CscsUploadBatch $batch,
        array &$summary,
        int $processedRows,
        int $totalRows,
        bool $force = false
    ): void {
        $summary['source_rows_processed'] = $processedRows;
        $summary['source_rows_total'] = $totalRows;
        $percent = $totalRows > 0
            ? 5 + (int) floor(($processedRows / $totalRows) * 75)
            : 80;

        $this->persistProcessingProgress($batch, $summary, 'PARSING', min(80, $percent), $force);
    }

    /** @param array<string, mixed> $summary */
    private function persistProcessingProgress(
        CscsUploadBatch $batch,
        array &$summary,
        string $stage,
        int $percent,
        bool $force = false
    ): void {
        $currentStage = (string) ($summary['processing_stage'] ?? '');
        $currentPercent = (int) ($summary['processing_percent'] ?? 0);
        $percent = max($currentPercent, min(99, max(0, $percent)));

        if (! $force && $currentStage === $stage && $currentPercent === $percent) {
            return;
        }

        $summary['processing_stage'] = $stage;
        $summary['processing_percent'] = $percent;

        DB::transaction(function () use ($batch, $summary): void {
            $current = CscsUploadBatch::lockForUpdate()->findOrFail($batch->id);
            if ($current->workflow_status === 'CANCELLED') {
                throw new CscsImportCancelledException;
            }
            if ($current->workflow_status !== 'PROCESSING') {
                throw new \RuntimeException("CSCS batch {$batch->id} left PROCESSING while the import worker was active.");
            }

            $current->update(['summary' => $summary]);
        });

        $batch->setAttribute('summary', $summary);
    }

    /** @return array<string, mixed> */
    private function finalizeCancelledImport(int $batchId): array
    {
        CscsUploadRow::where('batch_id', $batchId)
            ->where('file_type', 'movement')
            ->whereNotIn('resolution_status', ['POSTED', 'CONFIRMED_REPLAY'])
            ->update(['resolution_status' => 'CANCELLED_WITH_BATCH']);

        return $this->batchResult(CscsUploadBatch::findOrFail($batchId));
    }

    /**
     * @param  array<int, array<string, mixed>>  $storedFiles
     * @param  array<string, mixed>  $counts
     */
    private function failImport(CscsUploadBatch $batch, array $storedFiles, array $counts, \Throwable $exception): never
    {
        $reference = (string) Str::uuid();
        $latestSummary = $batch->fresh()->summary ?? [];
        $failureSummary = array_merge($counts, $latestSummary, [
            'processing_stage' => 'FAILED',
            'processing_percent' => max(
                (int) ($counts['processing_percent'] ?? 0),
                (int) ($latestSummary['processing_percent'] ?? 0)
            ),
            'failure_reference' => $reference,
        ]);
        Log::error('CSCS staging failed', ['batch_id' => $batch->id, 'reference' => $reference, 'error' => $exception->getMessage()]);
        $batch->update([
            'status' => 'failed',
            'workflow_status' => 'PROCESSING_FAILED',
            'uploaded_files' => $storedFiles,
            'summary' => $failureSummary,
            'failure_reason' => "CSCS processing failed. Reference: {$reference}",
        ]);
        $this->event(
            $batch,
            'PROCESSING_FAILED',
            'PROCESSING',
            'PROCESSING_FAILED',
            $batch->uploaded_by,
            "Processing failed. Reference: {$reference}"
        );

        throw $exception;
    }

    /** @return array<string, mixed> */
    public function reconcile(int $batchId, int $actorId, ?string $comment = null): array
    {
        $batch = CscsUploadBatch::findOrFail($batchId);
        $this->assertMaker($batch, $actorId);
        $this->assertState($batch, ['DRAFT_REVIEW', 'QUERY_RAISED', 'RECONCILED', 'STALE']);
        $result = $this->validateDraft($batch, $actorId, true, $comment);
        if (($result['unresolved_exceptions'] ?? 0) > 0) {
            throw ValidationException::withMessages([
                'batch' => ["The batch has {$result['unresolved_exceptions']} unresolved exception(s)."],
            ]);
        }

        return $this->batchResult($batch->fresh());
    }

    /** @return array<string, mixed> */
    private function validateDraft(
        CscsUploadBatch $batch,
        ?int $actorId,
        bool $markReconciled,
        ?string $comment = null
    ): array {
        $trackImportProgress = $batch->workflow_status === 'PROCESSING';
        $progressSummary = $batch->summary ?? [];
        $rows = CscsUploadRow::where('batch_id', $batch->id)
            ->where('file_type', 'movement')
            ->orderBy('id')
            ->get();

        $processedValidationRows = 0;
        foreach ($rows as $row) {
            if (in_array($row->resolution_status, ['RULE_EXCLUDED', 'CONFIRMED_REPLAY'], true)) {
                $processedValidationRows++;
                if ($trackImportProgress) {
                    $percent = 82 + (int) floor(($processedValidationRows / max(1, $rows->count())) * 6);
                    $this->persistProcessingProgress($batch, $progressSummary, 'VALIDATING_ROWS', $percent);
                }

                continue;
            }
            $structuralException = in_array($row->exception_code, ['INVALID_FORMAT', 'DUPLICATE_SOURCE_ROW'], true);
            $row->update([
                'resolution_status' => $structuralException ? 'INVALID' : 'UNRESOLVED',
                'exception_code' => $structuralException ? $row->exception_code : null,
                'error_message' => $structuralException ? $row->error_message : null,
                'proposed_share_class_id' => null,
                'proposed_before_qty' => null,
                'proposed_delta_qty' => null,
                'proposed_after_qty' => null,
            ]);
            $processedValidationRows++;
            if ($trackImportProgress) {
                $percent = 82 + (int) floor(($processedValidationRows / max(1, $rows->count())) * 6);
                $this->persistProcessingProgress($batch, $progressSummary, 'VALIDATING_ROWS', $percent);
            }
        }

        $totalDebit = $this->zero();
        $totalCredit = $this->zero();
        $riskFlags = [];
        $effects = [];
        $readyGroups = 0;
        $groups = $rows->filter(fn (CscsUploadRow $row) => $row->tran_no)->groupBy('tran_no');
        $processedValidationGroups = 0;
        $advanceValidationGroup = function () use (
            $batch,
            $groups,
            $trackImportProgress,
            &$processedValidationGroups,
            &$progressSummary
        ): void {
            $processedValidationGroups++;
            if ($trackImportProgress) {
                $percent = 88 + (int) floor(($processedValidationGroups / max(1, $groups->count())) * 7);
                $this->persistProcessingProgress($batch, $progressSummary, 'VALIDATING_TRANSACTIONS', $percent);
            }
        };

        foreach ($groups as $tranNo => $group) {
            if ($group->contains(fn (CscsUploadRow $row) => in_array($row->exception_code, ['INVALID_FORMAT', 'DUPLICATE_SOURCE_ROW'], true))) {
                foreach ($group->reject(fn (CscsUploadRow $row) => in_array($row->exception_code, ['INVALID_FORMAT', 'DUPLICATE_SOURCE_ROW'], true)) as $row) {
                    $this->failRow($row, 'GROUP_STRUCTURAL_ERROR', 'Another leg in this transaction group has a structural validation error.');
                }

                $advanceValidationGroup();

                continue;
            }
            if ($group->every(fn (CscsUploadRow $row) => $row->resolution_status === 'RULE_EXCLUDED')) {
                $advanceValidationGroup();

                continue;
            }
            if ($group->contains(fn (CscsUploadRow $row) => $row->resolution_status === 'RULE_EXCLUDED')) {
                foreach ($group->reject(fn (CscsUploadRow $row) => $row->resolution_status === 'RULE_EXCLUDED') as $row) {
                    $this->failRow($row, 'PARTIAL_GROUP_EXCLUSION', 'A transfer group must be included or excluded as a whole.');
                }

                $advanceValidationGroup();

                continue;
            }

            $replayed = $group->filter(fn (CscsUploadRow $row) => $this->isPostedReplay($row));
            if ($replayed->count() === $group->count()) {
                foreach ($group as $row) {
                    $row->update([
                        'resolution_status' => 'CONFIRMED_REPLAY',
                        'exception_code' => null,
                        'error_message' => 'Previously posted movement leg',
                    ]);
                }

                $advanceValidationGroup();

                continue;
            }
            if ($replayed->isNotEmpty()) {
                $this->failGroup($group, 'PARTIAL_REPLAY', 'Only part of this transaction group was previously posted.');

                $advanceValidationGroup();

                continue;
            }

            $groupError = $this->validateTransactionGroup($group);
            if ($groupError) {
                $this->failGroup($group, $groupError[0], $groupError[1]);

                $advanceValidationGroup();

                continue;
            }

            $securityCode = strtoupper((string) $group->first()->sec_code);
            $mapping = CscsSecurityMapping::where('security_code', $securityCode)->where('is_active', true)->first();
            if (! $mapping || (int) $mapping->register_id !== (int) $batch->register_id) {
                $this->failGroup($group, 'UNKNOWN_SECURITY', "No active mapping exists for {$securityCode} in this register.");

                $advanceValidationGroup();

                continue;
            }

            $groupReady = true;
            foreach ($group as $row) {
                $account = $this->resolveAccountForPreview($row, $mapping->register_id);
                if (! $account['resolved']) {
                    $this->failRow($row, $account['code'], $account['message']);
                    $groupReady = false;

                    continue;
                }

                $row->update([
                    'proposed_sra_id' => $account['sra_id'],
                    'proposed_share_class_id' => $mapping->share_class_id,
                    'matched_by' => $account['method'],
                    'match_method' => $account['method'],
                    'resolution_status' => 'READY',
                    'exception_code' => null,
                    'error_message' => null,
                    'proposed_delta_qty' => $row->sign === '-' ? '-'.$this->decimal($row->volume) : $this->decimal($row->volume),
                ]);

                if ($account['new_account']) {
                    $riskFlags['NEW_ACCOUNT'] = true;
                }
            }
            if (! $groupReady) {
                foreach ($group->where('resolution_status', 'READY') as $readyRow) {
                    $this->failRow($readyRow, 'GROUP_UNRESOLVED', 'Another leg in this transaction group is unresolved.');
                }

                $advanceValidationGroup();

                continue;
            }

            foreach ($group as $row) {
                $quantity = $this->decimal($row->volume);
                if ($row->sign === '-') {
                    $totalDebit = bcadd($totalDebit, $quantity, self::SCALE);
                } else {
                    $totalCredit = bcadd($totalCredit, $quantity, self::SCALE);
                }
                $key = ($row->proposed_sra_id ? 'sra:'.$row->proposed_sra_id : 'new:'.$row->identifier_value)
                    .':class:'.$mapping->share_class_id;
                $effects[$key] ??= [
                    'sra_id' => $row->proposed_sra_id,
                    'identifier' => $row->identifier_value,
                    'share_class_id' => (int) $mapping->share_class_id,
                    'debit' => $this->zero(),
                    'credit' => $this->zero(),
                    'rows' => [],
                ];
                $effects[$key][$row->sign === '-' ? 'debit' : 'credit'] = bcadd(
                    $effects[$key][$row->sign === '-' ? 'debit' : 'credit'],
                    $quantity,
                    self::SCALE
                );
                $effects[$key]['rows'][] = $row->id;
            }
            $readyGroups++;
            $advanceValidationGroup();
        }

        $processedEffects = 0;
        foreach ($effects as $effect) {
            $before = $this->zero();
            if ($effect['sra_id']) {
                $before = $this->decimal(SharePosition::where('sra_id', $effect['sra_id'])
                    ->where('share_class_id', $effect['share_class_id'])->value('quantity') ?? 0);
            }
            $delta = bcsub($effect['credit'], $effect['debit'], self::SCALE);
            $after = bcadd($before, $delta, self::SCALE);
            if (bccomp($after, $this->zero(), self::SCALE) < 0) {
                foreach ($effect['rows'] as $rowId) {
                    $this->failRow(CscsUploadRow::findOrFail($rowId), 'INSUFFICIENT_HOLDING', 'The proposed debit would create a negative holding.');
                }

                $processedEffects++;
                if ($trackImportProgress) {
                    $percent = 95 + (int) floor(($processedEffects / max(1, count($effects))) * 4);
                    $this->persistProcessingProgress($batch, $progressSummary, 'CALCULATING_EFFECTS', $percent);
                }

                continue;
            }
            CscsUploadRow::whereIn('id', $effect['rows'])->update([
                'proposed_before_qty' => $before,
                'proposed_after_qty' => $after,
            ]);
            $processedEffects++;
            if ($trackImportProgress) {
                $percent = 95 + (int) floor(($processedEffects / max(1, count($effects))) * 4);
                $this->persistProcessingProgress($batch, $progressSummary, 'CALCULATING_EFFECTS', $percent);
            }
        }

        if ($trackImportProgress) {
            $this->persistProcessingProgress($batch, $progressSummary, 'FINALIZING', 99, true);
        }

        $unresolved = CscsUploadRow::where('batch_id', $batch->id)
            ->where('file_type', 'movement')
            ->whereNotIn('resolution_status', ['READY', 'CONFIRMED_REPLAY', 'RULE_EXCLUDED'])
            ->count();
        $readyRows = CscsUploadRow::where('batch_id', $batch->id)->where('resolution_status', 'READY')->count();
        $replayRows = CscsUploadRow::where('batch_id', $batch->id)->where('resolution_status', 'CONFIRMED_REPLAY')->count();
        $excludedRows = CscsUploadRow::where('batch_id', $batch->id)->where('resolution_status', 'RULE_EXCLUDED')->count();
        $net = bcsub($totalCredit, $totalDebit, self::SCALE);
        $summary = array_merge($batch->summary ?? [], [
            'processing_stage' => 'READY',
            'processing_percent' => 100,
            'transaction_groups' => $groups->count(),
            'ready_groups' => $readyGroups,
            'ready_rows' => $readyRows,
            'replay_rows' => $replayRows,
            'excluded_rows' => $excludedRows,
            'unresolved_exceptions' => $unresolved,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'net_movement' => $net,
        ]);

        $snapshot = $unresolved === 0 ? $this->snapshotHash($batch->id) : null;
        $from = $batch->workflow_status;
        $to = $markReconciled && $unresolved === 0 ? 'RECONCILED' : 'DRAFT_REVIEW';
        $attributes = [
            'workflow_status' => $to,
            'summary' => $summary,
            'reconciliation' => $summary,
            'risk_flags' => array_keys($riskFlags),
            'snapshot_hash' => $snapshot,
            'reconciled_by' => $to === 'RECONCILED' ? $actorId : null,
            'reconciled_at' => $to === 'RECONCILED' ? now() : null,
            'failure_reason' => null,
        ];
        if ($trackImportProgress) {
            DB::transaction(function () use ($batch, $attributes): void {
                $current = CscsUploadBatch::lockForUpdate()->findOrFail($batch->id);
                if ($current->workflow_status === 'CANCELLED') {
                    throw new CscsImportCancelledException;
                }
                if ($current->workflow_status !== 'PROCESSING') {
                    throw new \RuntimeException("CSCS batch {$batch->id} left PROCESSING before validation completed.");
                }

                $current->update($attributes);
            });
            $batch->refresh();
        } else {
            $batch->update($attributes);
        }
        if ($markReconciled) {
            $this->event($batch, 'RECONCILED', $from, $to, $actorId, $comment, $summary);
        }

        return $summary;
    }

    /** @return array<string, mixed> */
    public function submit(int $batchId, int $actorId, ?string $comment = null): array
    {
        return DB::transaction(function () use ($batchId, $actorId, $comment) {
            $batch = CscsUploadBatch::lockForUpdate()->findOrFail($batchId);
            $this->assertMaker($batch, $actorId);
            $this->assertState($batch, ['RECONCILED']);
            if (! hash_equals((string) $batch->snapshot_hash, $this->snapshotHash($batch->id))) {
                throw ValidationException::withMessages(['batch' => ['The reconciled snapshot changed; reconcile it again.']]);
            }

            $steps = $this->approvalSteps($batch);
            $from = $batch->workflow_status;
            $batch->update([
                'workflow_status' => 'PENDING_APPROVAL',
                'submitted_by' => $actorId,
                'submitted_at' => now(),
                'required_approval_steps' => $steps,
                'current_approval_step' => 1,
            ]);
            CscsBatchSnapshot::create([
                'batch_id' => $batch->id,
                'revision' => $batch->revision,
                'snapshot_hash' => $batch->snapshot_hash,
                'payload' => $this->snapshotPayload($batch->id),
                'reconciliation' => $batch->reconciliation ?? [],
                'risk_flags' => $batch->risk_flags ?? [],
                'source_files' => collect($batch->uploaded_files ?? [])->map(fn (array $file) => [
                    'name' => $file['name'] ?? null,
                    'original_name' => $file['original_name'] ?? $file['name'] ?? null,
                    'type' => $file['type'] ?? null,
                    'size' => $file['size'] ?? null,
                    'sha256' => $file['sha256'] ?? null,
                    'encoding' => $file['encoding'] ?? null,
                ])->values()->all(),
                'submitted_by' => $actorId,
                'submitted_at' => now(),
            ]);
            $this->event($batch, 'SUBMITTED', $from, 'PENDING_APPROVAL', $actorId, $comment, ['snapshot_hash' => $batch->snapshot_hash]);

            return $this->batchResult($batch->fresh());
        });
    }

    /** @return array<string, mixed> */
    public function raiseQuery(int $batchId, int $actorId, string $comment, array $context = []): array
    {
        return DB::transaction(function () use ($batchId, $actorId, $comment, $context) {
            $batch = CscsUploadBatch::lockForUpdate()->findOrFail($batchId);
            $this->assertState($batch, ['PENDING_APPROVAL']);
            $this->assertNotMaker($batch, $actorId);
            $this->recordDecision($batch, $actorId, 'QUERY_RAISED', $comment, $context);
            $batch->update(['workflow_status' => 'QUERY_RAISED']);
            $this->event($batch, 'QUERY_RAISED', 'PENDING_APPROVAL', 'QUERY_RAISED', $actorId, $comment, $context);

            return $this->batchResult($batch->fresh());
        });
    }

    /** @return array<string, mixed> */
    public function respondToQuery(int $batchId, int $actorId, string $comment): array
    {
        $batch = CscsUploadBatch::findOrFail($batchId);
        $this->assertMaker($batch, $actorId);
        $this->assertState($batch, ['QUERY_RAISED']);
        $this->recordDecision($batch, $actorId, 'QUERY_RESPONDED', $comment);
        $batch->update([
            'workflow_status' => 'DRAFT_REVIEW',
            'revision' => $batch->revision + 1,
            'snapshot_hash' => null,
            'submitted_by' => null,
            'submitted_at' => null,
            'current_approval_step' => null,
            'required_approval_steps' => null,
        ]);
        $this->event($batch, 'QUERY_RESPONDED', 'QUERY_RAISED', 'DRAFT_REVIEW', $actorId, $comment);

        return $this->batchResult($batch->fresh());
    }

    /** @return array<string, mixed> */
    public function approve(int $batchId, AdminUser $actor, ?string $comment = null): array
    {
        return DB::transaction(function () use ($batchId, $actor, $comment) {
            $batch = CscsUploadBatch::lockForUpdate()->findOrFail($batchId);
            $this->assertState($batch, ['PENDING_APPROVAL']);
            $this->assertNotMaker($batch, (int) $actor->id);
            $stepNo = (int) $batch->current_approval_step;
            $steps = $batch->required_approval_steps ?? [];
            $step = $this->activeApprovalStep($batch, $actor);
            if (CscsApprovalAction::where('batch_id', $batch->id)->where('revision', $batch->revision)
                ->where('step_no', $stepNo)->where('decision', 'APPROVED')->exists()) {
                throw ValidationException::withMessages(['approval' => ['This approval step is already complete.']]);
            }
            if (CscsApprovalAction::where('batch_id', $batch->id)->where('revision', $batch->revision)
                ->where('actor_id', $actor->id)->where('decision', 'APPROVED')->exists()) {
                throw ValidationException::withMessages(['approval' => ['One user cannot approve more than one step in the same batch revision.']]);
            }

            $this->recordDecision($batch, (int) $actor->id, 'APPROVED', $comment, [], $stepNo, $step['code'] ?? null);
            $lastStep = $stepNo >= count($steps);
            if ($lastStep) {
                $batch->update([
                    'workflow_status' => 'APPROVED_AWAITING_POST',
                    'approved_by' => $actor->id,
                    'approved_at' => now(),
                ]);
                $this->event($batch, 'APPROVED', 'PENDING_APPROVAL', 'APPROVED_AWAITING_POST', $actor->id, $comment);
            } else {
                $batch->update(['current_approval_step' => $stepNo + 1]);
                $this->event($batch, 'APPROVAL_STEP_COMPLETED', 'PENDING_APPROVAL', 'PENDING_APPROVAL', $actor->id, $comment, ['step' => $stepNo]);
            }

            return $this->batchResult($batch->fresh());
        });
    }

    /** @return array<string, mixed> */
    public function reject(int $batchId, AdminUser $actor, string $comment): array
    {
        return DB::transaction(function () use ($batchId, $actor, $comment) {
            $batch = CscsUploadBatch::lockForUpdate()->findOrFail($batchId);
            $this->assertState($batch, ['PENDING_APPROVAL']);
            $this->assertNotMaker($batch, (int) $actor->id);
            $this->activeApprovalStep($batch, $actor);
            $this->recordDecision($batch, (int) $actor->id, 'REJECTED', $comment);
            $batch->update(['workflow_status' => 'REJECTED', 'rejected_by' => $actor->id, 'rejected_at' => now(), 'failure_reason' => $comment]);
            $this->event($batch, 'REJECTED', 'PENDING_APPROVAL', 'REJECTED', (int) $actor->id, $comment);

            return $this->batchResult($batch->fresh());
        });
    }

    /** @return array<string, mixed> */
    public function cancel(int $batchId, int $actorId, string $comment): array
    {
        return DB::transaction(function () use ($batchId, $actorId, $comment) {
            $batch = CscsUploadBatch::lockForUpdate()->findOrFail($batchId);
            $this->assertMaker($batch, $actorId);
            $this->assertState($batch, ['PROCESSING', 'DRAFT_REVIEW', 'RECONCILED', 'QUERY_RAISED', 'STALE', 'PROCESSING_FAILED']);
            $from = $batch->workflow_status;
            $summary = $batch->summary ?? [];
            if ($from === 'PROCESSING') {
                $summary['processing_stage'] = 'CANCELLED';
            }
            $batch->update(['workflow_status' => 'CANCELLED', 'summary' => $summary, 'failure_reason' => $comment]);
            CscsUploadRow::where('batch_id', $batch->id)
                ->where('file_type', 'movement')
                ->whereNotIn('resolution_status', ['POSTED', 'CONFIRMED_REPLAY'])
                ->update(['resolution_status' => 'CANCELLED_WITH_BATCH']);
            $this->event($batch, 'CANCELLED', $from, 'CANCELLED', $actorId, $comment);

            return $this->batchResult($batch->fresh());
        });
    }

    /** @return array<string, mixed> */
    public function resolveException(int $batchId, int $rowId, int $actorId, array $resolution): array
    {
        $batch = CscsUploadBatch::findOrFail($batchId);
        $this->assertMaker($batch, $actorId);
        $this->assertState($batch, ['DRAFT_REVIEW', 'QUERY_RAISED', 'STALE']);
        $row = CscsUploadRow::where('batch_id', $batchId)->where('file_type', 'movement')->findOrFail($rowId);
        $type = $resolution['resolution_type'];
        $updates = [
            'resolved_by' => $actorId,
            'resolved_at' => now(),
            'resolution_reason' => $resolution['reason'],
        ];
        if ($type === 'MAP_ACCOUNT') {
            $sra = ShareholderRegisterAccount::where('register_id', $batch->register_id)->findOrFail($resolution['register_account_id']);
            $updates += ['proposed_sra_id' => $sra->id, 'match_method' => 'manual_mapping', 'resolution_status' => 'UNRESOLVED'];
        } elseif ($type === 'RULE_EXCLUDED') {
            $updates += ['resolution_status' => 'RULE_EXCLUDED', 'exception_code' => null, 'error_message' => 'Excluded by documented rule'];
        } elseif ($type === 'CONFIRM_REPLAY') {
            if (! $this->isPostedReplay($row)) {
                throw ValidationException::withMessages(['resolution_type' => ['No matching posted movement leg was found.']]);
            }
            $updates += ['resolution_status' => 'CONFIRMED_REPLAY', 'exception_code' => null];
        }
        $from = $batch->workflow_status;
        if (in_array($type, ['RULE_EXCLUDED', 'CONFIRM_REPLAY'], true) && $row->tran_no) {
            CscsUploadRow::where('batch_id', $batch->id)->where('tran_no', $row->tran_no)->update($updates);
        } else {
            $row->update($updates);
        }
        $batch->update(['snapshot_hash' => null, 'workflow_status' => 'DRAFT_REVIEW']);
        $this->event($batch, 'EXCEPTION_RESOLVED', $from, 'DRAFT_REVIEW', $actorId, $resolution['reason'], ['row_id' => $row->id, 'type' => $type]);

        return ['row' => $row->fresh(), 'batch' => $this->batchResult($batch->fresh())];
    }

    /** @return array<string, mixed> */
    public function queueForPosting(int $batchId, AdminUser $actor, ?string $comment = null): array
    {
        return DB::transaction(function () use ($batchId, $actor, $comment) {
            $batch = CscsUploadBatch::lockForUpdate()->findOrFail($batchId);
            $this->assertState($batch, ['APPROVED_AWAITING_POST', 'POSTING_FAILED']);
            $this->assertNotMaker($batch, (int) $actor->id);
            $policy = CscsApprovalPolicy::where('is_active', true)->first();
            if ($policy && ! $policy->checker_can_post && (int) $batch->approved_by === (int) $actor->id) {
                abort(403, 'The active CSCS policy requires a separate poster.');
            }
            $from = $batch->workflow_status;
            $batch->update([
                'workflow_status' => 'POSTING_QUEUED',
                'posted_by' => $actor->id,
                'posting_started_at' => null,
                'failure_reason' => null,
            ]);
            $this->event($batch, 'POSTING_QUEUED', $from, 'POSTING_QUEUED', $actor->id, $comment);

            return $this->batchResult($batch->fresh());
        });
    }

    /** @return array<string, mixed> */
    public function postingReadiness(int $batchId): array
    {
        $batch = CscsUploadBatch::with('register')->findOrFail($batchId);
        $rows = CscsUploadRow::where('batch_id', $batch->id)
            ->where('file_type', 'movement')
            ->get();
        $readyRows = $rows->where('resolution_status', 'READY');

        $snapshotUnchanged = filled($batch->snapshot_hash)
            && hash_equals((string) $batch->snapshot_hash, $this->snapshotHash($batch->id));
        $securityMappingsValid = $readyRows->groupBy('sec_code')->every(function (Collection $securityRows, string|int $securityCode) use ($batch): bool {
            $mapping = CscsSecurityMapping::where('security_code', (string) $securityCode)
                ->where('is_active', true)
                ->first();

            return $mapping
                && (int) $mapping->register_id === (int) $batch->register_id
                && (int) $mapping->share_class_id === (int) $securityRows->first()->proposed_share_class_id;
        });
        $accountMappingsValid = $readyRows->every(function (CscsUploadRow $row) use ($batch): bool {
            if (! $row->proposed_share_class_id) {
                return false;
            }
            if (! $row->proposed_sra_id) {
                return filled($row->identifier_type) && filled($row->identifier_value);
            }

            return ShareholderRegisterAccount::whereKey($row->proposed_sra_id)
                ->where('register_id', $batch->register_id)
                ->exists();
        });
        $holdingsCurrent = $readyRows
            ->groupBy(fn (CscsUploadRow $row) => ($row->proposed_sra_id ?: 'new:'.$row->identifier_value).':'.$row->proposed_share_class_id)
            ->every(function (Collection $effectRows): bool {
                $first = $effectRows->first();
                $current = $first->proposed_sra_id
                    ? SharePosition::where('sra_id', $first->proposed_sra_id)
                        ->where('share_class_id', $first->proposed_share_class_id)
                        ->value('quantity') ?? 0
                    : 0;

                return bccomp($this->decimal($current), $this->decimal($first->proposed_before_qty ?? 0), self::SCALE) === 0;
            });
        $movementsNotPosted = $readyRows->every(fn (CscsUploadRow $row): bool => ! $row->replay_key
            || ! CscsUploadRow::where('fingerprint', $row->replay_key)
                ->where('status', 'posted')
                ->where('id', '!=', $row->id)
                ->exists());
        $blockingExceptions = $rows
            ->whereNotIn('resolution_status', ['READY', 'CONFIRMED_REPLAY', 'RULE_EXCLUDED', 'POSTED'])
            ->count();
        $statusAllowsPosting = in_array($batch->workflow_status, ['APPROVED_AWAITING_POST', 'POSTING_FAILED'], true);

        $checks = [
            'status_allows_posting' => ['passed' => $statusAllowsPosting, 'label' => 'Batch approved for posting'],
            'snapshot_hash_unchanged' => ['passed' => $snapshotUnchanged, 'label' => 'Snapshot hash unchanged'],
            'security_mappings_valid' => ['passed' => $securityMappingsValid, 'label' => 'Security mappings active and unchanged'],
            'account_mappings_valid' => ['passed' => $accountMappingsValid, 'label' => 'Account mappings remain valid'],
            'holdings_current' => ['passed' => $holdingsCurrent, 'label' => 'Current holdings match the approved snapshot'],
            'movements_not_posted' => ['passed' => $movementsNotPosted, 'label' => 'Movements have not already been posted'],
            'no_blocking_exceptions' => ['passed' => $blockingExceptions === 0, 'label' => 'No blocking exceptions remain'],
        ];

        return [
            'batch_id' => $batch->id,
            'status' => $batch->workflow_status,
            'snapshot_hash' => $batch->snapshot_hash,
            'ready' => collect($checks)->every(fn (array $check): bool => $check['passed']),
            'checks' => $checks,
            'summary' => [
                'records_to_post' => $readyRows->count(),
                'affected_accounts' => $readyRows
                    ->map(fn (CscsUploadRow $row) => $row->proposed_sra_id ?: 'new:'.$row->identifier_value)
                    ->unique()
                    ->count(),
                'blocking_exceptions' => $blockingExceptions,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function post(int $batchId, AdminUser $actor, ?string $comment = null): array
    {
        $batch = CscsUploadBatch::findOrFail($batchId);
        $this->assertState($batch, ['APPROVED_AWAITING_POST', 'POSTING_FAILED', 'POSTING_QUEUED']);
        $this->assertNotMaker($batch, (int) $actor->id);
        $policy = CscsApprovalPolicy::where('is_active', true)->first();
        if ($policy && ! $policy->checker_can_post && (int) $batch->approved_by === (int) $actor->id) {
            abort(403, 'The active CSCS policy requires a separate poster.');
        }

        try {
            DB::transaction(function () use ($batchId, $actor, $comment) {
                $batch = CscsUploadBatch::lockForUpdate()->findOrFail($batchId);
                $this->assertState($batch, ['APPROVED_AWAITING_POST', 'POSTING_FAILED', 'POSTING_QUEUED']);
                if (! hash_equals((string) $batch->snapshot_hash, $this->snapshotHash($batch->id))) {
                    $batch->update(['workflow_status' => 'STALE', 'failure_reason' => 'The approved snapshot changed.']);
                    $this->event($batch, 'STALE', $batch->workflow_status, 'STALE', $actor->id, 'The approved snapshot changed.');
                    throw ValidationException::withMessages(['batch' => ['The approved snapshot is stale.']]);
                }

                $batch->update(['workflow_status' => 'POSTING', 'posted_by' => $actor->id, 'posting_started_at' => now(), 'failure_reason' => null]);
                $rows = CscsUploadRow::where('batch_id', $batch->id)
                    ->where('resolution_status', 'READY')->orderBy('id')->lockForUpdate()->get();
                $this->assertSecurityMappings($batch, $rows);
                $this->assertOpeningBalances($batch, $rows);

                foreach ($rows as $row) {
                    if (CscsUploadRow::where('fingerprint', $row->replay_key)->where('status', 'posted')->where('id', '!=', $row->id)->exists()) {
                        throw ValidationException::withMessages(['replay' => ["Movement row {$row->id} was already posted."]]);
                    }
                    $sra = $row->proposed_sra_id
                        ? ShareholderRegisterAccount::findOrFail($row->proposed_sra_id)
                        : $this->createProposedAccount($batch, $row, (int) $actor->id);
                    $position = SharePosition::where('sra_id', $sra->id)
                        ->where('share_class_id', $row->proposed_share_class_id)->lockForUpdate()->first();
                    $before = $this->decimal($position?->quantity ?? 0);
                    $quantity = $this->decimal($row->volume);
                    $delta = $row->sign === '-' ? '-'.$quantity : $quantity;
                    $after = bcadd($before, $delta, self::SCALE);
                    if (bccomp($after, $this->zero(), self::SCALE) < 0) {
                        throw ValidationException::withMessages(['quantity' => ["Insufficient holding for row {$row->id}."]]);
                    }
                    $position ??= SharePosition::create([
                        'sra_id' => $sra->id,
                        'share_class_id' => $row->proposed_share_class_id,
                        'quantity' => $this->zero(),
                        'holding_mode' => 'demat',
                    ]);
                    $position->update(['quantity' => $after, 'last_updated_at' => now()]);
                    $tx = ShareTransaction::create([
                        'sra_id' => $sra->id,
                        'share_class_id' => $row->proposed_share_class_id,
                        'tx_type' => $row->sign === '-' ? 'transfer_out' : 'transfer_in',
                        'quantity' => $quantity,
                        'tx_ref' => $batch->batch_type === 'REVERSAL'
                            ? 'CSCS-REV-'.$batch->id.'-'.$row->tran_no.'-'.$row->tran_seq
                            : 'CSCS-'.$row->tran_no.'-'.$row->tran_seq,
                        'tx_date' => $row->trade_date,
                        'created_by' => $actor->id,
                    ]);
                    $row->update([
                        'status' => 'posted',
                        'resolution_status' => 'POSTED',
                        'sra_id' => $sra->id,
                        'shareholder_id' => $sra->shareholder_id,
                        'share_class_id' => $row->proposed_share_class_id,
                        'share_transaction_id' => $tx->id,
                        'actual_before_qty' => $before,
                        'actual_after_qty' => $after,
                        'before_qty' => $before,
                        'delta_qty' => $delta,
                        'after_qty' => $after,
                        'fingerprint' => $row->replay_key,
                    ]);
                }

                $verification = $this->verifyPostedEffects($batch);
                $reconciliation = $batch->reconciliation ?? [];
                $reconciliation['post_verification'] = $verification;
                $batch->update([
                    'status' => 'completed',
                    'workflow_status' => 'POSTED',
                    'posted_at' => now(),
                    'failure_reason' => null,
                    'reconciliation' => $reconciliation,
                ]);
                $this->event($batch, 'POST_VERIFIED', 'POSTING', 'POSTING', $actor->id, null, $verification);
                $this->event($batch, 'POSTED', 'POSTING', 'POSTED', $actor->id, $comment, ['posted_rows' => $rows->count(), 'verification' => $verification]);
            }, 3);
        } catch (ValidationException $e) {
            $message = (string) collect($e->errors())->flatten()->first();
            $stale = str_contains(strtolower($message), 'stale') || str_contains(strtolower($message), 'changed after approval');
            CscsUploadBatch::whereKey($batchId)->update([
                'workflow_status' => $stale ? 'STALE' : 'POSTING_FAILED',
                'failure_reason' => $message,
            ]);
            if ($stale && ($failedBatch = CscsUploadBatch::find($batchId))) {
                $this->event($failedBatch, 'STALE', 'APPROVED_AWAITING_POST', 'STALE', $actor->id, $message);
            }
            throw $e;
        } catch (\Throwable $e) {
            $reference = (string) Str::uuid();
            Log::error('CSCS posting failed', ['batch_id' => $batchId, 'reference' => $reference, 'error' => $e->getMessage()]);
            CscsUploadBatch::whereKey($batchId)->update([
                'workflow_status' => 'POSTING_FAILED',
                'failure_reason' => "A technical posting error occurred. Reference: {$reference}",
            ]);
            if ($failedBatch = CscsUploadBatch::find($batchId)) {
                $this->event($failedBatch, 'POSTING_FAILED', 'POSTING', 'POSTING_FAILED', $actor->id, "Technical failure reference: {$reference}");
            }
            throw $e;
        }

        return $this->batchResult(CscsUploadBatch::findOrFail($batchId));
    }

    /** @return Collection<int, array<string, mixed>> */
    public function accountEffects(int $batchId): Collection
    {
        return CscsUploadRow::where('batch_id', $batchId)
            ->whereIn('resolution_status', ['READY', 'POSTED'])
            ->get()
            ->groupBy(fn (CscsUploadRow $row) => ($row->proposed_sra_id ?: 'new:'.$row->identifier_value).':'.$row->proposed_share_class_id)
            ->map(function (Collection $rows) {
                $first = $rows->first();
                $debit = $this->zero();
                $credit = $this->zero();
                foreach ($rows as $row) {
                    if ($row->sign === '-') {
                        $debit = bcadd($debit, $this->decimal($row->volume), self::SCALE);
                    } else {
                        $credit = bcadd($credit, $this->decimal($row->volume), self::SCALE);
                    }
                }

                return [
                    'register_account_id' => $first->proposed_sra_id,
                    'identifier_value' => $first->identifier_value,
                    'share_class_id' => $first->proposed_share_class_id,
                    'current_quantity' => $first->proposed_before_qty,
                    'total_debit' => $debit,
                    'total_credit' => $credit,
                    'net_movement' => bcsub($credit, $debit, self::SCALE),
                    'proposed_quantity' => $first->proposed_after_qty,
                    'is_new_account' => ! $first->proposed_sra_id,
                    'row_count' => $rows->count(),
                ];
            })->values();
    }

    /** @return array<string, mixed> */
    public function createReversal(
        int $sourceBatchId,
        int $actorId,
        string $reason,
        string $effectiveDate,
        array $transactionNumbers = []
    ): array {
        return DB::transaction(function () use ($sourceBatchId, $actorId, $reason, $effectiveDate, $transactionNumbers) {
            $source = CscsUploadBatch::lockForUpdate()->findOrFail($sourceBatchId);
            $this->assertState($source, ['POSTED']);
            $query = CscsUploadRow::where('batch_id', $source->id)->where('status', 'posted')->orderBy('id');
            if ($transactionNumbers) {
                $query->whereIn('tran_no', $transactionNumbers);
            }
            $sourceRows = $query->get();
            if ($sourceRows->isEmpty()) {
                throw ValidationException::withMessages(['transaction_numbers' => ['No posted movement rows matched the requested reversal.']]);
            }
            foreach ($sourceRows->groupBy('tran_no') as $group) {
                if ($group->count() !== 2) {
                    throw ValidationException::withMessages(['transaction_numbers' => ['A reversal must include every leg of each transaction group.']]);
                }
            }

            $batch = CscsUploadBatch::create([
                'uploaded_by' => $actorId,
                'register_id' => $source->register_id,
                'status' => 'completed',
                'workflow_status' => 'DRAFT_REVIEW',
                'batch_type' => 'REVERSAL',
                'source_batch_id' => $source->id,
                'business_reference' => 'REV-'.$source->id.'-'.now()->format('YmdHis'),
                'description' => $reason,
                'uploaded_files' => [],
                'summary' => ['source_batch_id' => $source->id, 'movement_rows' => $sourceRows->count(), 'master_rows' => 0],
            ]);

            foreach ($sourceRows as $sourceRow) {
                $attributes = [
                    'batch_id' => $batch->id,
                    'file_type' => 'movement',
                    'source_filename' => 'reversal-of-batch-'.$source->id,
                    'row_number' => $sourceRow->row_number,
                    'tran_no' => $sourceRow->tran_no,
                    'tran_seq' => $sourceRow->tran_seq,
                    'transaction_group_key' => $sourceRow->tran_no,
                    'trade_date' => $effectiveDate,
                    'sec_code' => $sourceRow->sec_code,
                    'identifier_type' => $sourceRow->identifier_type,
                    'identifier_value' => $sourceRow->identifier_value,
                    'sign' => $sourceRow->sign === '+' ? '-' : '+',
                    'volume' => $this->decimal($sourceRow->volume),
                    'status' => 'skipped',
                    'resolution_status' => 'UNRESOLVED',
                    'proposed_sra_id' => $sourceRow->sra_id,
                    'match_method' => 'approved_reversal',
                    'raw_line' => 'REVERSAL OF CSCS ROW '.$sourceRow->id,
                    'extra_details' => ['source_batch_id' => $source->id, 'source_row_id' => $sourceRow->id, 'reason' => $reason],
                ];
                $attributes['replay_key'] = $this->replayKey($attributes);
                CscsUploadRow::create($attributes);
            }

            $this->event($batch, 'REVERSAL_CREATED', null, 'DRAFT_REVIEW', $actorId, $reason, ['source_batch_id' => $source->id]);
            $this->validateDraft($batch, $actorId, false);

            return $this->batchResult($batch->fresh());
        });
    }

    private function detectFileType(UploadedFile $file): string
    {
        $handle = fopen($file->getRealPath(), 'rb');
        $lines = [];
        if ($handle) {
            while (($candidate = fgets($handle)) !== false && count($lines) < max(2, (int) config('cscs.file_detection_sample_lines', 25))) {
                $candidate = rtrim($candidate, "\r\n");
                if ($candidate !== '') {
                    $lines[] = $candidate;
                }
            }
            fclose($handle);
        }

        $movementMatches = collect($lines)->filter(fn (string $line) => $this->looksLikeMovementLine($line))->count();
        $masterMatches = collect($lines)->filter(fn (string $line) => $this->looksLikeMasterLine($line))->count();
        if ($lines !== [] && $movementMatches === count($lines)) {
            return 'movement';
        }
        if ($lines !== [] && $masterMatches === count($lines)) {
            return 'master';
        }

        throw ValidationException::withMessages([
            'files' => ["Unsupported or internally inconsistent CSCS format for {$file->getClientOriginalName()}."],
        ]);
    }

    private function looksLikeMovementLine(string $line): bool
    {
        if (strlen($line) !== 114) {
            return false;
        }

        return preg_match('/^\s*\d{14,16}/', substr($line, 0, 16)) === 1
            && preg_match('/^\d{8}$/', substr($line, 23, 8)) === 1
            && in_array(substr($line, 73, 1), ['+', '-'], true)
            && trim(substr($line, 31, 21)) !== ''
            && trim(substr($line, 74, 40)) !== '';
    }

    private function looksLikeMasterLine(string $line): bool
    {
        if (strlen($line) !== 393) {
            return false;
        }

        $identifier = trim(substr($line, 0, 12));
        $name = trim(substr($line, 12, 80));

        return $identifier !== '' && $name !== ''
            && preg_match('/^[A-Z0-9._-]+$/i', $identifier) === 1;
    }

    private function assertUtf8File(UploadedFile $file): void
    {
        $content = file_get_contents($file->getRealPath());
        if ($content === false || str_contains($content, "\0") || ! mb_check_encoding($content, 'UTF-8')) {
            throw ValidationException::withMessages([
                'files' => ["{$file->getClientOriginalName()} must be valid UTF-8 text without binary content."],
            ]);
        }
    }

    private function duplicateFileBatchId(string $hash, int $registerId, int $currentBatchId): ?int
    {
        $batches = CscsUploadBatch::where('register_id', $registerId)
            ->where('id', '!=', $currentBatchId)
            ->whereNotIn('workflow_status', ['PROCESSING_FAILED', 'CANCELLED'])
            ->get(['id', 'uploaded_files']);

        foreach ($batches as $batch) {
            if (collect($batch->uploaded_files ?? [])->contains(fn (array $file) => hash_equals((string) ($file['sha256'] ?? ''), $hash))) {
                return (int) $batch->id;
            }
        }

        return null;
    }

    /** @return array<string, string|null> */
    private function parseMasterLine(string $line): array
    {
        $identifier = trim(substr($line, 0, 12));
        $name = trim(substr($line, 12, 80));
        $fixedEmail = trim(substr($line, 273, 39));
        $fixedPhone = trim(substr($line, 313, 14));
        preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', substr($line, 92), $email);
        preg_match('/(?<!\d)\d{10,13}(?!\d)/', substr($line, 92), $phone);

        return [
            'identifier' => $identifier,
            'full_name' => preg_replace('/\s+/', ' ', $name) ?: $identifier,
            'email' => filter_var($fixedEmail, FILTER_VALIDATE_EMAIL) ? $fixedEmail : ($email[0] ?? null),
            'phone' => preg_match('/^\d{10,13}$/', $fixedPhone) ? $fixedPhone : ($phone[0] ?? null),
        ];
    }

    /** @return array<string, mixed> */
    private function parseMovementLine(string $line, int $rowNumber, string $filename): array
    {
        $base = ['source_filename' => $filename, 'row_number' => $rowNumber, 'raw_line' => $line];
        if (strlen($line) !== 114) {
            return $base + ['parse_error' => 'Movement record must contain exactly 114 characters.'];
        }
        $tranNo = trim(substr($line, 0, 16));
        $sequence = trim(substr($line, 17, 6));
        $dateRaw = trim(substr($line, 23, 8));
        $securityCode = strtoupper(trim(substr($line, 31, 21)));
        $volumeRaw = trim(substr($line, 52, 18));
        $sign = substr($line, 73, 1);
        $identifier = trim(substr($line, 74, 40));
        $date = DateTimeImmutable::createFromFormat('!Ymd', $dateRaw);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if (! preg_match('/^\d{14,16}$/', $tranNo)
            || ! preg_match('/^\d{1,2}$/', $sequence)
            || ! $date || ($dateErrors !== false && ($dateErrors['warning_count'] || $dateErrors['error_count']))
            || ! preg_match('/^[A-Z0-9._-]+$/', $securityCode)
            || ! preg_match('/^\d+$/', $volumeRaw)
            || bccomp($volumeRaw, '0', self::SCALE) <= 0
            || ! in_array($sign, ['+', '-'], true)
            || ! preg_match('/^[A-Z0-9._-]+$/i', $identifier)) {
            return $base + ['parse_error' => 'Invalid CSCS movement record fields.'];
        }

        return $base + [
            'tran_no' => $tranNo,
            'tran_seq' => $sequence,
            'trade_date' => $date->format('Y-m-d'),
            'sec_code' => $securityCode,
            'volume' => $this->decimal($volumeRaw),
            'sign' => $sign,
            'identifier_type' => str_starts_with(strtoupper($identifier), 'C') ? 'chn' : 'cscs_account_no',
            'identifier_value' => $identifier,
        ];
    }

    private function createInvalidRow(CscsUploadBatch $batch, array $parsed): void
    {
        CscsUploadRow::create([
            'batch_id' => $batch->id,
            'file_type' => 'movement',
            'source_filename' => $parsed['source_filename'],
            'row_number' => $parsed['row_number'],
            'status' => 'failed',
            'resolution_status' => 'INVALID',
            'exception_code' => 'INVALID_FORMAT',
            'error_message' => $parsed['parse_error'],
            'raw_line' => $parsed['raw_line'],
        ]);
    }

    /** @return array{0:string,1:string}|null */
    private function validateTransactionGroup(Collection $group): ?array
    {
        if ($group->count() !== 2) {
            return ['INCOMPLETE_TRANSACTION_GROUP', 'A transfer must contain exactly two movement legs.'];
        }
        if ($group->pluck('tran_seq')->unique()->count() !== 2) {
            return ['DUPLICATE_SEQUENCE', 'Transaction sequences must be unique within the transfer.'];
        }
        if ($group->where('sign', '+')->count() !== 1 || $group->where('sign', '-')->count() !== 1) {
            return ['UNBALANCED_SIGNS', 'A transfer must contain one debit and one credit.'];
        }
        if ($group->pluck('volume')->map(fn ($v) => $this->decimal($v))->unique()->count() !== 1) {
            return ['UNBALANCED_QUANTITY', 'Debit and credit quantities do not match.'];
        }
        if ($group->pluck('sec_code')->unique()->count() !== 1 || $group->pluck('trade_date')->map(fn ($d) => (string) $d)->unique()->count() !== 1) {
            return ['CONFLICTING_TRANSACTION_DETAILS', 'Transfer legs have different security codes or trade dates.'];
        }

        return null;
    }

    /** @return array{resolved:bool,sra_id:?int,method:?string,new_account:bool,code:?string,message:?string} */
    private function resolveAccountForPreview(CscsUploadRow $row, int $registerId): array
    {
        if ($row->proposed_sra_id && $row->match_method === 'manual_mapping') {
            $sra = ShareholderRegisterAccount::where('register_id', $registerId)->find($row->proposed_sra_id);
            if ($sra) {
                return ['resolved' => true, 'sra_id' => $sra->id, 'method' => 'manual_mapping', 'new_account' => false, 'code' => null, 'message' => null];
            }
        }
        $sra = ShareholderRegisterAccount::where('register_id', $registerId)
            ->whereHas('externalIdentifiers', fn ($q) => $q->where('identifier_type', $row->identifier_type)->where('identifier_value', $row->identifier_value))
            ->first();
        $method = 'external_identifier';
        if (! $sra) {
            $column = $row->identifier_type === 'chn' ? 'chn' : 'cscs_account_no';
            $sra = ShareholderRegisterAccount::where('register_id', $registerId)->where($column, $row->identifier_value)->first();
            $method = $column;
        }
        if ($sra) {
            return ['resolved' => true, 'sra_id' => $sra->id, 'method' => $method, 'new_account' => false, 'code' => null, 'message' => null];
        }

        $profile = data_get($row->extra_details, 'master_profile');
        $profileCount = (int) data_get($row->extra_details, 'master_profile_count', $profile ? 1 : 0);
        if ($profileCount > 1) {
            return ['resolved' => false, 'sra_id' => null, 'method' => null, 'new_account' => false, 'code' => 'AMBIGUOUS_MASTER_RECORD', 'message' => 'More than one master record uses this identifier; manual correction is required.'];
        }
        if (! $profile) {
            return ['resolved' => false, 'sra_id' => null, 'method' => null, 'new_account' => false, 'code' => 'MASTER_RECORD_MISSING', 'message' => 'No matching master record exists.'];
        }
        if (empty($profile['email']) || empty($profile['phone']) || empty($profile['full_name'])) {
            return ['resolved' => false, 'sra_id' => null, 'method' => null, 'new_account' => false, 'code' => 'INSUFFICIENT_MASTER_DATA', 'message' => 'The master record lacks the minimum name, email, or phone information.'];
        }
        $profileMatches = Shareholder::where('email', $profile['email'])->orWhere('phone', $profile['phone'])->get();
        $exactShareholder = $profileMatches->first(fn (Shareholder $shareholder) => $shareholder->email === $profile['email'] && $shareholder->phone === $profile['phone']);
        if ($profileMatches->isNotEmpty() && ! $exactShareholder) {
            return ['resolved' => false, 'sra_id' => null, 'method' => null, 'new_account' => false, 'code' => 'AMBIGUOUS_PROFILE_MATCH', 'message' => 'Email or phone belongs to a different shareholder; manual resolution is required.'];
        }
        if ($exactShareholder) {
            $profileSra = ShareholderRegisterAccount::where('shareholder_id', $exactShareholder->id)->where('register_id', $registerId)->first();
            if ($profileSra) {
                return ['resolved' => true, 'sra_id' => $profileSra->id, 'method' => 'email_phone', 'new_account' => false, 'code' => null, 'message' => null];
            }
        }
        if ($row->sign === '-') {
            return ['resolved' => false, 'sra_id' => null, 'method' => null, 'new_account' => false, 'code' => 'DEBIT_ACCOUNT_NOT_FOUND', 'message' => 'A new account cannot fund a debit movement.'];
        }

        return ['resolved' => true, 'sra_id' => null, 'method' => 'proposed_new_account', 'new_account' => true, 'code' => null, 'message' => null];
    }

    private function createProposedAccount(CscsUploadBatch $batch, CscsUploadRow $row, int $actorId): ShareholderRegisterAccount
    {
        $profile = data_get($row->extra_details, 'master_profile');
        if (! $profile || empty($profile['email']) || empty($profile['phone'])) {
            throw ValidationException::withMessages(['profile' => ["Missing approved master data for {$row->identifier_value}."]]);
        }
        $shareholder = Shareholder::where('email', $profile['email'])->where('phone', $profile['phone'])->first();
        if (! $shareholder) {
            $parts = preg_split('/\s+/', trim($profile['full_name'])) ?: [];
            $first = array_shift($parts) ?: 'UNKNOWN';
            $last = $parts ? array_pop($parts) : null;
            $shareholder = Shareholder::create([
                'account_no' => $this->accountNumberService->generate(),
                'holder_type' => 'individual',
                'first_name' => $first,
                'last_name' => $last,
                'middle_name' => $parts ? implode(' ', $parts) : null,
                'full_name' => $profile['full_name'],
                'email' => $profile['email'],
                'phone' => $profile['phone'],
                'status' => 'active',
            ]);
        }
        $sra = ShareholderRegisterAccount::firstOrCreate(
            ['shareholder_id' => $shareholder->id, 'register_id' => $batch->register_id],
            ['shareholder_no' => ShareholderRegisterAccount::generateAccountNumber($shareholder->id), 'status' => 'active']
        );
        $column = $row->identifier_type === 'chn' ? 'chn' : 'cscs_account_no';
        if (! $sra->{$column}) {
            $sra->update([$column => $row->identifier_value]);
        }
        SraExternalIdentifier::firstOrCreate(
            ['identifier_type' => $row->identifier_type, 'identifier_value' => $row->identifier_value],
            ['sra_id' => $sra->id, 'source' => 'cscs_upload', 'created_by' => $actorId]
        );
        CscsUploadRow::where('batch_id', $batch->id)->where('identifier_value', $row->identifier_value)
            ->whereNull('proposed_sra_id')->update(['proposed_sra_id' => $sra->id]);

        return $sra;
    }

    private function assertOpeningBalances(CscsUploadBatch $batch, Collection $rows): void
    {
        foreach ($rows->groupBy(fn (CscsUploadRow $row) => ($row->proposed_sra_id ?: 'new:'.$row->identifier_value).':'.$row->proposed_share_class_id) as $effectRows) {
            $first = $effectRows->first();
            $current = $this->zero();
            if ($first->proposed_sra_id) {
                $current = $this->decimal(SharePosition::where('sra_id', $first->proposed_sra_id)
                    ->where('share_class_id', $first->proposed_share_class_id)->lockForUpdate()->value('quantity') ?? 0);
            }
            if (bccomp($current, $this->decimal($first->proposed_before_qty), self::SCALE) !== 0) {
                $batch->update(['workflow_status' => 'STALE', 'failure_reason' => 'A holding changed after approval.']);
                throw ValidationException::withMessages(['batch' => ['A holding changed after approval; the batch must be reconciled again.']]);
            }
        }
    }

    private function assertSecurityMappings(CscsUploadBatch $batch, Collection $rows): void
    {
        foreach ($rows->groupBy('sec_code') as $securityCode => $securityRows) {
            $mapping = CscsSecurityMapping::where('security_code', $securityCode)->where('is_active', true)->first();
            $approvedClassId = (int) $securityRows->first()->proposed_share_class_id;
            if (! $mapping || (int) $mapping->register_id !== (int) $batch->register_id || (int) $mapping->share_class_id !== $approvedClassId) {
                throw ValidationException::withMessages(['batch' => ["Security mapping {$securityCode} changed after approval."]]);
            }
        }
    }

    /** @return array<string, mixed> */
    private function verifyPostedEffects(CscsUploadBatch $batch): array
    {
        $rows = CscsUploadRow::where('batch_id', $batch->id)->where('resolution_status', 'POSTED')->get();
        $expected = $batch->reconciliation ?? [];
        $actualDebit = $this->zero();
        $actualCredit = $this->zero();
        foreach ($rows as $row) {
            $quantity = $this->decimal($row->volume);
            if ($row->sign === '-') {
                $actualDebit = bcadd($actualDebit, $quantity, self::SCALE);
            } else {
                $actualCredit = bcadd($actualCredit, $quantity, self::SCALE);
            }
        }
        $actualNet = bcsub($actualCredit, $actualDebit, self::SCALE);
        $checks = [
            'posted_row_count' => $rows->count() === (int) ($expected['ready_rows'] ?? -1),
            'share_transaction_count' => $rows->whereNotNull('share_transaction_id')->unique('share_transaction_id')->count() === $rows->count(),
            'unique_replay_fingerprints' => $rows->whereNotNull('fingerprint')->unique('fingerprint')->count() === $rows->count(),
            'debit_total' => bccomp($actualDebit, $this->decimal($expected['total_debit'] ?? 0), self::SCALE) === 0,
            'credit_total' => bccomp($actualCredit, $this->decimal($expected['total_credit'] ?? 0), self::SCALE) === 0,
            'net_movement' => bccomp($actualNet, $this->decimal($expected['net_movement'] ?? 0), self::SCALE) === 0,
            'holding_effects' => true,
        ];
        foreach ($rows->groupBy(fn (CscsUploadRow $row) => $row->sra_id.':'.$row->share_class_id) as $effectRows) {
            $first = $effectRows->first();
            $actual = $this->decimal(SharePosition::where('sra_id', $first->sra_id)->where('share_class_id', $first->share_class_id)->value('quantity') ?? 0);
            if (bccomp($actual, $this->decimal($first->proposed_after_qty), self::SCALE) !== 0) {
                $checks['holding_effects'] = false;
            }
        }

        if (in_array(false, $checks, true)) {
            throw new \RuntimeException('Post-posting verification failed: '.implode(', ', array_keys(array_filter($checks, fn (bool $passed) => ! $passed))));
        }

        return [
            'status' => 'VERIFIED',
            'checks' => $checks,
            'posted_rows' => $rows->count(),
            'total_debit' => $actualDebit,
            'total_credit' => $actualCredit,
            'net_movement' => $actualNet,
            'verified_at' => now()->toIso8601String(),
        ];
    }

    private function failGroup(Collection $group, string $code, string $message): void
    {
        foreach ($group as $row) {
            $this->failRow($row, $code, $message);
        }
    }

    private function failRow(CscsUploadRow $row, string $code, string $message): void
    {
        $row->update(['resolution_status' => 'UNRESOLVED', 'exception_code' => $code, 'error_message' => $message]);
    }

    private function isPostedReplay(CscsUploadRow $row): bool
    {
        return $row->replay_key && CscsUploadRow::where('fingerprint', $row->replay_key)
            ->where('status', 'posted')->where('id', '!=', $row->id)->exists();
    }

    /** @return array<int, array<string, mixed>> */
    private function approvalSteps(CscsUploadBatch $batch): array
    {
        $policy = CscsApprovalPolicy::where('is_active', true)->first();
        $steps = [['step' => 1, 'code' => 'CHECKER', 'roles' => $policy?->checker_roles ?? []]];
        $quantityTrigger = $policy?->additional_approval_quantity
            && bccomp($this->decimal(data_get($batch->summary, 'total_debit', 0)), $this->decimal($policy->additional_approval_quantity), self::SCALE) >= 0;
        $configuredRiskFlags = config('cscs.additional_approval_risk_flags', []);
        $riskTrigger = $policy && array_intersect($batch->risk_flags ?? [], $configuredRiskFlags);
        if ($quantityTrigger || $riskTrigger) {
            $steps[] = ['step' => 2, 'code' => 'OVERSIGHT', 'roles' => $policy->additional_approval_roles ?? ['Internal Audit', 'Compliance']];
        }

        return $steps;
    }

    private function recordDecision(
        CscsUploadBatch $batch,
        int $actorId,
        string $decision,
        ?string $comment,
        array $context = [],
        ?int $step = null,
        ?string $role = null
    ): void {
        CscsApprovalAction::create([
            'batch_id' => $batch->id,
            'revision' => $batch->revision,
            'step_no' => $step ?? $batch->current_approval_step,
            'role_code' => $role,
            'decision' => $decision,
            'actor_id' => $actorId,
            'comment' => $comment,
            'context' => $context,
            'acted_at' => now(),
        ]);
    }

    private function event(
        CscsUploadBatch $batch,
        string $type,
        ?string $from,
        ?string $to,
        ?int $actorId,
        ?string $comment = null,
        array $metadata = []
    ): void {
        CscsWorkflowEvent::create([
            'batch_id' => $batch->id,
            'event_type' => $type,
            'from_status' => $from,
            'to_status' => $to,
            'actor_id' => $actorId,
            'comment' => $comment,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }

    private function snapshotHash(int $batchId): string
    {
        return hash('sha256', json_encode($this->snapshotPayload($batchId), JSON_THROW_ON_ERROR));
    }

    /** @return array<int, array<int, mixed>> */
    private function snapshotPayload(int $batchId): array
    {
        return CscsUploadRow::where('batch_id', $batchId)->where('file_type', 'movement')->orderBy('id')->get()->map(fn (CscsUploadRow $row) => [
            $row->id, $row->raw_line, $row->resolution_status, $row->proposed_sra_id,
            $row->proposed_share_class_id, $row->proposed_before_qty, $row->proposed_delta_qty,
            $row->proposed_after_qty, $row->replay_key, $row->resolution_reason,
        ])->all();
    }

    private function replayKey(array|CscsUploadRow $row): string
    {
        $get = fn (string $key) => is_array($row) ? $row[$key] : $row->{$key};

        return hash('sha256', implode('|', [
            'CSCS', $get('tran_no'), $get('tran_seq'), (string) $get('trade_date'),
            strtoupper((string) $get('sec_code')), $get('identifier_type'), strtoupper((string) $get('identifier_value')),
            $get('sign'), $this->decimal($get('volume')),
        ]));
    }

    private function assertMaker(CscsUploadBatch $batch, int $actorId): void
    {
        if ((int) $batch->uploaded_by !== $actorId) {
            abort(403, 'Only the batch maker may perform this action.');
        }
    }

    private function assertNotMaker(CscsUploadBatch $batch, int $actorId): void
    {
        if ((int) $batch->uploaded_by === $actorId) {
            abort(403, 'Maker-checker separation prevents this action.');
        }
    }

    /** @return array<string, mixed> */
    private function activeApprovalStep(CscsUploadBatch $batch, AdminUser $actor): array
    {
        $step = collect($batch->required_approval_steps ?? [])->firstWhere('step', (int) $batch->current_approval_step);
        if (! $step) {
            throw ValidationException::withMessages(['approval' => ['No active approval step is configured.']]);
        }
        $roles = $step['roles'] ?? [];
        if ($roles && ! $actor->hasAnyRole($roles)) {
            abort(403, 'You are not authorized for the active CSCS approval step.');
        }

        return $step;
    }

    private function assertState(CscsUploadBatch $batch, array $states): void
    {
        if (! in_array($batch->workflow_status, $states, true)) {
            throw ValidationException::withMessages([
                'status' => ["Batch is {$batch->workflow_status}; expected ".implode(' or ', $states).'.'],
            ]);
        }
    }

    private function decimal(mixed $value): string
    {
        return bcadd((string) ($value ?? 0), '0', self::SCALE);
    }

    private function zero(): string
    {
        return '0.000000';
    }

    /** @return array<string, mixed> */
    private function batchResult(CscsUploadBatch $batch): array
    {
        return [
            'batch_id' => $batch->id,
            'status' => $batch->workflow_status,
            'revision' => $batch->revision,
            'snapshot_hash' => $batch->snapshot_hash,
            'summary' => $batch->summary ?? [],
            'risk_flags' => $batch->risk_flags ?? [],
            'current_approval_step' => $batch->current_approval_step,
            'required_approval_steps' => $batch->required_approval_steps ?? [],
        ];
    }
}
