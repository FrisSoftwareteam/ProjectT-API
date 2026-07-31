<?php

namespace App\Services\LegacyMigration;

use App\Jobs\PublishLegacyMigrationBatchJob;
use App\Jobs\RollbackLegacyMigrationBatchJob;
use App\Jobs\StageLegacyMigrationBatchJob;
use App\Models\LegacyMigrationApproval;
use App\Models\LegacyMigrationBatch;
use App\Models\LegacyMigrationEvent;
use App\Models\LegacyMigrationRecord;
use App\Models\ShareClass;
use App\Models\Shareholder;
use App\Models\ShareholderCategory;
use App\Models\SharePosition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LegacyMigrationWorkflowService
{
    public function __construct(private readonly LegacyMigrationPackageRegistry $packages) {}

    public function create(string $packageKey, int $registerId, int $shareClassId, int $actorId): LegacyMigrationBatch
    {
        $package = $this->packages->get($packageKey);
        $shareClass = ShareClass::findOrFail($shareClassId);
        if ((int) $shareClass->register_id !== $registerId) {
            throw ValidationException::withMessages(['share_class_id' => ['The share class does not belong to the selected register.']]);
        }
        $path = $package['source_path'];
        if (! is_file($path)) {
            throw ValidationException::withMessages(['source' => ['The package source file is not available.']]);
        }
        $actualHash = hash_file('sha256', $path);
        if (! hash_equals($package['source_sha256'], $actualHash)) {
            throw ValidationException::withMessages(['source' => ['The source checksum differs from the approved package checksum.']]);
        }

        return DB::transaction(function () use ($packageKey, $package, $registerId, $shareClassId, $actorId, $path, $actualHash) {
            $latest = LegacyMigrationBatch::where('register_id', $registerId)
                ->where('source_sha256', $actualHash)
                ->orderByDesc('attempt_no')
                ->lockForUpdate()
                ->first();
            if ($latest && ! in_array($latest->status, [LegacyMigrationBatch::ROLLED_BACK, LegacyMigrationBatch::CANCELLED], true)) {
                return $latest;
            }
            $batch = LegacyMigrationBatch::create([
                'public_id' => (string) Str::uuid(),
                'package_key' => $packageKey,
                'package_version' => $package['version'],
                'register_id' => $registerId,
                'share_class_id' => $shareClassId,
                'source_filename' => $package['source_filename'],
                'source_sha256' => $actualHash,
                'source_size' => filesize($path),
                'status' => LegacyMigrationBatch::CREATED,
                'attempt_no' => ((int) ($latest?->attempt_no ?? 0)) + 1,
                'expected_rows' => $package['expected_rows'],
                'expected_quantity' => $package['expected_quantity'],
                'config_snapshot' => $this->packages->snapshot($packageKey),
                'created_by' => $actorId,
            ]);
            $this->event($batch, 'BATCH_CREATED', null, LegacyMigrationBatch::CREATED, $actorId, null, [
                'attempt_no' => $batch->attempt_no,
                'previous_batch_id' => $latest?->id,
            ]);

            return $batch;
        });
    }

    public function dispatchStaging(int $batchId, int $actorId): LegacyMigrationBatch
    {
        $batch = LegacyMigrationBatch::findOrFail($batchId);
        $this->assertState($batch, [LegacyMigrationBatch::CREATED, LegacyMigrationBatch::FAILED]);
        StageLegacyMigrationBatchJob::dispatch($batch->id, $actorId);

        return $batch->fresh();
    }

    /** @return array<string, mixed> */
    public function reconcile(int $batchId, int $actorId, ?string $comment = null): array
    {
        $batch = LegacyMigrationBatch::findOrFail($batchId);
        $this->assertState($batch, [LegacyMigrationBatch::STAGED, LegacyMigrationBatch::VALIDATED]);
        $package = $this->packages->get($batch->package_key);
        $sourceHashMatches = is_file($package['source_path'])
            && hash_equals($batch->source_sha256, hash_file('sha256', $package['source_path']));
        $stagedRows = LegacyMigrationRecord::where('batch_id', $batch->id)->count();
        $validRows = LegacyMigrationRecord::where('batch_id', $batch->id)->where('status', 'VALID')->count();
        $errorRows = LegacyMigrationRecord::where('batch_id', $batch->id)->where('status', 'ERROR')->count();
        $missingCategories = LegacyMigrationRecord::where('batch_id', $batch->id)->where('status', 'VALID')
            ->whereNotIn('category_code', ShareholderCategory::query()->where('is_active', true)->pluck('code'))->count();
        $collisions = $this->targetCollisions($batch);
        $quantity = $this->decimal((string) LegacyMigrationRecord::where('batch_id', $batch->id)->where('status', 'VALID')->sum('quantity'));
        $targetPositionRows = SharePosition::where('share_class_id', $batch->share_class_id)->count();
        $register = $batch->register()->firstOrFail();
        $capitalMatches = $register->capital_behaviour_type !== 'constant'
            || $this->decimal((string) ($register->paid_up_capital ?? 0)) === $this->decimal((string) $batch->expected_quantity);
        $checks = [
            'source_checksum_matches' => $sourceHashMatches,
            'row_count_matches' => $stagedRows === (int) $batch->expected_rows,
            'all_rows_valid' => $errorRows === 0 && $validRows === (int) $batch->expected_rows,
            'quantity_matches' => $quantity === $this->decimal((string) $batch->expected_quantity),
            'all_categories_exist' => $missingCategories === 0,
            'target_identifiers_available' => array_sum($collisions) === 0,
            'target_share_class_is_empty' => $targetPositionRows === 0,
            'constant_capital_matches_expected_units' => $capitalMatches,
        ];
        $reconciliation = [
            'expected_rows' => (int) $batch->expected_rows,
            'staged_rows' => $stagedRows,
            'valid_rows' => $validRows,
            'error_rows' => $errorRows,
            'expected_quantity' => $this->decimal((string) $batch->expected_quantity),
            'valid_quantity' => $quantity,
            'missing_category_rows' => $missingCategories,
            'target_collisions' => $collisions,
            'existing_target_position_rows' => $targetPositionRows,
            'checks' => $checks,
            'result' => ! in_array(false, $checks, true) ? 'PASS' : 'FAIL',
        ];
        $from = $batch->status;
        $status = $reconciliation['result'] === 'PASS' ? LegacyMigrationBatch::VALIDATED : LegacyMigrationBatch::STAGED;
        $batch->update([
            'status' => $status,
            'staged_rows' => $stagedRows,
            'valid_rows' => $validRows,
            'error_rows' => $errorRows,
            'staged_quantity' => $quantity,
            'reconciliation' => $reconciliation,
            'approval_snapshot_hash' => null,
            'validated_by' => $reconciliation['result'] === 'PASS' ? $actorId : null,
            'validated_at' => $reconciliation['result'] === 'PASS' ? now() : null,
        ]);
        $this->event($batch, 'RECONCILIATION_'.$reconciliation['result'], $from, $status, $actorId, $comment, $reconciliation);

        return ['batch' => $batch->fresh(), 'reconciliation' => $reconciliation];
    }

    public function submit(int $batchId, int $actorId, ?string $comment = null): LegacyMigrationBatch
    {
        return DB::transaction(function () use ($batchId, $actorId, $comment) {
            $batch = LegacyMigrationBatch::lockForUpdate()->findOrFail($batchId);
            $this->assertState($batch, [LegacyMigrationBatch::VALIDATED]);
            if ((int) $batch->created_by !== $actorId) {
                throw ValidationException::withMessages(['actor' => ['Only the batch maker may submit it.']]);
            }
            $snapshot = $this->snapshotHash($batch);
            $batch->update([
                'status' => LegacyMigrationBatch::PENDING_APPROVAL,
                'approval_snapshot_hash' => $snapshot,
                'submitted_by' => $actorId,
                'submitted_at' => now(),
            ]);
            LegacyMigrationApproval::create([
                'batch_id' => $batch->id, 'revision' => $batch->revision, 'decision' => 'SUBMITTED',
                'actor_id' => $actorId, 'comment' => $comment, 'snapshot_hash' => $snapshot,
            ]);
            $this->event($batch, 'SUBMITTED', LegacyMigrationBatch::VALIDATED, LegacyMigrationBatch::PENDING_APPROVAL, $actorId, $comment);

            return $batch->fresh();
        });
    }

    public function approve(int $batchId, int $actorId, ?string $comment = null): LegacyMigrationBatch
    {
        return DB::transaction(function () use ($batchId, $actorId, $comment) {
            $batch = LegacyMigrationBatch::lockForUpdate()->findOrFail($batchId);
            $this->assertState($batch, [LegacyMigrationBatch::PENDING_APPROVAL]);
            if ((int) $batch->created_by === $actorId) {
                throw ValidationException::withMessages(['actor' => ['The maker cannot approve their own migration.']]);
            }
            if (! hash_equals((string) $batch->approval_snapshot_hash, $this->snapshotHash($batch))) {
                throw ValidationException::withMessages(['snapshot' => ['The staged data changed after submission. Revalidate it.']]);
            }
            $batch->update(['status' => LegacyMigrationBatch::APPROVED, 'approved_by' => $actorId, 'approved_at' => now()]);
            LegacyMigrationApproval::create([
                'batch_id' => $batch->id, 'revision' => $batch->revision, 'decision' => 'APPROVED',
                'actor_id' => $actorId, 'comment' => $comment, 'snapshot_hash' => $batch->approval_snapshot_hash,
            ]);
            $this->event($batch, 'APPROVED', LegacyMigrationBatch::PENDING_APPROVAL, LegacyMigrationBatch::APPROVED, $actorId, $comment);

            return $batch->fresh();
        });
    }

    public function dispatchPublishing(int $batchId, int $actorId): LegacyMigrationBatch
    {
        $batch = DB::transaction(function () use ($batchId, $actorId) {
            $batch = LegacyMigrationBatch::lockForUpdate()->findOrFail($batchId);
            $this->assertState($batch, [LegacyMigrationBatch::APPROVED, LegacyMigrationBatch::PUBLISHING_FAILED]);
            if ((int) $batch->created_by === $actorId) {
                throw ValidationException::withMessages(['actor' => ['The maker cannot publish their own migration.']]);
            }
            if (! hash_equals((string) $batch->approval_snapshot_hash, $this->snapshotHash($batch))) {
                throw ValidationException::withMessages(['snapshot' => ['The approved snapshot changed. Publishing is blocked.']]);
            }
            $package = $this->packages->get($batch->package_key);
            if (! is_file($package['source_path']) || ! hash_equals($batch->source_sha256, hash_file('sha256', $package['source_path']))) {
                throw ValidationException::withMessages(['source' => ['The approved source file is missing or its checksum changed.']]);
            }
            $from = $batch->status;
            $batch->update(['status' => LegacyMigrationBatch::PUBLISHING, 'published_by' => $actorId, 'publishing_started_at' => now(), 'failure_reason' => null]);
            $this->event($batch, $from === LegacyMigrationBatch::PUBLISHING_FAILED ? 'PUBLISHING_RESUMED' : 'PUBLISHING_STARTED', $from, LegacyMigrationBatch::PUBLISHING, $actorId);

            return $batch;
        });
        PublishLegacyMigrationBatchJob::dispatch($batch->id, $actorId);

        return $batch->fresh();
    }

    public function dispatchRollback(int $batchId, int $actorId, string $comment): LegacyMigrationBatch
    {
        $batch = DB::transaction(function () use ($batchId, $actorId, $comment) {
            $batch = LegacyMigrationBatch::lockForUpdate()->findOrFail($batchId);
            $this->assertState($batch, [LegacyMigrationBatch::PUBLISHED, LegacyMigrationBatch::ROLLBACK_BLOCKED, LegacyMigrationBatch::PUBLISHING_FAILED]);
            if ($batch->status === LegacyMigrationBatch::PUBLISHING_FAILED && ! LegacyMigrationRecord::where('batch_id', $batch->id)->where('status', 'PUBLISHED')->exists()) {
                throw ValidationException::withMessages(['status' => ['There are no published rows to roll back.']]);
            }
            $from = $batch->status;
            $batch->update(['status' => LegacyMigrationBatch::ROLLING_BACK, 'rolled_back_by' => $actorId, 'rollback_started_at' => now(), 'failure_reason' => null]);
            $this->event($batch, 'ROLLBACK_STARTED', $from, LegacyMigrationBatch::ROLLING_BACK, $actorId, $comment);

            return $batch;
        });
        RollbackLegacyMigrationBatchJob::dispatch($batch->id, $actorId, $comment);

        return $batch->fresh();
    }

    public function cancel(int $batchId, int $actorId, string $comment): LegacyMigrationBatch
    {
        return DB::transaction(function () use ($batchId, $actorId, $comment) {
            $batch = LegacyMigrationBatch::lockForUpdate()->findOrFail($batchId);
            $this->assertState($batch, [LegacyMigrationBatch::CREATED, LegacyMigrationBatch::STAGED, LegacyMigrationBatch::VALIDATED, LegacyMigrationBatch::PENDING_APPROVAL, LegacyMigrationBatch::FAILED]);
            if ((int) $batch->created_by !== $actorId) {
                throw ValidationException::withMessages(['actor' => ['Only the batch maker may cancel an unpublished migration.']]);
            }
            $from = $batch->status;
            $batch->update(['status' => LegacyMigrationBatch::CANCELLED]);
            $this->event($batch, 'CANCELLED', $from, LegacyMigrationBatch::CANCELLED, $actorId, $comment);

            return $batch->fresh();
        });
    }

    public function snapshotHash(LegacyMigrationBatch $batch): string
    {
        $context = hash_init('sha256');
        hash_update($context, $batch->source_sha256.'|'.json_encode($batch->config_snapshot).'|'.json_encode($batch->reconciliation));
        LegacyMigrationRecord::where('batch_id', $batch->id)->orderBy('id')
            ->lazyById(2000)->each(function ($record) use ($context) {
                $classification = $record->status === 'ERROR' ? 'ERROR' : 'VALID';
                $transformed = [
                    $record->idempotency_key, $record->row_hash, $record->source_account_number,
                    $record->target_account_no, $record->target_email, $record->target_phone,
                    $record->holder_type, $record->category_code, $this->decimal((string) $record->quantity),
                    $record->holding_mode, $classification, $record->normalized_data, $record->errors,
                ];
                hash_update($context, '|'.json_encode($transformed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            });

        return hash_final($context);
    }

    public function event(LegacyMigrationBatch $batch, string $type, ?string $from, ?string $to, ?int $actorId = null, ?string $comment = null, ?array $metadata = null): void
    {
        LegacyMigrationEvent::create([
            'batch_id' => $batch->id, 'event_type' => $type, 'from_status' => $from,
            'to_status' => $to, 'actor_id' => $actorId, 'comment' => $comment, 'metadata' => $metadata,
        ]);
    }

    /** @return array{account_no:int,email:int,phone:int} */
    private function targetCollisions(LegacyMigrationBatch $batch): array
    {
        $counts = ['account_no' => 0, 'email' => 0, 'phone' => 0];
        LegacyMigrationRecord::where('batch_id', $batch->id)->where('status', 'VALID')->orderBy('id')->chunkById(1000, function ($records) use (&$counts) {
            $counts['account_no'] += Shareholder::whereIn('account_no', $records->pluck('target_account_no'))->count();
            $counts['email'] += Shareholder::whereIn('email', $records->pluck('target_email'))->count();
            $counts['phone'] += Shareholder::whereIn('phone', $records->pluck('target_phone'))->count();
        });

        return $counts;
    }

    /** @param array<int, string> $states */
    private function assertState(LegacyMigrationBatch $batch, array $states): void
    {
        if (! in_array($batch->status, $states, true)) {
            throw ValidationException::withMessages(['status' => ['Operation is not permitted while the batch is '.$batch->status.'.']]);
        }
    }

    private function decimal(string $value): string
    {
        return number_format((float) $value, 6, '.', '');
    }
}
