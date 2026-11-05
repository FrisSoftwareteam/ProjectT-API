<?php

namespace App\Services;

use App\Models\AdminUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class FidelitySmallRepairService
{
    private const CHECKSUM = 'e588fd47f0b539373a6e612f82c393819367bfee9d5e18a561d086eaa3f33621';

    public function run(?int $actorId = null, bool $apply = false, bool $restoreMissing = false, ?string $expectedFinalTotal = null): array
    {
        return DB::transaction(function () use ($actorId, $apply, $restoreMissing, $expectedFinalTotal) {
            $path = database_path('repairs/fidelity-small-v1.json');
            if (! hash_equals(self::CHECKSUM, hash_file('sha256', $path))) {
                throw new RuntimeException('Repair evidence checksum mismatch.');
            }
            $evidence = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $registers = DB::table('registers')->where('register_code', '87')->lockForUpdate()->get();
            if ($registers->count() !== 1) {
                throw new RuntimeException('Expected exactly one Fidelity register with code 87.');
            }
            $register = $registers->first();
            $classes = DB::table('share_classes')->where('register_id', $register->id)->where('class_code', 'ORDINARY')->get();
            if ($classes->count() !== 1) {
                throw new RuntimeException('Expected exactly one ORDINARY share class.');
            }
            $class = $classes->first();
            $plan = [];
            $blocking = [];
            $missingQuantity = '0.000000';
            foreach ($evidence['rows'] as $link) {
                $record = $link['record'];
                $accounts = DB::table('shareholder_register_accounts')->where('register_id', $register->id)->where('shareholder_no', $record['source_account_number'])->lockForUpdate()->get();
                if ($accounts->count() > 1) {
                    throw new RuntimeException('Duplicate legacy register account '.$record['source_account_number']);
                }
                $account = $accounts->first();
                $entry = ['legacy_account' => $record['source_account_number'], 'sra_id' => $account?->id, 'chn' => $link['chn'], 'cscs_account_no' => $link['cscs_account_no'], 'action' => $account ? 'LINK_IDENTIFIERS' : 'RESTORE_MISSING_ACCOUNT', 'source_transaction' => $link['source_transaction'], 'source_unit_ids' => $link['source_unit_ids']];
                if ($account) {
                    $holder = DB::table('shareholders')->where('id', $account->shareholder_id)->first();
                    $normalize = fn ($value) => strtoupper(preg_replace('/\s+/', ' ', trim((string) $value)));
                    if (! $holder || $normalize($holder->full_name) !== $normalize($record['full_name'])) {
                        $blocking[] = 'Holder name differs from approved source for '.$record['source_account_number'];
                    }
                }
                if (! $account) {
                    $candidate = DB::table('shareholders')->where('account_no', $record['target_account_no'])->orWhereRaw('UPPER(full_name) = ?', [strtoupper($record['full_name'])])->exists();
                    if ($candidate) {
                        $blocking[] = 'Existing shareholder candidate for '.$record['source_account_number'].' requires investigation; automatic duplication refused.';
                    }
                    $missingQuantity = bcadd($missingQuantity, $record['quantity'], 6);
                    if (! $restoreMissing) {
                        $blocking[] = 'Missing account '.$record['source_account_number'].' requires explicit --restore-missing and a reconciled --expected-final-total.';
                    }
                }
                foreach (['chn', 'cscs_account_no'] as $column) {
                    if ($account && $account->$column && $link[$column] !== $account->$column) {
                        $blocking[] = 'Existing '.$column.' differs for '.$record['source_account_number'];
                    }
                    $owners = DB::table('shareholder_register_accounts')->where(function ($q) use ($link, $column) {
                        $q->where('chn', $link[$column])->orWhere('cscs_account_no', $link[$column]);
                    })->when($account, fn ($q) => $q->where('id', '!=', $account->id))->exists();
                    $external = DB::table('sra_external_identifiers')->where('identifier_value', $link[$column])->when($account, fn ($q) => $q->where('sra_id', '!=', $account->id))->exists();
                    if ($owners || $external) {
                        $blocking[] = 'Identifier conflict for '.$link[$column];
                    }
                }
                if ($account && ! DB::table('share_positions')->where('sra_id', $account->id)->where('share_class_id', $class->id)->exists()) {
                    $blocking[] = 'Existing account has no opening position: '.$record['source_account_number'];
                }
                $plan[] = $entry;
            }
            $before = (string) DB::table('share_positions')->where('share_class_id', $class->id)->sum('quantity');
            $after = bcadd($before, $missingQuantity, 6);
            if (bccomp($missingQuantity, '0', 6) > 0 && ($expectedFinalTotal === null || ! preg_match('/^\d+(\.\d{1,6})?$/', $expectedFinalTotal) || bccomp($after, $expectedFinalTotal, 6) !== 0)) {
                $blocking[] = 'Missing-account restoration requires a verified final class total equal to '.$after.'.';
            }
            $registerTotal = (string) DB::table('share_positions')->join('share_classes', 'share_classes.id', '=', 'share_positions.share_class_id')->where('share_classes.register_id', $register->id)->sum('share_positions.quantity');
            $registerAfter = bcadd($registerTotal, $missingQuantity, 6);
            if (bccomp($missingQuantity, '0', 6) > 0 && ($register->capital_behaviour_type ?? null) === 'constant' && bccomp((string) ($register->paid_up_capital ?? 0), '0', 6) > 0 && bccomp($registerAfter, (string) $register->paid_up_capital, 6) !== 0) {
                $blocking[] = 'Restoration would leave constant-capital register totals unbalanced; reconcile the approved opening balance first.';
            }
            $schemaChecks = ['approval_snapshot_table' => Schema::hasTable('cscs_batch_snapshots')];
            $schemaChecks['transaction_actor_fk'] = 'NO_CONSTRAINT';
            foreach (Schema::getForeignKeys('share_transactions') as $key) {
                if ($key['columns'] === ['created_by']) {
                    $schemaChecks['transaction_actor_fk'] = $key['foreign_table'];
                }
            }
            $report = ['schema_checks' => $schemaChecks, 'register_total_before' => $registerTotal, 'register_total_after' => $registerAfter, 'mode' => $apply ? 'APPLY' : 'PREVIEW', 'register_id' => $register->id, 'share_class_id' => $class->id, 'class_total_before' => $before, 'class_total_after' => $after, 'plan' => $plan, 'blocking' => $blocking];
            if (! $apply) {
                return $report;
            }
            $actor = AdminUser::findOrFail($actorId);
            if (! $actor->is_active || ! $actor->can('cscs.admin')) {
                throw new RuntimeException('An active administrator with cscs.admin is required.');
            }
            if ($blocking) {
                throw new RuntimeException(implode(' ', $blocking));
            }
            foreach ($plan as $index => $entry) {
                $record = $evidence['rows'][$index]['record'];
                $now = now();
                $beforeAccount = $entry['sra_id'] ? (array) DB::table('shareholder_register_accounts')->find($entry['sra_id']) : null;
                if (! $entry['sra_id']) {
                    $category = DB::table('shareholder_categories')->where('code', $record['category_code'])->where('is_active', true)->sole();
                    $shareholderId = DB::table('shareholders')->insertGetId([
                        'account_no' => $record['target_account_no'], 'holder_type' => $record['holder_type'],
                        'full_name' => $record['full_name'], 'email' => $record['target_email'], 'phone' => $record['target_phone'],
                        'email_is_verified' => false, 'phone_is_verified' => false, 'contact_suppressed' => true,
                        'status' => $record['status'], 'created_at' => $now, 'updated_at' => $now,
                    ]);
                    DB::table('shareholder_addresses')->insert(['shareholder_id' => $shareholderId, 'address_line1' => $record['address_line1'], 'state' => $record['state'], 'country' => $record['country'], 'is_primary' => true, 'created_at' => $now, 'updated_at' => $now]);
                    $entry['sra_id'] = DB::table('shareholder_register_accounts')->insertGetId(['shareholder_id' => $shareholderId, 'register_id' => $register->id, 'shareholder_no' => $record['source_account_number'], 'shareholder_category_id' => $category->id, 'status' => 'active', 'kyc_level' => 'basic', 'created_at' => $now, 'updated_at' => $now]);
                    DB::table('share_positions')->insert(['sra_id' => $entry['sra_id'], 'share_class_id' => $class->id, 'quantity' => $record['quantity'], 'holding_mode' => $record['holding_mode'], 'last_updated_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
                }
                DB::table('shareholder_register_accounts')->where('id', $entry['sra_id'])->update(['chn' => $entry['chn'], 'cscs_account_no' => $entry['cscs_account_no'], 'updated_at' => $now]);
                $saved = DB::table('shareholder_register_accounts')->find($entry['sra_id']);
                // Write the audit in this transaction; audit failure rolls the repair back.
                DB::table('user_activity_logs')->insert(['user_id' => $actorId, 'action' => 'cscs_small_register_repaired', 'metadata' => json_encode(['shareholder_id' => $saved->shareholder_id, 'sra_id' => $saved->id, 'before' => $beforeAccount, 'after' => (array) $saved, 'source_bundle_sha256' => $evidence['source_bundle_sha256'], 'source_record_hash' => $record['payload_sha256'], 'source_transaction' => $entry['source_transaction'], 'source_unit_ids' => $entry['source_unit_ids'], 'restored_quantity' => $beforeAccount ? '0.000000' : $record['quantity']], JSON_THROW_ON_ERROR), 'created_at' => $now, 'updated_at' => $now]);
            }
            $actualAfter = (string) DB::table('share_positions')->where('share_class_id', $class->id)->sum('quantity');
            if (bccomp($actualAfter, $after, 6) !== 0) {
                throw new RuntimeException('Position total changed during repair; rolling back.');
            }
            if (bccomp($missingQuantity, '0', 6) > 0) {
                $metadata = [];
                foreach (['total_units_outstanding', 'remaining_outstanding_units'] as $column) {
                    if (Schema::hasColumn('registers', $column)) {
                        $metadata[$column] = $registerAfter;
                    }
                }
                if ($metadata) {
                    DB::table('registers')->where('id', $register->id)->update($metadata + ['updated_at' => now()]);
                }
            }
            $report['blocking'] = [];
            $report['applied'] = true;

            return $report;
        });
    }
}
