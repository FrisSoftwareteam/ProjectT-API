<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Register;
use Illuminate\Database\Seeder;

class CompanyRegisterSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $company = Company::query()->firstOrCreate(
            ['issuer_code' => 'TEST-ISSUER'],
            [
                'name' => 'Test Company',
                'rc_number' => 'RC123456',
                'tin' => 'TIN123456',
                'status' => 'active',
            ]
        );

        Register::query()->firstOrCreate(
            [
                'company_id' => $company->id,
                'register_code' => 'REG-DEFAULT',
            ],
            [
                'name' => 'Default Register',
                'is_default' => true,
                'status' => 'active',
            ]
        );
    }
}
