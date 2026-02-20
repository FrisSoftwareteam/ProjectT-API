<?php

namespace Database\Seeders;

use App\Models\Register;
use App\Models\Shareholder;
use App\Models\ShareholderAddress;
use App\Models\ShareholderIdentity;
use App\Models\ShareholderMandate;
use App\Models\ShareholderRegisterAccount;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ShareholderSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $registerIds = Register::query()->pluck('id')->all();
        $bankNames = ['Access Bank', 'GTBank', 'First Bank', 'Zenith Bank', 'UBA'];
        $idTypes = ['passport', 'drivers_license', 'nin', 'bvn', 'cac_cert'];
        $sexes = ['male', 'female', 'other'];

        for ($i = 1; $i <= 30; $i++) {
            $isCorporate = $i % 5 === 0;
            $firstName = $isCorporate ? 'Corp' : 'Holder' . $i;
            $lastName = $isCorporate ? 'Limited' : 'User';
            $fullName = $isCorporate ? "Company {$i} Limited" : "{$firstName} {$lastName}";

            $shareholder = Shareholder::query()->firstOrCreate(
                ['email' => "shareholder{$i}@example.com"],
                [
                    'account_no' => str_pad((string) (1000000000 + $i), 10, '0', STR_PAD_LEFT),
                    'holder_type' => $isCorporate ? 'corporate' : 'individual',
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'middle_name' => null,
                    'full_name' => $fullName,
                    'phone' => '0803' . str_pad((string) (1000000 + $i), 7, '0', STR_PAD_LEFT),
                    'date_of_birth' => $isCorporate ? null : now()->subYears(25 + ($i % 20))->toDateString(),
                    'sex' => $isCorporate ? null : $sexes[array_rand($sexes)],
                    'rc_number' => $isCorporate ? 'RC' . str_pad((string) (200000 + $i), 6, '0', STR_PAD_LEFT) : null,
                    'nin' => $isCorporate ? null : str_pad((string) (30000000000 + $i), 11, '0', STR_PAD_LEFT),
                    'bvn' => $isCorporate ? null : str_pad((string) (40000000000 + $i), 11, '0', STR_PAD_LEFT),
                    'tax_id' => $isCorporate ? 'TAX' . str_pad((string) (500000 + $i), 6, '0', STR_PAD_LEFT) : null,
                    'next_of_kin_name' => $isCorporate ? null : "Next Of Kin {$i}",
                    'next_of_kin_phone' => $isCorporate ? null : '0807' . str_pad((string) (2000000 + $i), 7, '0', STR_PAD_LEFT),
                    'next_of_kin_relationship' => $isCorporate ? null : 'spouse',
                    'status' => 'active',
                ]
            );

            ShareholderAddress::query()->firstOrCreate(
                ['shareholder_id' => $shareholder->id, 'is_primary' => true],
                [
                    'address_line1' => "Address Line 1 - {$i}",
                    'address_line2' => "Address Line 2 - {$i}",
                    'city' => 'Lagos',
                    'state' => 'Lagos',
                    'postal_code' => str_pad((string) (100000 + $i), 6, '0', STR_PAD_LEFT),
                    'country' => 'Nigeria',
                    'valid_from' => now()->subYears(2)->toDateString(),
                ]
            );

            $idType = $idTypes[$i % count($idTypes)];
            $idValue = 'ID' . str_pad((string) (100000 + $i), 6, '0', STR_PAD_LEFT) . 'X';
            ShareholderIdentity::query()->firstOrCreate(
                ['shareholder_id' => $shareholder->id, 'id_type' => $idType, 'id_value' => $idValue],
                [
                    'issued_on' => now()->subYears(5)->toDateString(),
                    'expires_on' => now()->addYears(5)->toDateString(),
                    'verified_status' => 'pending',
                ]
            );

            $bankName = $bankNames[$i % count($bankNames)];
            $accountNumber = str_pad((string) (7000000000 + $i), 10, '0', STR_PAD_LEFT);
            ShareholderMandate::query()->firstOrCreate(
                ['shareholder_id' => $shareholder->id, 'bank_name' => $bankName, 'account_number' => $accountNumber],
                [
                    'account_name' => $shareholder->full_name,
                    'bvn' => $shareholder->bvn,
                    'status' => 'pending',
                ]
            );

            if (!empty($registerIds)) {
                $registerId = $registerIds[array_rand($registerIds)];
                ShareholderRegisterAccount::query()->firstOrCreate(
                    ['shareholder_id' => $shareholder->id, 'register_id' => $registerId],
                    [
                        'shareholder_no' => 'SH' . str_pad((string) $i, 6, '0', STR_PAD_LEFT),
                        'chn' => $i % 2 === 0 ? str_pad((string) (90000000 + $i), 8, '0', STR_PAD_LEFT) : null,
                        'cscs_account_no' => $i % 3 === 0 ? str_pad((string) (8000000000 + $i), 10, '0', STR_PAD_LEFT) : null,
                        'residency_status' => $i % 2 === 0 ? 'resident' : 'non_resident',
                        'kyc_level' => ['basic', 'standard', 'enhanced'][$i % 3],
                        'status' => 'active',
                    ]
                );
            }
        }
    }
}
