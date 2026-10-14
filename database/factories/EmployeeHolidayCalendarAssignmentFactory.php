<?php

namespace Database\Factories;

use App\Enums\EmployeeHolidayCalendarAssignmentStatus;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeHolidayCalendarAssignment;
use App\Models\HolidayCalendar;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeHolidayCalendarAssignment>
 */
class EmployeeHolidayCalendarAssignmentFactory extends Factory
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
            'employee_id' => fn (array $attributes): int => Employee::factory()->create(['company_id' => $attributes['company_id']])->id,
            'holiday_calendar_id' => fn (array $attributes): int => HolidayCalendar::factory()->create(['company_id' => $attributes['company_id']])->id,
            'effective_from' => fake()->date(),
            'effective_to' => null,
            'status' => EmployeeHolidayCalendarAssignmentStatus::Active,
            'notes' => null,
            'created_by' => null,
            'updated_by' => null,
        ];
    }
}
