<?php

namespace Database\Factories;

use App\Enums\WorkScheduleDayType;
use App\Enums\WorkScheduleStatus;
use App\Models\Company;
use App\Models\WorkSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkSchedule>
 */
class WorkScheduleFactory extends Factory
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
            'name' => fake()->unique()->words(2, true).' schedule',
            'code' => strtoupper(fake()->unique()->bothify('SCHEDULE-####')),
            'description' => fake()->optional()->sentence(),
            'status' => WorkScheduleStatus::Active,
            'created_by' => null,
            'updated_by' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (WorkSchedule $schedule): void {
            foreach (range(1, 7) as $dayOfWeek) {
                $schedule->days()->create([
                    'company_id' => $schedule->company_id,
                    'day_of_week' => $dayOfWeek,
                    'day_type' => WorkScheduleDayType::Off,
                    'shift_id' => null,
                ]);
            }
        });
    }
}
