<?php

namespace Database\Factories;

use App\Models\AttendanceWorksite;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceWorksite>
 */
class AttendanceWorksiteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->unique()->company().' Workplace',
            'latitude' => 28.6139,
            'longitude' => 77.2090,
            'radius_meters' => 100,
            'is_active' => true,
        ];
    }
}
