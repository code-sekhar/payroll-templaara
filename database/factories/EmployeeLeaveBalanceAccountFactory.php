<?php

namespace Database\Factories;

use App\Enums\LeaveBalanceAccountStatus;
use App\Models\Company;
use App\Models\EmployeeLeaveBalanceAccount;
use App\Models\EmployeeLeavePolicyAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeLeaveBalanceAccount>
 */
class EmployeeLeaveBalanceAccountFactory extends Factory
{
    protected $model = EmployeeLeaveBalanceAccount::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'employee_leave_policy_assignment_id' => fn (array $attributes): int => EmployeeLeavePolicyAssignment::factory()->create([
                'company_id' => $attributes['company_id'],
                'effective_from' => '2026-01-01',
                'effective_to' => null,
            ])->id,
            'employee_id' => fn (array $attributes): int => EmployeeLeavePolicyAssignment::findOrFail($attributes['employee_leave_policy_assignment_id'])->employee_id,
            'leave_type_id' => fn (array $attributes): int => EmployeeLeavePolicyAssignment::findOrFail($attributes['employee_leave_policy_assignment_id'])->leave_type_id,
            'leave_policy_id' => fn (array $attributes): int => EmployeeLeavePolicyAssignment::findOrFail($attributes['employee_leave_policy_assignment_id'])->leave_policy_id,
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
            'status' => LeaveBalanceAccountStatus::Open,
            'created_by' => null,
            'updated_by' => null,
        ];
    }

    public function open(): static
    {
        return $this->state(fn (): array => ['status' => LeaveBalanceAccountStatus::Open]);
    }

    public function closed(): static
    {
        return $this->state(fn (): array => ['status' => LeaveBalanceAccountStatus::Closed]);
    }
}
