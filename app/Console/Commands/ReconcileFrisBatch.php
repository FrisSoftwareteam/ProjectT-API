<?php

namespace App\Console\Commands;

use App\Models\FrisMigrationBatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReconcileFrisBatch extends Command
{
    protected $signature = 'fris:reconcile-batch {batch_id : FRIS migration batch ID}';

    protected $description = 'Reconcile a staged FRIS migration batch before publishing';

    public function handle(): int
    {
        $batch = FrisMigrationBatch::find((int) $this->argument('batch_id'));
        if ($batch === null) {
            $this->error('FRIS migration batch not found.');

            return self::FAILURE;
        }

        $profileRows = DB::table('fris_migration_profiles')->where('batch_id', $batch->id)->count();
        $unitRows = DB::table('fris_migration_units')->where('batch_id', $batch->id)->count();
        $profileErrors = DB::table('fris_migration_profiles')->where('batch_id', $batch->id)->where('status', 'ERROR')->count();
        $unitErrors = DB::table('fris_migration_units')->where('batch_id', $batch->id)->where('status', 'ERROR')->count();
        $profileHoldings = (string) DB::table('fris_migration_profiles')->where('batch_id', $batch->id)->sum('source_holdings');
        $unitQuantity = (string) DB::table('fris_migration_units')->where('batch_id', $batch->id)->sum('source_units');

        $duplicateProfiles = DB::table('fris_migration_profiles')
            ->select('register_code', 'account_number')
            ->where('batch_id', $batch->id)
            ->groupBy('register_code', 'account_number')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        $duplicateUnitIds = DB::table('fris_migration_units')
            ->select('fris_unit_id')
            ->where('batch_id', $batch->id)
            ->groupBy('fris_unit_id')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        $unitsWithoutProfile = DB::table('fris_migration_units as u')
            ->leftJoin('fris_migration_profiles as p', function ($join) {
                $join->on('p.batch_id', '=', 'u.batch_id')
                    ->on('p.register_code', '=', 'u.register_code')
                    ->on('p.account_number', '=', 'u.account_number');
            })
            ->where('u.batch_id', $batch->id)
            ->whereNull('p.id')
            ->count();
        $unitsWithoutProfileQuantity = (string) DB::table('fris_migration_units as u')
            ->leftJoin('fris_migration_profiles as p', function ($join) {
                $join->on('p.batch_id', '=', 'u.batch_id')
                    ->on('p.register_code', '=', 'u.register_code')
                    ->on('p.account_number', '=', 'u.account_number');
            })
            ->where('u.batch_id', $batch->id)
            ->whereNull('p.id')
            ->sum('u.source_units');

        $profilesWithoutUnits = DB::table('fris_migration_profiles as p')
            ->leftJoin('fris_migration_units as u', function ($join) {
                $join->on('u.batch_id', '=', 'p.batch_id')
                    ->on('u.register_code', '=', 'p.register_code')
                    ->on('u.account_number', '=', 'p.account_number');
            })
            ->where('p.batch_id', $batch->id)
            ->whereNull('u.id')
            ->count();

        $accountMismatches = DB::query()
            ->fromSub(
                DB::table('fris_migration_profiles as p')
                    ->leftJoin('fris_migration_units as u', function ($join) {
                        $join->on('u.batch_id', '=', 'p.batch_id')
                            ->on('u.register_code', '=', 'p.register_code')
                            ->on('u.account_number', '=', 'p.account_number');
                    })
                    ->where('p.batch_id', $batch->id)
                    ->groupBy('p.id', 'p.source_holdings')
                    ->selectRaw('p.id, p.source_holdings, COALESCE(SUM(u.source_units), 0) as unit_quantity'),
                'account_totals'
            )
            ->whereRaw('ABS(COALESCE(source_holdings, 0) - COALESCE(unit_quantity, 0)) > 0.000001')
            ->count();

        $reconciliation = [
            'profile_rows' => $profileRows,
            'unit_rows' => $unitRows,
            'profile_errors' => $profileErrors,
            'unit_errors' => $unitErrors,
            'profile_holdings' => $profileHoldings,
            'unit_quantity' => $unitQuantity,
            'quantity_difference' => number_format(((float) $profileHoldings) - ((float) $unitQuantity), 6, '.', ''),
            'duplicate_profiles' => $duplicateProfiles,
            'duplicate_unit_ids' => $duplicateUnitIds,
            'units_without_profile' => $unitsWithoutProfile,
            'units_without_profile_quantity' => $unitsWithoutProfileQuantity,
            'profiles_without_units' => $profilesWithoutUnits,
            'account_quantity_mismatches' => $accountMismatches,
        ];

        $blockingIssues = array_filter([
            'profile_errors' => $profileErrors,
            'unit_errors' => $unitErrors,
            'duplicate_profiles' => $duplicateProfiles,
            'duplicate_unit_ids' => $duplicateUnitIds,
            'units_without_profile' => abs((float) $unitsWithoutProfileQuantity) > 0.000001 ? $unitsWithoutProfile : 0,
            'account_quantity_mismatches' => $accountMismatches,
        ], fn ($value) => (int) $value > 0);

        $reconciliation['passed'] = $blockingIssues === [] && abs((float) $reconciliation['quantity_difference']) <= 0.000001;
        $reconciliation['blocking_issues'] = array_keys($blockingIssues);
        $reconciliation['reconciled_at'] = now()->toIso8601String();

        $batch->update([
            'reconciliation' => $reconciliation,
            'profile_summary' => [
                'rows' => $profileRows,
                'errors' => $profileErrors,
                'holdings' => $profileHoldings,
                'profiles_without_units' => $profilesWithoutUnits,
            ],
            'unit_summary' => [
                'rows' => $unitRows,
                'errors' => $unitErrors,
                'quantity' => $unitQuantity,
                'units_without_profile' => $unitsWithoutProfile,
                'units_without_profile_quantity' => $unitsWithoutProfileQuantity,
            ],
        ]);

        $this->info('FRIS reconciliation for batch '.$batch->id);
        foreach ($reconciliation as $key => $value) {
            $this->line($key.': '.(is_array($value) ? implode(', ', $value) : (string) $value));
        }

        return $reconciliation['passed'] ? self::SUCCESS : self::FAILURE;
    }
}
