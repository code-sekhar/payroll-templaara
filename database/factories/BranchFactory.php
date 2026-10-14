<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->unique()->company().' branch',
            'code' => strtoupper(fake()->unique()->bothify('BR-####')),
            'email' => fake()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'city' => fake()->city(),
            'timezone' => 'UTC',
            'status' => 'active',
        ];
    }
}
