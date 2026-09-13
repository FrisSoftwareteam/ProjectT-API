<?php

namespace App\Console\Commands;

use App\Models\FrisMigrationBatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PublishFrisBatch extends Command
{
    protected $signature = 'fris:publish-batch
        {batch_id : Reconciled FRIS migration batch ID}
        {--dry-run : Check publish readiness without writing domain tables}';

    protected $description = 'Publish a reconciled FRIS batch into Project T domain tables';

    public function handle(): int
    {
        $batch = FrisMigrationBatch::find((int) $this->argument('batch_id'));
        if ($batch === null) {
            $this->error('FRIS migration batch not found.');

            return self::FAILURE;
        }

        $reconciliation = $batch->reconciliation ?? [];
        if (($reconciliation['passed'] ?? false) !== true && ($reconciliation['passed'] ?? null) !== 1) {
            $this->error('FRIS batch has not passed reconciliation.');

            return self::FAILURE;
        }
        if ($batch->published_at !== null) {
            $this->error('FRIS batch has already been published.');

            return self::FAILURE;
        }

        $profileErrors = DB::table('fris_migration_profiles')->where('batch_id', $batch->id)->where('status', 'ERROR')->count();
        $unitErrors = DB::table('fris_migration_units')->where('batch_id', $batch->id)->where('status', 'ERROR')->count();
        if ($profileErrors > 0 || $unitErrors > 0) {
            $this->error("Cannot publish batch with errors. Profiles: {$profileErrors}, units: {$unitErrors}");

            return self::FAILURE;
        }

        $this->info(($this->option('dry-run') ? 'Checking' : 'Publishing').' FRIS batch '.$batch->id);
        if ($this->option('dry-run')) {
            $this->line('Profiles: '.DB::table('fris_migration_profiles')->where('batch_id', $batch->id)->count());
            $this->line('Units: '.DB::table('fris_migration_units')->where('batch_id', $batch->id)->count());
            $this->line('Register code: '.$batch->register_code);

            return self::SUCCESS;
        }

        DB::transaction(function () use ($batch) {
            $now = now();
            $target = $this->ensureRegisterTargets($batch, $now);
            $categoryId = DB::table('shareholder_categories')->where('code', 'A')->value('id')
                ?? DB::table('shareholder_categories')->orderBy('id')->value('id');

            $profiles = DB::table('fris_migration_profiles')
                ->where('batch_id', $batch->id)
                ->where('status', 'VALID')
                ->orderBy('id')
                ->get();

            foreach ($profiles as $profile) {
                $sourceKey = $profile->register_code.'|'.$profile->account_number;
                $hash = hash('sha256', $sourceKey);
                $accountNo = 'FR'.strtoupper(substr($hash, 0, 18));
                $email = 'fris-'.$accountNo.'@invalid.projectt.local';
                $phone = 'FRIS'.strtoupper(substr($hash, 0, 28));
                $name = $profile->normalized_name ?: 'FRIS Account '.$profile->account_number;

                $shareholderId = DB::table('shareholders')->insertGetId([
                    'account_no' => $accountNo,
                    'holder_type' => $this->holderType($name),
                    'full_name' => $name,
                    'email' => $email,
                    'email_is_verified' => false,
                    'phone' => $phone,
                    'phone_is_verified' => false,
                    'contact_suppressed' => true,
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $normalized = json_decode((string) $profile->normalized_data, true) ?: [];
                $addressId = DB::table('shareholder_addresses')->insertGetId([
                    'shareholder_id' => $shareholderId,
                    'address_line1' => $normalized['address'] ?: 'Unknown legacy address',
                    'country' => 'Nigeria',
                    'is_primary' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                if (! empty($normalized['bankac'])) {
                    DB::table('shareholder_bank_mandates')->insertOrIgnore([
                        'shareholder_id' => $shareholderId,
                        'bank_name' => $normalized['clearing_no'] ? 'FRIS Clearing '.$normalized['clearing_no'] : 'FRIS Legacy Bank',
                        'account_name' => $name,
                        'account_number' => $normalized['bankac'],
                        'status' => 'active',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                $sraId = DB::table('shareholder_register_accounts')->insertGetId([
                    'shareholder_id' => $shareholderId,
                    'register_id' => $target['register_id'],
                    'shareholder_category_id' => $categoryId,
                    'shareholder_no' => (string) $profile->account_number,
                    'kyc_level' => 'basic',
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('share_positions')->insert([
                    'sra_id' => $sraId,
                    'share_class_id' => $target['share_class_id'],
                    'quantity' => $profile->source_holdings ?? 0,
                    'holding_mode' => 'paper',
                    'last_updated_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('fris_migration_profiles')->where('id', $profile->id)->update([
                    'shareholder_id' => $shareholderId,
                    'address_id' => $addressId,
                    'sra_id' => $sraId,
                    'published_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('fris_migration_crosswalks')->insert([
                    'batch_id' => $batch->id,
                    'source_table' => 'profiles',
                    'source_key' => $sourceKey,
                    'source_key_hash' => $profile->source_key_hash,
                    'target_table' => 'shareholder_register_accounts',
                    'target_id' => $sraId,
                    'row_hash' => $profile->row_hash,
                    'metadata' => json_encode(['shareholder_id' => $shareholderId]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $sraByKey = DB::table('fris_migration_profiles')
                ->where('batch_id', $batch->id)
                ->whereNotNull('sra_id')
                ->get()
                ->mapWithKeys(fn ($profile) => [$profile->register_code.'|'.$profile->account_number => $profile->sra_id]);

            DB::table('fris_migration_units')
                ->where('batch_id', $batch->id)
                ->where('status', 'VALID')
                ->orderBy('id')
                ->chunkById(2000, function ($units) use ($batch, $target, $sraByKey, $now) {
                    foreach ($units as $unit) {
                        $sraId = $sraByKey[$unit->register_code.'|'.$unit->account_number] ?? null;
                        if ($sraId === null) {
                            continue;
                        }

                        $quantity = (float) $unit->source_units;
                        $txType = $quantity < 0 ? 'transfer_out' : 'transfer_in';
                        $txId = DB::table('share_transactions')->insertGetId([
                            'sra_id' => $sraId,
                            'share_class_id' => $target['share_class_id'],
                            'tx_type' => $txType,
                            'quantity' => abs($quantity),
                            'tx_ref' => 'FRIS-U'.$unit->fris_unit_id,
                            'tx_date' => $unit->issue_date ?: '1900-01-01 00:00:00',
                            'created_at' => $now,
                        ]);

                        $lotId = null;
                        if ($quantity > 0) {
                            $lotId = DB::table('share_lots')->insertGetId([
                                'sra_id' => $sraId,
                                'share_class_id' => $target['share_class_id'],
                                'lot_ref' => 'FRIS-U'.$unit->fris_unit_id,
                                'source_type' => 'transfer_in',
                                'quantity' => $quantity,
                                'acquired_at' => $unit->issue_date ?: '1900-01-01 00:00:00',
                                'status' => 'open',
                                'created_at' => $now,
                                'updated_at' => $now,
                            ]);
                        }

                        DB::table('fris_migration_units')->where('id', $unit->id)->update([
                            'profile_stage_id' => null,
                            'share_lot_id' => $lotId,
                            'share_transaction_id' => $txId,
                            'published_at' => $now,
                            'updated_at' => $now,
                        ]);

                        DB::table('fris_migration_crosswalks')->insert([
                            'batch_id' => $batch->id,
                            'source_table' => 'units',
                            'source_key' => 'units|'.$unit->fris_unit_id,
                            'source_key_hash' => $unit->source_key_hash,
                            'target_table' => 'share_transactions',
                            'target_id' => $txId,
                            'row_hash' => $unit->row_hash,
                            'metadata' => json_encode(['share_lot_id' => $lotId]),
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                });

            $batch->update([
                'status' => FrisMigrationBatch::PUBLISHED,
                'published_at' => $now,
            ]);

            $this->finalizeOperationalSetup($target['register_id'], $target['share_class_id'], $batch, $now);
        });

        $this->info('FRIS batch '.$batch->id.' published.');

        return self::SUCCESS;
    }

    /** @return array{company_id:int,register_id:int,share_class_id:int} */
    private function ensureRegisterTargets(FrisMigrationBatch $batch, mixed $now): array
    {
        $issuerCode = 'FRIS-'.$batch->register_code;
        $companyId = DB::table('companies')->where('issuer_code', $issuerCode)->value('id');
        if ($companyId === null) {
            $companyId = DB::table('companies')->insertGetId([
                'issuer_code' => $issuerCode,
                'name' => $batch->company_name ?: $issuerCode,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $registerId = DB::table('registers')
            ->where('company_id', $companyId)
            ->where('register_code', (string) $batch->register_code)
            ->value('id');
        if ($registerId === null) {
            $registerId = DB::table('registers')->insertGetId([
                'company_id' => $companyId,
                'register_code' => (string) $batch->register_code,
                'name' => $batch->company_name ?: 'FRIS Register '.$batch->register_code,
                'is_default' => true,
                'status' => 'active',
                'instrument_type' => 'equity',
                'instrument_type_id' => DB::table('instrument_types')->where('code', 'ordinary_share')->value('id'),
                'capital_behaviour_type' => 'constant',
                'unit_precision_type' => 'decimal',
                'decimal_precision' => 6,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $shareClassId = DB::table('share_classes')
            ->where('register_id', $registerId)
            ->where('class_code', 'ORD')
            ->value('id');
        if ($shareClassId === null) {
            $shareClassId = DB::table('share_classes')->insertGetId([
                'register_id' => $registerId,
                'class_code' => 'ORD',
                'currency' => 'NGN',
                'par_value' => 0,
                'description' => 'Ordinary shares imported from FRIS',
                'name' => 'Ordinary',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return ['company_id' => $companyId, 'register_id' => $registerId, 'share_class_id' => $shareClassId];
    }

    private function finalizeOperationalSetup(int $registerId, int $shareClassId, FrisMigrationBatch $batch, mixed $now): void
    {
        $positionTotal = DB::table('share_positions')
            ->where('share_class_id', $shareClassId)
            ->sum('quantity');

        DB::table('registers')->where('id', $registerId)->update([
            'instrument_type' => 'equity',
            'instrument_type_id' => DB::table('instrument_types')->where('code', 'ordinary_share')->value('id'),
            'capital_behaviour_type' => 'constant',
            'paid_up_capital' => $positionTotal,
            'total_units_outstanding' => $positionTotal,
            'remaining_outstanding_units' => $positionTotal,
            'unit_precision_type' => 'decimal',
            'decimal_precision' => 6,
            'updated_at' => $now,
        ]);

        DB::table('cscs_security_mappings')->updateOrInsert(
            ['security_code' => 'FRIS'.$batch->register_code],
            [
                'register_id' => $registerId,
                'share_class_id' => $shareClassId,
                'is_active' => true,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );
    }

    private function holderType(string $name): string
    {
        return preg_match('/\b(LTD|LIMITED|PLC|BANK|FUND|TRUST|ASSURANCE|INSURANCE|COMPANY|CO\\.)\b/i', $name)
            ? 'corporate'
            : 'individual';
    }
}
