<?php

namespace Database\Seeders;

use App\Models\AdminUser;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Always seed roles and permissions (required for production)
        $this->call([
            RolesAndPermissionsSeeder::class,
            DividendApprovalRolesSeeder::class,
        ]);

        // Only seed test data in non-production environments
        if (!app()->environment('production')) {
            $this->command->info('Seeding test data...');
            
            // Create test admin user (using AdminUser, not User)
            $testAdmin = AdminUser::factory()->create([
                'microsoft_id' => 'test-admin-microsoft-id',
                'email' => 'test@example.com',
                'first_name' => 'Test',
                'last_name' => 'Admin',
                'department' => 'IT',
                'is_active' => true,
                'microsoft_data' => [
                    'displayName' => 'Test Admin',
                    'jobTitle' => 'System Administrator',
                    'officeLocation' => 'Head Office',
                    'preferredLanguage' => 'en-US',
                ],
            ]);
            
            // Assign Super Admin role
            $testAdmin->assignRole('Super Admin');
            $this->command->info('✓ Test admin created: test@example.com');

            // Seed additional test admin users
            $this->call([
                AdminUserSeeder::class,
                CompanyRegisterSeeder::class,
                ShareholderSeeder::class,
                SharePositionLotTransactionSeeder::class,
                SraGuardianSeeder::class,
                ProbateSeeder::class,
                DividendSeeder::class,
            ]);
            
            $this->command->info('✓ Test data seeded successfully!');
        }
    }
}
