<?php

namespace App\Console\Commands;

use App\Models\FrisMigrationBatch;
use App\Services\Fris\FrisRegisterMetadataResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class RemediateFrisStructure extends Command
{
    protected $signature = 'fris:remediate-structure
        {--batch=* : Limit remediation to one or more published batch IDs}
        {--apply : Apply company, register, share-class, and instrument corrections}';

    protected $description = 'Audit or safely correct FRIS issuer grouping and register metadata';

    public function handle(FrisRegisterMetadataResolver $resolver): int
    {
        $selectedBatchIds = collect($this->option('batch'))
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        $batches = FrisMigrationBatch::query()
            ->whereNotNull('published_at')
            ->when($selectedBatchIds->isNotEmpty(), fn ($query) => $query->whereIn('id', $selectedBatchIds))
            ->orderBy('id')
            ->get();

        if ($batches->isEmpty()) {
            $this->warn('No published FRIS batches matched the selection.');

            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply');
        $rows = [];
        $failed = false;

        foreach ($batches as $batch) {
            try {
                $result = $apply
                    ? DB::transaction(fn () => $this->remediate($batch, $resolver))
                    : $this->audit($batch, $resolver);
            } catch (\Throwable $exception) {
                $failed = true;
                $result = [
                    'current_issuer' => 'ERROR',
                    'desired_issuer' => '-',
                    'current_class' => '-',
                    'desired_class' => '-',
                    'instrument' => '-',
                    'result' => $exception->getMessage(),
                ];
            }

            $rows[] = [
                $batch->id,
                $batch->register_code,
                $result['current_issuer'],
                $result['desired_issuer'],
                $result['current_class'],
                $result['desired_class'],
                $result['instrument'],
                $result['result'],
            ];
        }

        $this->table(
            ['Batch', 'Register', 'Current issuer', 'Desired issuer', 'Current class', 'Desired class', 'Instrument', 'Result'],
            $rows,
        );

        if (! $apply) {
            $this->warn('Dry run only. Re-run with --apply after reviewing every proposed correction.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @return array<string, string> */
    private function audit(FrisMigrationBatch $batch, FrisRegisterMetadataResolver $resolver): array
    {
        $targets = $this->targets($batch);
        $metadata = $resolver->resolve((int) $batch->register_code, $batch->company_name);

        $changes = [];
        if ($targets['company']->issuer_code !== $metadata['issuer_code']) {
            $changes[] = 'move company';
        }
        if ($targets['company']->name !== $metadata['company_name']) {
            $changes[] = 'company name';
        }
        if ($targets['share_class']->class_code !== $metadata['class_code']) {
            $changes[] = 'share class';
        }
        if ($targets['register']->instrument_type !== $metadata['instrument_category']) {
            $changes[] = 'instrument';
        }

        $instrumentCode = DB::table('instrument_types')->where('id', $targets['register']->instrument_type_id)->value('code');
        if ($instrumentCode !== $metadata['instrument_type_code']) {
            $changes[] = 'instrument type';
        }

        return [
            'current_issuer' => $targets['company']->issuer_code,
            'desired_issuer' => $metadata['issuer_code'],
            'current_class' => $targets['share_class']->class_code,
            'desired_class' => $metadata['class_code'],
            'instrument' => $metadata['instrument_type_code'],
            'result' => $changes === [] ? 'already correct' : implode(', ', array_unique($changes)),
        ];
    }

    /** @return array<string, string> */
    private function remediate(FrisMigrationBatch $batch, FrisRegisterMetadataResolver $resolver): array
    {
        $before = $this->audit($batch, $resolver);
        $targets = $this->targets($batch);
        $metadata = $resolver->resolve((int) $batch->register_code, $batch->company_name);
        $now = now();
        $oldCompanyId = (int) $targets['company']->id;

        $desiredCompany = DB::table('companies')->where('issuer_code', $metadata['issuer_code'])->first();
        if ($desiredCompany === null) {
            DB::table('companies')->where('id', $oldCompanyId)->update([
                'issuer_code' => $metadata['issuer_code'],
                'name' => $metadata['company_name'],
                'updated_at' => $now,
            ]);
            $desiredCompanyId = $oldCompanyId;
        } else {
            $desiredCompanyId = (int) $desiredCompany->id;
            DB::table('companies')->where('id', $desiredCompanyId)->update([
                'name' => $metadata['company_name'],
                'updated_at' => $now,
            ]);
        }

        if ($desiredCompanyId !== $oldCompanyId) {
            $this->assertDividendMoveIsSafe((int) $targets['register']->id, $desiredCompanyId);
            DB::table('dividend_declarations')
                ->where('register_id', $targets['register']->id)
                ->update(['company_id' => $desiredCompanyId, 'updated_at' => $now]);
        }

        $instrumentTypeId = DB::table('instrument_types')->where('code', $metadata['instrument_type_code'])->value('id');
        if ($instrumentTypeId === null) {
            throw new RuntimeException('Missing instrument type: '.$metadata['instrument_type_code']);
        }

        $isDefault = $metadata['is_default'] || ! DB::table('registers')
            ->where('company_id', $desiredCompanyId)
            ->where('id', '<>', $targets['register']->id)
            ->where('is_default', true)
            ->exists();

        if ($isDefault) {
            DB::table('registers')
                ->where('company_id', $desiredCompanyId)
                ->where('id', '<>', $targets['register']->id)
                ->update(['is_default' => false, 'updated_at' => $now]);
        }

        DB::table('registers')->where('id', $targets['register']->id)->update([
            'company_id' => $desiredCompanyId,
            'name' => $metadata['register_name'],
            'is_default' => $isDefault,
            'instrument_type' => $metadata['instrument_category'],
            'instrument_type_id' => $instrumentTypeId,
            'capital_behaviour_type' => 'constant',
            'unit_precision_type' => $metadata['unit_precision_type'],
            'decimal_precision' => $metadata['decimal_precision'],
            'updated_at' => $now,
        ]);

        $classConflict = DB::table('share_classes')
            ->where('register_id', $targets['register']->id)
            ->where('class_code', $metadata['class_code'])
            ->where('id', '<>', $targets['share_class']->id)
            ->exists();
        if ($classConflict) {
            throw new RuntimeException('Desired share class already exists on register '.$batch->register_code);
        }

        DB::table('share_classes')->where('id', $targets['share_class']->id)->update([
            'class_code' => $metadata['class_code'],
            'name' => $metadata['class_name'],
            'description' => $metadata['class_description'],
            'updated_at' => $now,
        ]);

        DB::table('cscs_security_mappings')->updateOrInsert(
            ['security_code' => $metadata['cscs_security_code']],
            [
                'register_id' => $targets['register']->id,
                'share_class_id' => $targets['share_class']->id,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        $this->retireEmptyFrisCompany($oldCompanyId, $desiredCompanyId, $now);

        return array_merge($before, [
            'result' => $before['result'] === 'already correct' ? 'already correct' : 'corrected',
        ]);
    }

    /** @return array{company:object,register:object,share_class:object} */
    private function targets(FrisMigrationBatch $batch): array
    {
        $register = DB::table('fris_migration_profiles as p')
            ->join('shareholder_register_accounts as sra', 'sra.id', '=', 'p.sra_id')
            ->join('registers as r', 'r.id', '=', 'sra.register_id')
            ->where('p.batch_id', $batch->id)
            ->whereNotNull('p.sra_id')
            ->select('r.*')
            ->first();
        if ($register === null) {
            throw new RuntimeException('Published register target not found.');
        }

        $shareClass = DB::table('fris_migration_profiles as p')
            ->join('share_positions as sp', 'sp.sra_id', '=', 'p.sra_id')
            ->join('share_classes as sc', 'sc.id', '=', 'sp.share_class_id')
            ->where('p.batch_id', $batch->id)
            ->select('sc.*')
            ->first();
        if ($shareClass === null) {
            throw new RuntimeException('Published share-class target not found.');
        }

        $company = DB::table('companies')->where('id', $register->company_id)->first();
        if ($company === null) {
            throw new RuntimeException('Published company target not found.');
        }

        return [
            'company' => $company,
            'register' => $register,
            'share_class' => $shareClass,
        ];
    }

    private function assertDividendMoveIsSafe(int $registerId, int $companyId): void
    {
        $periodLabels = DB::table('dividend_declarations')->where('register_id', $registerId)->pluck('period_label');
        if ($periodLabels->isEmpty()) {
            return;
        }

        if (DB::table('dividend_declarations')->where('company_id', $companyId)->whereIn('period_label', $periodLabels)->exists()) {
            throw new RuntimeException('Dividend declaration period conflict prevents company regrouping.');
        }
    }

    private function retireEmptyFrisCompany(int $oldCompanyId, int $desiredCompanyId, mixed $now): void
    {
        if ($oldCompanyId === $desiredCompanyId || DB::table('registers')->where('company_id', $oldCompanyId)->exists()) {
            return;
        }

        $issuerCode = DB::table('companies')->where('id', $oldCompanyId)->value('issuer_code');
        if (! str_starts_with((string) $issuerCode, 'FRIS-')) {
            return;
        }

        $updates = ['status' => 'closed', 'updated_at' => $now];
        if (Schema::hasColumn('companies', 'deleted_at')) {
            $updates['deleted_at'] = $now;
        }
        DB::table('companies')->where('id', $oldCompanyId)->update($updates);
    }
}
