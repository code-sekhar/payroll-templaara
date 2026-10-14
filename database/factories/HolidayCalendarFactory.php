<?php

namespace Database\Factories;

use App\Enums\HolidayCalendarStatus;
use App\Models\Company;
use App\Models\HolidayCalendar;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HolidayCalendar>
 */
class HolidayCalendarFactory extends Factory
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
            'name' => fake()->unique()->words(2, true).' Holidays',
            'year' => (int) now()->format('Y'),
            'description' => fake()->optional()->sentence(),
            'status' => HolidayCalendarStatus::Active,
            'created_by' => null,
            'updated_by' => null,
        ];
    }
}
