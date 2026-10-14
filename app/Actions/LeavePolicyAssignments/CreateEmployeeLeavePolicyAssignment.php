<?php

namespace App\Actions\LeavePolicyAssignments;

use App\Enums\EmployeeLeavePolicyAssignmentStatus;
use App\Enums\LeavePolicyStatus;
use App\Enums\LeaveTypeStatus;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeLeavePolicyAssignment;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateEmployeeLeavePolicyAssignment
{
    /** @param array<string, mixed> $attributes */
    public function handle(Company $company, User $actor, array $attributes): EmployeeLeavePolicyAssignment
    {
        return DB::transaction(function () use ($company, $actor, $attributes): EmployeeLeavePolicyAssignment {
            $employee = Employee::query()->where('company_id', $company->id)->whereKey($attributes['employee_id'])
                ->lockForUpdate()->first();

            if (! $employee) {
                throw ValidationException::withMessages(['employee_id' => 'Select an active employee in this company.']);
            }

            $policy = LeavePolicy::query()->where('company_id', $company->id)->whereKey($attributes['leave_policy_id'])
                ->sharedLock()->first();
            $type = $policy ? LeaveType::query()->where('company_id', $company->id)->whereKey($policy->leave_type_id)->sharedLock()->first() : null;

            if (! $policy || ! $type) {
                throw ValidationException::withMessages(['leave_policy_id' => 'Select a valid leave policy in this company.']);
            }

            $this->assertAssignableStatuses($policy, $type, $attributes['status']);
            $attributes['leave_type_id'] = $type->id;
            $this->ensureNoOverlap($employee, $attributes);

            return $company->employeeLeavePolicyAssignments()->create([
                'employee_id' => $employee->id,
                'leave_type_id' => $type->id,
                'leave_policy_id' => $policy->id,
                'effective_from' => $attributes['effective_from'],
                'effective_to' => $attributes['effective_to'] ?? null,
                'status' => $attributes['status'],
                'notes' => $attributes['notes'] ?? null,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
        });
    }

    /** @param array<string, mixed> $attributes */
    public function ensureNoOverlap(Employee $employee, array $attributes, ?int $exceptAssignmentId = null): void
    {
        $from = CarbonImmutable::parse($attributes['effective_from'])->toDateString();
        $to = filled($attributes['effective_to'] ?? null) ? CarbonImmutable::parse($attributes['effective_to'])->toDateString() : null;

        if ($to !== null && $to < $from) {
            throw ValidationException::withMessages(['effective_to' => 'The end date cannot be earlier than the start date.']);
        }

        if ($attributes['status'] !== EmployeeLeavePolicyAssignmentStatus::Active->value) {
            return;
        }

        $overlaps = $employee->leavePolicyAssignments()->where('leave_type_id', $attributes['leave_type_id'])
            ->where('status', EmployeeLeavePolicyAssignmentStatus::Active->value)
            ->overlappingPeriod($from, $to)
            ->when($exceptAssignmentId !== null, fn ($query) => $query->where('id', '!=', $exceptAssignmentId))
            ->exists();

        if ($overlaps) {
            throw ValidationException::withMessages([
                'effective_from' => 'This employee already has an active policy for this leave type during the selected period.',
            ]);
        }
    }

    public function assertAssignableStatuses(LeavePolicy $policy, LeaveType $type, string $status): void
    {
        if ($status === EmployeeLeavePolicyAssignmentStatus::Active->value
            && ($policy->status !== LeavePolicyStatus::Active || $type->status !== LeaveTypeStatus::Active)) {
            throw ValidationException::withMessages([
                'leave_policy_id' => 'An active assignment requires an active leave policy and active leave type.',
            ]);
        }
    }
}
