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
        $faker = \Faker\Factory::create();
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
                'court_ref' => 'CRT-' . strtoupper($faker->bothify('??#####')),
                'executor_name' => $faker->name(),
                'document_ref' => 'PRB-' . strtoupper($faker->bothify('??#####')),
                'status' => $faker->randomElement(['pending', 'granted']),
                'opened_at' => now()->subDays($faker->numberBetween(1, 180)),
                'closed_at' => null,
            ]);

            $beneficiaryShareholderId = $faker->optional(0.7)->randomElement($allShareholderIds);
            $beneficiarySraId = null;
            if ($beneficiaryShareholderId) {
                $beneficiarySraId = DB::table('shareholder_register_accounts')
                    ->where('shareholder_id', $beneficiaryShareholderId)
                    ->value('id');
            }

            $maxQty = max(1.0, (float) $position->quantity / 2);
            $qty = $faker->randomFloat(6, 1, $maxQty);

            ProbateBeneficiary::query()->create([
                'probate_case_id' => $caseId,
                'beneficiary_shareholder_id' => $beneficiaryShareholderId,
                'beneficiary_name' => $beneficiaryShareholderId ? null : $faker->name(),
                'relationship' => $faker->randomElement(['spouse', 'child', 'executor', 'sibling']),
                'share_class_id' => $position->share_class_id,
                'sra_id' => $beneficiarySraId,
                'quantity' => $qty,
                'transfer_status' => 'pending',
                'executed_by' => null,
                'executed_at' => null,
            ]);
        }
    }
}
