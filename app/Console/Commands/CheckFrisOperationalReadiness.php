<?php

namespace App\Console\Commands;

use App\Models\FrisMigrationBatch;
use App\Services\Fris\FrisRegisterMetadataResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CheckFrisOperationalReadiness extends Command
{
    protected $signature = 'fris:readiness
        {batch_id : Published FRIS migration batch ID}
        {--repair : Fill missing operational setup where safe}';

    protected $description = 'Check CSCS/dividend readiness for a published FRIS batch';

    public function handle(): int
    {
        $batch = FrisMigrationBatch::find((int) $this->argument('batch_id'));
        if ($batch === null || $batch->published_at === null) {
            $this->error('Published FRIS batch not found.');

            return self::FAILURE;
        }

        $metadata = app(FrisRegisterMetadataResolver::class)->resolve(
            (int) $batch->register_code,
            $batch->company_name,
        );
        $register = DB::table('fris_migration_profiles as p')
            ->join('shareholder_register_accounts as sra', 'sra.id', '=', 'p.sra_id')
            ->join('registers as r', 'r.id', '=', 'sra.register_id')
            ->where('p.batch_id', $batch->id)
            ->select('r.*')
            ->first();
        $company = $register ? DB::table('companies')->where('id', $register->company_id)->first() : null;
        $shareClass = DB::table('fris_migration_profiles as p')
            ->join('share_positions as sp', 'sp.sra_id', '=', 'p.sra_id')
            ->join('share_classes as sc', 'sc.id', '=', 'sp.share_class_id')
            ->where('p.batch_id', $batch->id)
            ->select('sc.*')
            ->first();

        if ($this->option('repair') && $register && $shareClass) {
            $now = now();
            $positionTotal = DB::table('share_positions')->where('share_class_id', $shareClass->id)->sum('quantity');
            DB::table('registers')->where('id', $register->id)->update([
                'instrument_type' => $metadata['instrument_category'],
                'instrument_type_id' => DB::table('instrument_types')->where('code', $metadata['instrument_type_code'])->value('id'),
                'capital_behaviour_type' => 'constant',
                'paid_up_capital' => $positionTotal,
                'total_units_outstanding' => $positionTotal,
                'remaining_outstanding_units' => $positionTotal,
                'unit_precision_type' => $metadata['unit_precision_type'],
                'decimal_precision' => $metadata['decimal_precision'],
                'updated_at' => $now,
            ]);
            DB::table('cscs_security_mappings')->updateOrInsert(
                ['security_code' => $metadata['cscs_security_code']],
                [
                    'register_id' => $register->id,
                    'share_class_id' => $shareClass->id,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
            $register = DB::table('registers')->where('id', $register->id)->first();
        }

        $mapping = $register && $shareClass
            ? DB::table('cscs_security_mappings')
                ->where('security_code', $metadata['cscs_security_code'])
                ->where('register_id', $register->id)
                ->where('share_class_id', $shareClass->id)
                ->where('is_active', true)
                ->first()
            : null;
        $positionCount = $shareClass ? DB::table('share_positions')->where('share_class_id', $shareClass->id)->count() : 0;
        $positionTotal = $shareClass ? DB::table('share_positions')->where('share_class_id', $shareClass->id)->sum('quantity') : 0;
        $activeMandates = $register
            ? DB::table('shareholder_register_accounts as sra')
                ->join('shareholder_bank_mandates as m', 'm.shareholder_id', '=', 'sra.shareholder_id')
                ->where('sra.register_id', $register->id)
                ->where('m.status', 'active')
                ->count()
            : 0;
        $pendingMandates = $register
            ? DB::table('shareholder_register_accounts as sra')
                ->join('shareholder_bank_mandates as m', 'm.shareholder_id', '=', 'sra.shareholder_id')
                ->where('sra.register_id', $register->id)
                ->where('m.status', 'pending')
                ->count()
            : 0;
        $cscsAccountCount = $register
            ? DB::table('shareholder_register_accounts')
                ->where('register_id', $register->id)
                ->whereNotNull('cscs_account_no')
                ->where('cscs_account_no', '<>', '')
                ->count()
            : 0;
        $chnCount = $register
            ? DB::table('shareholder_register_accounts')
                ->where('register_id', $register->id)
                ->whereNotNull('chn')
                ->where('chn', '<>', '')
                ->count()
            : 0;

        $checks = [
            'company_exists' => (bool) $company,
            'company_group_correct' => (bool) $company && $company->issuer_code === $metadata['issuer_code'],
            'register_exists' => (bool) $register,
            'share_class_exists' => (bool) $shareClass,
            'share_class_code_correct' => (bool) $shareClass && $shareClass->class_code === $metadata['class_code'],
            'instrument_type_correct' => (bool) $register
                && DB::table('instrument_types')->where('id', $register->instrument_type_id)->value('code') === $metadata['instrument_type_code'],
            'capital_totals_set' => abs((float) ($register?->total_units_outstanding ?? 0) - (float) $positionTotal) <= 0.000001,
            'cscs_mapping_exists' => (bool) $mapping,
            'position_count' => $positionCount,
            'position_total' => $positionTotal,
            'paid_up_capital' => $register?->paid_up_capital,
            'cscs_account_count' => $cscsAccountCount,
            'chn_count' => $chnCount,
            'active_bank_mandates' => $activeMandates,
            'pending_bank_mandates' => $pendingMandates,
        ];

        foreach ($checks as $key => $value) {
            $this->line($key.': '.(is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value));
        }

        $passed = $checks['company_exists']
            && $checks['company_group_correct']
            && $checks['register_exists']
            && $checks['share_class_exists']
            && $checks['share_class_code_correct']
            && $checks['instrument_type_correct']
            && $checks['capital_totals_set']
            && $checks['cscs_mapping_exists'];

        if (! $passed) {
            $this->warn('Operational setup is incomplete. Run with --repair where safe.');
        }

        if ($activeMandates === 0) {
            $this->warn('Dividend declarations that require active bank mandates will mark these holders as not payable until mandates are verified/activated.');
        }

        return $passed ? self::SUCCESS : self::FAILURE;
    }
}
