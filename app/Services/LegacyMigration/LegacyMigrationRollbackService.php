<?php

namespace App\Services\LegacyMigration;

use App\Models\LegacyMigrationBatch;
use App\Models\LegacyMigrationRecord;
use App\Services\CapitalValidationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class LegacyMigrationRollbackService
{
    public function __construct(
        private readonly LegacyMigrationWorkflowService $workflow,
        private readonly CapitalValidationService $capitalValidation
    ) {}

    public function rollback(int $batchId, int $actorId, string $comment): void
    {
        $batch = LegacyMigrationBatch::findOrFail($batchId);
        try {
            $this->assertNoDownstreamActivity($batch);
            LegacyMigrationRecord::where('batch_id', $batch->id)->where('status', 'PUBLISHED')->orderByDesc('id')
                ->chunkById(config('legacy_migrations.chunk_size', 1000), fn (Collection $records) => $this->rollbackChunk($records), 'id', 'id');
            $rolledBack = LegacyMigrationRecord::where('batch_id', $batch->id)->where('status', 'ROLLED_BACK')->count();
            $this->capitalValidation->syncOutstandingUnits((int) $batch->register_id);
            $batch->update(['status' => LegacyMigrationBatch::ROLLED_BACK, 'rolled_back_rows' => $rolledBack, 'rolled_back_at' => now(), 'failure_reason' => null]);
            $this->workflow->event($batch, 'ROLLED_BACK', LegacyMigrationBatch::ROLLING_BACK, LegacyMigrationBatch::ROLLED_BACK, $actorId, $comment, ['rows' => $rolledBack]);
        } catch (Throwable $exception) {
            $batch->update(['status' => LegacyMigrationBatch::ROLLBACK_BLOCKED, 'failure_reason' => $exception->getMessage()]);
            $this->workflow->event($batch, 'ROLLBACK_BLOCKED', LegacyMigrationBatch::ROLLING_BACK, LegacyMigrationBatch::ROLLBACK_BLOCKED, $actorId, $comment);
            throw $exception;
        }
    }

    private function assertNoDownstreamActivity(LegacyMigrationBatch $batch): void
    {
        $sraReferences = [
            'share_transactions' => ['sra_id'], 'share_lots' => ['sra_id'], 'sra_guardians' => ['sra_id'],
            'sra_joint_holders' => ['sra_id'], 'sra_proxies' => ['sra_id'], 'shareholder_cautions' => ['sra_id'],
            'probate_beneficiaries' => ['sra_id'], 'share_transfer_events' => ['from_sra_id', 'to_sra_id'],
            'cscs_upload_rows' => ['sra_id', 'proposed_sra_id'], 'sra_external_identifiers' => ['sra_id'],
            'dividend_entitlements' => ['register_account_id', 'sra_id'],
        ];
        $shareholderReferences = [
            'shareholder_identities' => ['shareholder_id'], 'shareholder_bank_mandates' => ['shareholder_id'],
            'probate_cases' => ['shareholder_id'], 'ipo_offer_allotments' => ['shareholder_id'],
            'probate_beneficiaries' => ['beneficiary_shareholder_id'], 'shareholder_cautions' => ['shareholder_id'],
            'shareholder_caution_logs' => ['shareholder_id'], 'share_transfer_events' => ['from_shareholder_id', 'to_shareholder_id'],
            'shareholder_merge_events' => ['primary_shareholder_id', 'duplicate_shareholder_id'],
            'sra_guardians' => ['guardian_shareholder_id'], 'estate_case_representatives' => ['representative_shareholder_id'],
        ];

        LegacyMigrationRecord::where('batch_id', $batch->id)->where('status', 'PUBLISHED')->orderBy('id')
            ->chunkById(1000, function (Collection $records) use ($sraReferences, $shareholderReferences) {
                $this->assertNoReferences($sraReferences, $records->pluck('sra_id')->filter()->values(), 'register-account');
                $this->assertNoReferences($shareholderReferences, $records->pluck('shareholder_id')->filter()->values(), 'shareholder');
            });
    }

    /** @param array<string, array<int, string>> $references */
    private function assertNoReferences(array $references, Collection $ids, string $kind): void
    {
        foreach ($references as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($columns as $column) {
                if (Schema::hasColumn($table, $column) && DB::table($table)->whereIn($column, $ids)->exists()) {
                    throw new \RuntimeException("Rollback is unsafe because {$table}.{$column} contains downstream {$kind} activity.");
                }
            }
        }
    }

    private function rollbackChunk(Collection $records): void
    {
        DB::transaction(function () use ($records) {
            $locked = LegacyMigrationRecord::whereIn('id', $records->pluck('id'))->lockForUpdate()->where('status', 'PUBLISHED')->get();
            DB::table('share_positions')->whereIn('id', $locked->pluck('position_id'))->delete();
            DB::table('shareholder_addresses')->whereIn('id', $locked->pluck('address_id'))->delete();
            DB::table('shareholder_register_accounts')->whereIn('id', $locked->pluck('sra_id'))->delete();
            DB::table('shareholders')->whereIn('id', $locked->pluck('shareholder_id'))->delete();
            LegacyMigrationRecord::whereIn('id', $locked->pluck('id'))->update(['status' => 'ROLLED_BACK', 'rolled_back_at' => now()]);
        });
    }
}
