<?php

namespace Database\Seeders;

use App\Models\AdminUser;
use App\Models\Register;
use App\Models\ShareClass;
use App\Models\ShareLot;
use App\Models\SharePosition;
use App\Models\ShareTransaction;
use App\Models\ShareholderRegisterAccount;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class SharePositionLotTransactionSeeder extends Seeder
{
    public function run(): void
    {
        $faker = \Faker\Factory::create();
        $actorId = AdminUser::query()->value('id');

        // Ensure each register has at least one share class.
        $registers = Register::query()->get();
        foreach ($registers as $register) {
            ShareClass::query()->firstOrCreate(
                ['register_id' => $register->id, 'class_code' => 'ORD'],
                [
                    'currency' => 'NGN',
                    'par_value' => 0.50,
                    'description' => 'Ordinary Shares',
                    'withholding_tax_rate' => 10.00,
                ]
            );
        }

        $sras = ShareholderRegisterAccount::query()->with('register')->get();
        foreach ($sras as $sra) {
            if (!$sra->register_id) {
                continue;
            }

            $class = ShareClass::query()
                ->where('register_id', $sra->register_id)
                ->inRandomOrder()
                ->first();

            if (!$class) {
                continue;
            }

            $position = SharePosition::query()->firstOrCreate(
                [
                    'sra_id' => $sra->id,
                    'share_class_id' => $class->id,
                ],
                [
                    'quantity' => $faker->randomFloat(6, 100, 10000),
                    'holding_mode' => $faker->randomElement(['demat', 'paper']),
                    'last_updated_at' => now(),
                ]
            );

            $lotRef = 'SEED-' . $sra->id . '-' . $class->id . '-001';
            $lotExists = ShareLot::query()
                ->where('sra_id', $sra->id)
                ->where('share_class_id', $class->id)
                ->where('lot_ref', $lotRef)
                ->exists();

            if (!$lotExists) {
                ShareLot::query()->create([
                    'sra_id' => $sra->id,
                    'share_class_id' => $class->id,
                    'lot_ref' => $lotRef,
                    'source_type' => $faker->randomElement(['allotment', 'bonus', 'rights', 'transfer_in', 'demat_in']),
                    'quantity' => $position->quantity,
                    'acquired_at' => now()->subDays($faker->numberBetween(30, 900)),
                    'status' => 'open',
                ]);
            }

            $hasTx = ShareTransaction::query()
                ->where('sra_id', $sra->id)
                ->where('share_class_id', $class->id)
                ->exists();

            if (!$hasTx) {
                ShareTransaction::query()->create([
                    'sra_id' => $sra->id,
                    'share_class_id' => $class->id,
                    'tx_type' => $faker->randomElement(['allot', 'bonus', 'rights', 'transfer_in', 'demat_in']),
                    'quantity' => $position->quantity,
                    'tx_ref' => 'TX-' . strtoupper(Str::random(10)),
                    'tx_date' => now()->subDays($faker->numberBetween(30, 900)),
                    'created_by' => $actorId,
                ]);
            }
        }
    }
}
