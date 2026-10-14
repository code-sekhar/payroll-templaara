<?php

namespace Database\Factories;

use App\Enums\EmployeeWorkScheduleAssignmentStatus;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeWorkScheduleAssignment;
use App\Models\WorkSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeWorkScheduleAssignment>
 */
class EmployeeWorkScheduleAssignmentFactory extends Factory
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
            'work_schedule_id' => fn (array $attributes): int => WorkSchedule::factory()->create(['company_id' => $attributes['company_id']])->id,
            'effective_from' => fake()->date(),
            'effective_to' => null,
            'status' => EmployeeWorkScheduleAssignmentStatus::Active,
            'notes' => null,
            'created_by' => null,
            'updated_by' => null,
        ];
    }
}
