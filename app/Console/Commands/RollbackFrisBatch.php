<?php

namespace App\Console\Commands;

use App\Models\FrisMigrationBatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RollbackFrisBatch extends Command
{
    protected $signature = 'fris:rollback-batch
        {batch_id : Published FRIS migration batch ID}
        {--dry-run : Show rollback counts without deleting records}';

    protected $description = 'Rollback a published FRIS batch from Project T domain tables';

    public function handle(): int
    {
        $batch = FrisMigrationBatch::find((int) $this->argument('batch_id'));
        if ($batch === null) {
            $this->error('FRIS migration batch not found.');

            return self::FAILURE;
        }
        if ($batch->published_at === null) {
            $this->error('FRIS batch has not been published.');

            return self::FAILURE;
        }

        $counts = $this->counts($batch);
        $this->info(($this->option('dry-run') ? 'Checking rollback for' : 'Rolling back').' FRIS batch '.$batch->id);
        foreach ($counts as $key => $value) {
            $this->line($key.': '.$value);
        }

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        DB::transaction(function () use ($batch, $counts) {
            $now = now();

            $transactionIds = DB::table('fris_migration_units')
                ->where('batch_id', $batch->id)
                ->whereNotNull('share_transaction_id')
                ->pluck('share_transaction_id');
            $lotIds = DB::table('fris_migration_units')
                ->where('batch_id', $batch->id)
                ->whereNotNull('share_lot_id')
                ->pluck('share_lot_id');
            $positionIds = DB::table('fris_migration_profiles as p')
                ->join('share_positions as sp', 'sp.sra_id', '=', 'p.sra_id')
                ->where('p.batch_id', $batch->id)
                ->pluck('sp.id');
            $sraIds = DB::table('fris_migration_profiles')
                ->where('batch_id', $batch->id)
                ->whereNotNull('sra_id')
                ->pluck('sra_id');
            $addressIds = DB::table('fris_migration_profiles')
                ->where('batch_id', $batch->id)
                ->whereNotNull('address_id')
                ->pluck('address_id');
            $shareholderIds = DB::table('fris_migration_profiles')
                ->where('batch_id', $batch->id)
                ->whereNotNull('shareholder_id')
                ->pluck('shareholder_id');

            DB::table('share_transactions')->whereIn('id', $transactionIds)->delete();
            DB::table('share_lots')->whereIn('id', $lotIds)->delete();
            DB::table('share_positions')->whereIn('id', $positionIds)->delete();
            DB::table('shareholder_register_accounts')->whereIn('id', $sraIds)->delete();
            DB::table('shareholder_bank_mandates')->whereIn('shareholder_id', $shareholderIds)->delete();
            DB::table('shareholder_addresses')->whereIn('id', $addressIds)->delete();
            DB::table('shareholders')->whereIn('id', $shareholderIds)->delete();

            $target = $this->targetIds($batch);
            if ($target['register_id'] !== null) {
                DB::table('cscs_security_mappings')
                    ->where('security_code', 'FRIS'.$batch->register_code)
                    ->where('register_id', $target['register_id'])
                    ->delete();
            }

            if ($target['share_class_id'] !== null && $this->shareClassIsEmpty($target['share_class_id'])) {
                DB::table('share_classes')->where('id', $target['share_class_id'])->delete();
            }
            if ($target['register_id'] !== null && $this->registerIsEmpty($target['register_id'])) {
                DB::table('registers')->where('id', $target['register_id'])->delete();
            }
            if ($target['company_id'] !== null && $this->companyIsFrisAndEmpty($target['company_id'])) {
                DB::table('companies')->where('id', $target['company_id'])->delete();
            }

            DB::table('fris_migration_crosswalks')->where('batch_id', $batch->id)->delete();
            DB::table('fris_migration_units')->where('batch_id', $batch->id)->update([
                'share_lot_id' => null,
                'share_transaction_id' => null,
                'published_at' => null,
                'updated_at' => $now,
            ]);
            DB::table('fris_migration_profiles')->where('batch_id', $batch->id)->update([
                'shareholder_id' => null,
                'address_id' => null,
                'mandate_id' => null,
                'sra_id' => null,
                'published_at' => null,
                'updated_at' => $now,
            ]);
            $batch->update([
                'status' => FrisMigrationBatch::STAGED,
                'published_at' => null,
                'published_by' => null,
                'publishing_started_at' => null,
                'reconciliation' => array_merge($batch->reconciliation ?? [], [
                    'rolled_back_at' => $now->toIso8601String(),
                    'rollback_counts' => $counts,
                ]),
            ]);
        });

        $this->info('FRIS batch '.$batch->id.' rolled back.');

        return self::SUCCESS;
    }

    /** @return array<string, int> */
    private function counts(FrisMigrationBatch $batch): array
    {
        $shareholderIds = DB::table('fris_migration_profiles')->where('batch_id', $batch->id)->whereNotNull('shareholder_id')->pluck('shareholder_id');
        $sraIds = DB::table('fris_migration_profiles')->where('batch_id', $batch->id)->whereNotNull('sra_id')->pluck('sra_id');

        return [
            'shareholders' => $shareholderIds->count(),
            'addresses' => DB::table('fris_migration_profiles')->where('batch_id', $batch->id)->whereNotNull('address_id')->count(),
            'bank_mandates' => DB::table('shareholder_bank_mandates')->whereIn('shareholder_id', $shareholderIds)->count(),
            'sras' => $sraIds->count(),
            'positions' => DB::table('share_positions')->whereIn('sra_id', $sraIds)->count(),
            'lots' => DB::table('fris_migration_units')->where('batch_id', $batch->id)->whereNotNull('share_lot_id')->count(),
            'transactions' => DB::table('fris_migration_units')->where('batch_id', $batch->id)->whereNotNull('share_transaction_id')->count(),
            'crosswalks' => DB::table('fris_migration_crosswalks')->where('batch_id', $batch->id)->count(),
        ];
    }

    /** @return array{company_id:?int,register_id:?int,share_class_id:?int} */
    private function targetIds(FrisMigrationBatch $batch): array
    {
        $companyId = DB::table('companies')->where('issuer_code', 'FRIS-'.$batch->register_code)->value('id');
        $registerId = $companyId
            ? DB::table('registers')->where('company_id', $companyId)->where('register_code', (string) $batch->register_code)->value('id')
            : null;
        $shareClassId = $registerId
            ? DB::table('share_classes')->where('register_id', $registerId)->where('class_code', 'ORD')->value('id')
            : null;

        return ['company_id' => $companyId, 'register_id' => $registerId, 'share_class_id' => $shareClassId];
    }

    private function shareClassIsEmpty(int $shareClassId): bool
    {
        return ! DB::table('share_positions')->where('share_class_id', $shareClassId)->exists()
            && ! DB::table('share_lots')->where('share_class_id', $shareClassId)->exists()
            && ! DB::table('share_transactions')->where('share_class_id', $shareClassId)->exists();
    }

    private function registerIsEmpty(int $registerId): bool
    {
        return ! DB::table('shareholder_register_accounts')->where('register_id', $registerId)->exists()
            && ! DB::table('share_classes')->where('register_id', $registerId)->exists()
            && ! DB::table('dividend_declarations')->where('register_id', $registerId)->exists()
            && ! DB::table('cscs_upload_batches')->where('register_id', $registerId)->exists();
    }

    private function companyIsFrisAndEmpty(int $companyId): bool
    {
        $issuerCode = DB::table('companies')->where('id', $companyId)->value('issuer_code');

        return str_starts_with((string) $issuerCode, 'FRIS-')
            && ! DB::table('registers')->where('company_id', $companyId)->exists()
            && ! DB::table('dividend_declarations')->where('company_id', $companyId)->exists();
    }
}
