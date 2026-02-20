<?php

namespace Database\Seeders;

use App\Models\AdminUser;
use App\Models\Shareholder;
use App\Models\ShareholderRegisterAccount;
use App\Models\SraGuardian;
use Illuminate\Database\Seeder;

class SraGuardianSeeder extends Seeder
{
    public function run(): void
    {
        $faker = \Faker\Factory::create();
        $verifierId = AdminUser::query()->value('id');

        $shareholderIds = Shareholder::query()->pluck('id')->all();
        if (empty($shareholderIds)) {
            return;
        }

        $sras = ShareholderRegisterAccount::query()->inRandomOrder()->take(15)->get();
        foreach ($sras as $sra) {
            $existing = SraGuardian::query()->where('sra_id', $sra->id)->exists();
            if ($existing) {
                continue;
            }

            $guardianShareholderId = $faker->optional(0.6)->randomElement($shareholderIds);
            $guardianName = $guardianShareholderId
                ? (Shareholder::query()->find($guardianShareholderId)?->full_name ?? $faker->name())
                : $faker->name();

            SraGuardian::query()->create([
                'sra_id' => $sra->id,
                'guardian_shareholder_id' => $guardianShareholderId,
                'guardian_name' => $guardianName,
                'guardian_contact' => $faker->phoneNumber(),
                'document_ref' => 'GDN-' . strtoupper($faker->bothify('??#####')),
                'valid_from' => now()->subMonths($faker->numberBetween(1, 18))->toDateString(),
                'valid_to' => now()->addMonths($faker->numberBetween(6, 36))->toDateString(),
                'verified_status' => $faker->randomElement(['pending', 'verified']),
                'verified_by' => $verifierId,
                'verified_at' => $verifierId ? now()->subDays($faker->numberBetween(1, 30)) : null,
                'permissions' => ['receive_dividend_notices', 'act_for_minor'],
            ]);
        }
    }
}
