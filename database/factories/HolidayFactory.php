<?php

namespace Database\Factories;

use App\Enums\HolidayStatus;
use App\Enums\HolidayType;
use App\Models\Company;
use App\Models\Holiday;
use App\Models\HolidayCalendar;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Holiday>
 */
class HolidayFactory extends Factory
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
            'holiday_calendar_id' => fn (array $attributes): int => HolidayCalendar::factory()->create(['company_id' => $attributes['company_id']])->id,
            'date' => fake()->date(),
            'name' => fake()->unique()->words(2, true).' Day',
            'description' => fake()->optional()->sentence(),
            'type' => HolidayType::Public,
            'status' => HolidayStatus::Active,
            'is_optional' => false,
            'created_by' => null,
            'updated_by' => null,
        ];
    }
}
