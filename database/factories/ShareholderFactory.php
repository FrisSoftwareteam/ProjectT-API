<?php

namespace Database\Factories;

use App\Models\Shareholder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Shareholder>
 */
class ShareholderFactory extends Factory
{
    protected $model = Shareholder::class;

    public function definition(): array
    {
        $holderType = $this->faker->randomElement(['individual', 'corporate']);
        $fullName = $holderType === 'corporate'
            ? $this->faker->company()
            : $this->faker->firstName() . ' ' . $this->faker->lastName();

        return [
            'account_no' => str_pad((string) $this->faker->unique()->numberBetween(1, 9999999999), 10, '0', STR_PAD_LEFT),
            'holder_type' => $holderType,
            'full_name' => $fullName,
            'email' => $this->faker->unique()->safeEmail(),
            'phone' => $this->faker->unique()->numerify('080########'),
            'date_of_birth' => $holderType === 'individual'
                ? $this->faker->dateTimeBetween('-75 years', '-18 years')->format('Y-m-d')
                : null,
            'sex' => $holderType === 'individual'
                ? $this->faker->randomElement(['male', 'female', 'other'])
                : null,
            'rc_number' => $holderType === 'corporate'
                ? 'RC' . $this->faker->unique()->numerify('######')
                : null,
            'nin' => $holderType === 'individual' ? $this->faker->unique()->numerify('###########') : null,
            'bvn' => $holderType === 'individual' ? $this->faker->unique()->numerify('###########') : null,
            'tax_id' => $holderType === 'corporate' ? 'TAX' . $this->faker->unique()->numerify('######') : null,
            'next_of_kin_name' => $holderType === 'individual' ? $this->faker->name() : null,
            'next_of_kin_phone' => $holderType === 'individual' ? $this->faker->numerify('080########') : null,
            'next_of_kin_relationship' => $holderType === 'individual'
                ? $this->faker->randomElement(['spouse', 'parent', 'child', 'sibling', 'relative'])
                : null,
            'status' => $this->faker->randomElement(['active', 'dormant', 'deceased', 'closed']),
        ];
    }
}
