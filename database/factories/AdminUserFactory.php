<?php

namespace Database\Factories;

use App\Models\AdminUser;
use Faker\Factory as FakerFactory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AdminUser>
 */
class AdminUserFactory extends Factory
{
    protected $model = AdminUser::class;

    public function definition(): array
    {
        // Use a static Faker instance so we don't recreate it every time
        static $faker = null;

        if ($faker === null) {
            $faker = FakerFactory::create();
        }

        return [
            'microsoft_id' => $faker->unique()->uuid(),
            'email'        => $faker->unique()->safeEmail(),
            'first_name'   => $faker->firstName(),
            'last_name'    => $faker->lastName(),
            'department'   => $faker->randomElement([
                'IT', 'Finance', 'HR', 'Operations', 'Compliance',
                'Customer Service', 'Marketing', 'Legal',
            ]),
            'is_active'    => $faker->boolean(90), // 90% chance of being active
            'last_login_at' => $faker->optional(0.7)->dateTimeBetween('-30 days', 'now'),
            'profile_picture' => $faker->optional(0.3)->imageUrl(200, 200, 'people'),
            'microsoft_data' => [
                'displayName'       => $faker->name(),
                'jobTitle'          => $faker->jobTitle(),
                'officeLocation'    => $faker->city(),
                'preferredLanguage' => 'en-US',
            ],
        ];
    }
}
