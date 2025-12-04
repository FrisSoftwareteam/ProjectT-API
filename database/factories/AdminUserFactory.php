<?php

namespace Database\Factories;

use App\Models\AdminUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Carbon\Carbon;

/**
 * @extends \Illuminate\Database\Eloquent\Factories.Factory<\App\Models\AdminUser>
 */
class AdminUserFactory extends Factory
{
    protected $model = AdminUser::class;

    public function definition(): array
    {
        // basic pools for fake-ish data
        $firstNames = ['John', 'Jane', 'Michael', 'Sarah', 'David', 'Mary', 'Daniel', 'Grace', 'Joshua', 'Tosin'];
        $lastNames  = ['Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Musa', 'Okafor', 'Adebayo', 'Esan'];

        $departments = [
            'IT', 'Finance', 'HR', 'Operations', 'Compliance',
            'Customer Service', 'Marketing', 'Legal',
        ];

        $firstName = $firstNames[array_rand($firstNames)];
        $lastName  = $lastNames[array_rand($lastNames)];

        // 90% chance active
        $isActive = random_int(1, 100) <= 90;

        // 70% chance of having a last_login_at within the last 30 days
        $lastLoginAt = null;
        if (random_int(1, 100) <= 70) {
            $daysAgo = random_int(0, 30);
            $lastLoginAt = Carbon::now()->subDays($daysAgo);
        }

        // 30% chance of having a profile picture
        $profilePicture = null;
        if (random_int(1, 100) <= 30) {
            // simple avatar placeholder
            $profilePicture = 'https://i.pravatar.cc/200?u=' . Str::uuid()->toString();
        }

        return [
            'microsoft_id'   => (string) Str::uuid(),
            'email'          => strtolower($firstName . '.' . $lastName) . '+' . Str::random(5) . '@example.com',
            'first_name'     => $firstName,
            'last_name'      => $lastName,
            'department'     => $departments[array_rand($departments)],
            'is_active'      => $isActive,
            'last_login_at'  => $lastLoginAt,
            'profile_picture'=> $profilePicture,
            'microsoft_data' => [
                'displayName'       => $firstName . ' ' . $lastName,
                'jobTitle'          => 'System ' . ['Administrator', 'Analyst', 'Manager', 'Engineer'][array_rand(['Administrator', 'Analyst', 'Manager', 'Engineer'])],
                'officeLocation'    => ['Head Office', 'Lagos', 'Abuja', 'Remote'][array_rand(['Head Office', 'Lagos', 'Abuja', 'Remote'])],
                'preferredLanguage' => 'en-US',
            ],
        ];
    }
}
