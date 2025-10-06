<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
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
        ]);

        // Only seed test data in non-production environments
        if (!app()->environment('production')) {
            // User::factory(10)->create();

            User::factory()->create([
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

            // Seed test admin users with fake data
            $this->call([
                AdminUserSeeder::class,
            ]);
        }
    }
}
