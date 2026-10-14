<?php

namespace Database\Factories;

use App\Enums\EmployeeAttendancePolicyAssignmentStatus;
use App\Models\AttendancePolicy;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeAttendancePolicyAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeAttendancePolicyAssignment>
 */
class EmployeeAttendancePolicyAssignmentFactory extends Factory
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
            'attendance_policy_id' => fn (array $attributes): int => AttendancePolicy::factory()->create(['company_id' => $attributes['company_id']])->id,
            'effective_from' => fake()->date(),
            'effective_to' => null,
            'status' => EmployeeAttendancePolicyAssignmentStatus::Active,
            'notes' => null,
            'created_by' => null,
            'updated_by' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (): array => ['status' => EmployeeAttendancePolicyAssignmentStatus::Active]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['status' => EmployeeAttendancePolicyAssignmentStatus::Inactive]);
    }

    public function openEnded(): static
    {
        return $this->state(fn (): array => ['effective_to' => null]);
    }
}
