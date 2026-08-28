<?php

namespace Database\Factories;

use App\Models\Shareholder;
use App\Models\ShareholderChangeRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ShareholderChangeRequest>
 */
class ShareholderChangeRequestFactory extends Factory
{
    protected $model = ShareholderChangeRequest::class;

    public function definition(): array
    {
        return [
            'shareholder_id' => Shareholder::factory(),
            'request_type' => 'profile_update',
            'payload_old' => ['email' => $this->faker->safeEmail()],
            'payload_new' => ['email' => $this->faker->unique()->safeEmail()],
            'reason' => $this->faker->sentence(),
            'status' => 'submitted',
            'control_no' => 'CR-'.now()->format('Ymd').'-'.$this->faker->unique()->numerify('######'),
            'submitted_by' => null,
            'submitted_at' => now(),
        ];
    }
}
