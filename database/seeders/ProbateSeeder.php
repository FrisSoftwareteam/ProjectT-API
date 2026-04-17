<?php

namespace Database\Seeders;

use App\Models\AdminUser;
use App\Models\ProbateBeneficiary;
use App\Models\SharePosition;
use App\Models\Shareholder;
use App\Models\ShareholderIdentity;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProbateSeeder extends Seeder
{
    public function run(): void
    {
        $executorId = AdminUser::query()->value('id');

        $positions = SharePosition::query()
            ->with('registerAccount')
            ->where('quantity', '>', 0)
            ->inRandomOrder()
            ->take(8)
            ->get();

        if ($positions->isEmpty()) {
            return;
        }

        $allShareholderIds = Shareholder::query()->pluck('id')->all();

        foreach ($positions as $position) {
            $sourceSra = $position->registerAccount;
            if (!$sourceSra || !$sourceSra->shareholder_id) {
                continue;
            }

            $shareholder = Shareholder::query()->find($sourceSra->shareholder_id);
            if (! $shareholder) {
                continue;
            }

            $caseType = mt_rand(0, 1) === 1 ? 'probate' : 'letters_of_administration';

            $status = mt_rand(0, 4) === 0 ? 'closed' : 'pending';

            $caseId = DB::table('probate_cases')->insertGetId([
                'shareholder_id' => $sourceSra->shareholder_id,
                'case_type' => $caseType,
                'court_ref' => 'CRT-' . strtoupper(substr(md5((string) ($sourceSra->id . '-crt')), 0, 7)),
                'executor_name' => $shareholder->full_name,
                'document_ref' => 'PRB-' . strtoupper(substr(md5((string) ($sourceSra->id . '-prb')), 0, 7)),
                'grant_date' => now()->subDays(mt_rand(1, 365))->toDateString(),
                'original_first_name' => $shareholder->first_name,
                'original_last_name' => $shareholder->last_name,
                'original_middle_name' => $shareholder->middle_name,
                'original_full_name' => $shareholder->full_name,
                'status' => $status,
                'opened_at' => now()->subDays(mt_rand(1, 180)),
                'closed_at' => $status === 'closed' ? now()->subDays(mt_rand(0, 30)) : null,
            ]);

            $repShareholder = Shareholder::query()
                ->where('id', '!=', $shareholder->id)
                ->inRandomOrder()
                ->first();

            if ($repShareholder) {
                $identity = ShareholderIdentity::query()
                    ->where('shareholder_id', $repShareholder->id)
                    ->orderByDesc('verified_at')
                    ->first();

                $address = DB::table('shareholder_addresses')
                    ->where('shareholder_id', $repShareholder->id)
                    ->orderByDesc('is_primary')
                    ->value('address_line1');

                DB::table('estate_case_representatives')->insert([
                    'probate_case_id' => $caseId,
                    'shareholder_id' => $repShareholder->id,
                    'representative_type' => $caseType === 'probate' ? 'executor' : 'administrator',
                    'full_name' => $repShareholder->full_name
                        ?: trim(implode(' ', array_filter([
                            $repShareholder->first_name,
                            $repShareholder->middle_name,
                            $repShareholder->last_name,
                        ])))
                        ?: ($repShareholder->email ?: ('Shareholder #' . $repShareholder->id)),
                    'id_type' => $identity?->id_type ?? ($repShareholder->nin ? 'nin' : ($repShareholder->bvn ? 'bvn' : null)),
                    'id_value' => $identity?->id_value ?? ($repShareholder->nin ?: $repShareholder->bvn),
                    'email' => $repShareholder->email,
                    'phone' => $repShareholder->phone,
                    'address' => $address,
                    'is_primary' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $beneficiaryShareholderId = mt_rand(1, 10) <= 7 ? $allShareholderIds[array_rand($allShareholderIds)] : null;
            $beneficiarySraId = null;
            if ($beneficiaryShareholderId) {
                $beneficiarySraId = DB::table('shareholder_register_accounts')
                    ->where('shareholder_id', $beneficiaryShareholderId)
                    ->value('id');
            }

            $maxQty = max(1.0, (float) $position->quantity / 2);
            $qty = number_format(mt_rand(1000000, (int) max(1000000, floor($maxQty * 1000000))) / 1000000, 6, '.', '');

            ProbateBeneficiary::query()->create([
                'probate_case_id' => $caseId,
                'beneficiary_shareholder_id' => $beneficiaryShareholderId,
                'beneficiary_name' => $beneficiaryShareholderId ? null : ('Beneficiary ' . $caseId),
                'relationship' => ['spouse', 'child', 'executor', 'sibling'][mt_rand(0, 3)],
                'share_class_id' => $position->share_class_id,
                'sra_id' => $beneficiarySraId,
                'quantity' => $qty,
                'transfer_status' => 'pending',
                'executed_by' => $executorId ? null : null,
                'executed_at' => null,
            ]);
        }
    }
}
