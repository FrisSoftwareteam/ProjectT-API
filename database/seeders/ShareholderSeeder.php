<?php

namespace Database\Seeders;

use App\Models\Register;
use App\Models\Shareholder;
use App\Models\ShareholderAddress;
use App\Models\ShareholderIdentity;
use App\Models\ShareholderMandate;
use App\Models\ShareholderRegisterAccount;
use Illuminate\Database\Seeder;

class ShareholderSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $faker = \Faker\Factory::create();

        $shareholders = Shareholder::factory()->count(30)->create();
        $registerIds = Register::query()->pluck('id')->all();

        foreach ($shareholders as $shareholder) {
            ShareholderAddress::create([
                'shareholder_id' => $shareholder->id,
                'address_line1' => $faker->streetAddress,
                'address_line2' => $faker->secondaryAddress,
                'city' => $faker->city,
                'state' => $faker->state,
                'postal_code' => $faker->postcode,
                'country' => 'Nigeria',
                'is_primary' => true,
                'valid_from' => $faker->dateTimeBetween('-5 years', 'now')->format('Y-m-d'),
            ]);

            ShareholderIdentity::create([
                'shareholder_id' => $shareholder->id,
                'id_type' => $faker->randomElement(['passport', 'drivers_license', 'nin', 'bvn', 'cac_cert']),
                'id_value' => strtoupper($faker->bothify('??######??')),
                'issued_on' => $faker->dateTimeBetween('-10 years', '-1 years')->format('Y-m-d'),
                'expires_on' => $faker->dateTimeBetween('+1 years', '+10 years')->format('Y-m-d'),
                'verified_status' => 'pending',
            ]);

            ShareholderMandate::create([
                'shareholder_id' => $shareholder->id,
                'bank_name' => $faker->randomElement(['Access Bank', 'GTBank', 'First Bank', 'Zenith Bank', 'UBA']),
                'account_name' => $shareholder->full_name,
                'account_number' => $faker->numerify('##########'),
                'bvn' => $shareholder->bvn,
                'status' => 'pending',
            ]);

            if (!empty($registerIds)) {
                ShareholderRegisterAccount::create([
                    'shareholder_id' => $shareholder->id,
                    'register_id' => $faker->randomElement($registerIds),
                    'shareholder_no' => 'SH' . $faker->numerify('######'),
                    'chn' => $faker->optional()->numerify('########'),
                    'cscs_account_no' => $faker->optional()->numerify('##########'),
                    'residency_status' => $faker->randomElement(['resident', 'non_resident']),
                    'kyc_level' => $faker->randomElement(['basic', 'standard', 'enhanced']),
                    'status' => 'active',
                ]);
            }
        }
    }
}
