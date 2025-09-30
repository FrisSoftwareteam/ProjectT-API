<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AdminUser>
 */
class AdminUserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'microsoft_id' => $this->faker->unique()->uuid(),
            'email' => $this->faker->unique()->safeEmail(),
            'first_name' => $this->faker->firstName(),
            'last_name' => $this->faker->lastName(),
            'department' => $this->faker->randomElement([
                'IT', 'Finance', 'HR', 'Operations', 'Compliance', 
                'Customer Service', 'Marketing', 'Legal'
            ]),
            'is_active' => $this->faker->boolean(90), // 90% chance of being active
            'last_login_at' => $this->faker->optional(0.7)->dateTimeBetween('-30 days', 'now'),
            'profile_picture' => $this->faker->optional(0.3)->imageUrl(200, 200, 'people'),
            'microsoft_data' => [
                'displayName' => $this->faker->name(),
                'jobTitle' => $this->faker->jobTitle(),
                'officeLocation' => $this->faker->city(),
                'preferredLanguage' => 'en-US',
            ],
        ];
    }
}
