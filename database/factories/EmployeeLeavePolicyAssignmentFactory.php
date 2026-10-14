<?php

namespace Database\Factories;

use App\Enums\EmployeeLeavePolicyAssignmentStatus;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeLeavePolicyAssignment;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeLeavePolicyAssignment>
 */
class EmployeeLeavePolicyAssignmentFactory extends Factory
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
            'leave_type_id' => fn (array $attributes): int => LeaveType::factory()->create(['company_id' => $attributes['company_id']])->id,
            'leave_policy_id' => fn (array $attributes): int => LeavePolicy::factory()->create([
                'company_id' => $attributes['company_id'], 'leave_type_id' => $attributes['leave_type_id'],
            ])->id,
            'effective_from' => fake()->date(),
            'effective_to' => null,
            'status' => EmployeeLeavePolicyAssignmentStatus::Active,
            'notes' => null,
            'created_by' => null,
            'updated_by' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (): array => ['status' => EmployeeLeavePolicyAssignmentStatus::Active]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['status' => EmployeeLeavePolicyAssignmentStatus::Inactive]);
    }

    public function openEnded(): static
    {
        return $this->state(fn (): array => ['effective_to' => null]);
    }
}
