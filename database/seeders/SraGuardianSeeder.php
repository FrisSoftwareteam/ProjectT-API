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

            $guardianShareholderId = mt_rand(1, 10) <= 6 ? $shareholderIds[array_rand($shareholderIds)] : null;
            $guardianName = $guardianShareholderId
                ? (Shareholder::query()->find($guardianShareholderId)?->full_name ?? ('Guardian ' . $sra->id))
                : ('Guardian ' . $sra->id);

            SraGuardian::query()->create([
                'sra_id' => $sra->id,
                'guardian_shareholder_id' => $guardianShareholderId,
                'guardian_name' => $guardianName,
                'guardian_contact' => '0803' . str_pad((string) mt_rand(1000000, 9999999), 7, '0', STR_PAD_LEFT),
                'document_ref' => 'GDN-' . strtoupper(substr(md5((string) $sra->id), 0, 7)),
                'valid_from' => now()->subMonths(mt_rand(1, 18))->toDateString(),
                'valid_to' => now()->addMonths(mt_rand(6, 36))->toDateString(),
                'verified_status' => (mt_rand(0, 1) === 1 ? 'pending' : 'verified'),
                'verified_by' => $verifierId,
                'verified_at' => $verifierId ? now()->subDays(mt_rand(1, 30)) : null,
                'permissions' => ['receive_dividend_notices', 'act_for_minor'],
            ]);
        }
    }
}
