<?php

namespace Database\Seeders;

use App\Models\AdminUser;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create a super admin user
        $superAdmin = AdminUser::create([
            'microsoft_id' => 'super-admin-microsoft-id',
            'email' => 'superadmin@company.com',
            'first_name' => 'Super',
            'last_name' => 'Admin',
            'department' => 'IT',
            'is_active' => true,
            'microsoft_data' => [
                'displayName' => 'Super Admin',
                'jobTitle' => 'System Administrator',
                'officeLocation' => 'Head Office',
                'preferredLanguage' => 'en-US',
            ],
        ]);

        // Assign Super Admin role
        $superAdmin->assignRole('Super Admin');

        // Create sample admin users using factory
        $users = AdminUser::factory(10)->create();

        // Assign random roles to sample users
        $roles = [
            'Admin',
            'Shareholder Management',
            'Certificate Management',
            'Warrant Management',
            'Customer Service',
            'Customer Support',
            'Finance',
            'Marketing',
            'Compliance',
            'Reconciliation',
            'Internal Audit',
            'Mailing'
        ];

        foreach ($users as $user) {
            $randomRole = $roles[array_rand($roles)];
            $user->assignRole($randomRole);
        }
    }
}
