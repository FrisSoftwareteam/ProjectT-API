<?php

namespace Database\Seeders;

use App\Models\AdminUser;
use App\Models\ProbateBeneficiary;
use App\Models\SharePosition;
use App\Models\Shareholder;
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

            $caseId = DB::table('probate_cases')->insertGetId([
                'shareholder_id' => $sourceSra->shareholder_id,
                'court_ref' => 'CRT-' . strtoupper(substr(md5((string) ($sourceSra->id . '-crt')), 0, 7)),
                'executor_name' => 'Executor ' . $sourceSra->id,
                'document_ref' => 'PRB-' . strtoupper(substr(md5((string) ($sourceSra->id . '-prb')), 0, 7)),
                'status' => (mt_rand(0, 1) === 1 ? 'pending' : 'granted'),
                'opened_at' => now()->subDays(mt_rand(1, 180)),
                'closed_at' => null,
            ]);

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
