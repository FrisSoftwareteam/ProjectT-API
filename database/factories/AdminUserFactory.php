<?php

namespace Database\Factories;

use App\Models\AdminUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\EloquentFactories\Factory<\App\Models\AdminUser>
 */
class AdminUserFactory extends Factory
{
    protected $model = AdminUser::class;

    public function definition(): array
    {
        return [
            'microsoft_id' => fake()->unique()->uuid(),
            'email'        => fake()->unique()->safeEmail(),
            'first_name'   => fake()->firstName(),
            'last_name'    => fake()->lastName(),
            'department'   => fake()->randomElement([
                'IT', 'Finance', 'HR', 'Operations', 'Compliance',
                'Customer Service', 'Marketing', 'Legal',
            ]),
            'is_active'    => fake()->boolean(90), // 90% chance of being active
            'last_login_at' => fake()->optional(0.7)->dateTimeBetween('-30 days', 'now'),
            'profile_picture' => fake()->optional(0.3)->imageUrl(200, 200, 'people'),
            'microsoft_data' => [
                'displayName'       => fake()->name(),
                'jobTitle'          => fake()->jobTitle(),
                'officeLocation'    => fake()->city(),
                'preferredLanguage' => 'en-US',
            ],
        ];
    }
}
