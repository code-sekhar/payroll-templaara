<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Shift;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shift>
 */
class ShiftFactory extends Factory
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
            'name' => fake()->unique()->words(2, true).' shift',
            'code' => strtoupper(fake()->unique()->bothify('SH-####')),
            'start_time' => '09:00',
            'end_time' => '18:00',
            'break_duration_minutes' => 0,
            'grace_period_minutes' => 0,
            'is_overnight' => false,
            'status' => 'active',
        ];
    }
}
