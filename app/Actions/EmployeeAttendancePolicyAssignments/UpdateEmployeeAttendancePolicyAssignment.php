<?php

namespace App\Actions\EmployeeAttendancePolicyAssignments;

use App\Enums\AttendancePolicyStatus;
use App\Models\AttendancePolicy;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeAttendancePolicyAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateEmployeeAttendancePolicyAssignment
{
    public function __construct(private readonly CreateEmployeeAttendancePolicyAssignment $createAssignment) {}

    /** @param array<string, mixed> $attributes */
    public function handle(Company $company, EmployeeAttendancePolicyAssignment $assignment, User $actor, array $attributes): EmployeeAttendancePolicyAssignment
    {
        abort_unless((int) $assignment->company_id === (int) $company->id, 404);

        return DB::transaction(function () use ($company, $assignment, $actor, $attributes): EmployeeAttendancePolicyAssignment {
            $lockedAssignment = $company->employeeAttendancePolicyAssignments()->whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            $employeeIds = array_unique([$lockedAssignment->employee_id, (int) $attributes['employee_id']]);
            sort($employeeIds);
            $employees = Employee::query()->where('company_id', $company->id)->whereIn('id', $employeeIds)
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $employee = $employees->get((int) $attributes['employee_id']);

            if (! $employee) {
                throw ValidationException::withMessages(['employee_id' => 'Select an active employee in this company.']);
            }

            $policy = AttendancePolicy::withTrashed()->where('company_id', $company->id)
                ->whereKey($attributes['attendance_policy_id'])->sharedLock()->first();
            $samePolicy = $policy && (int) $policy->id === (int) $lockedAssignment->attendance_policy_id;

            if (! $policy || (! $samePolicy && ($policy->trashed() || $policy->status !== AttendancePolicyStatus::Active))) {
                throw ValidationException::withMessages(['attendance_policy_id' => 'Select an active attendance policy in this company.']);
            }

            if (($policy->trashed() || $policy->status !== AttendancePolicyStatus::Active) && (
                (int) $attributes['employee_id'] !== (int) $lockedAssignment->employee_id
                || (int) $attributes['attendance_policy_id'] !== (int) $lockedAssignment->attendance_policy_id
                || $attributes['effective_from'] !== $lockedAssignment->effective_from->toDateString()
                || ($attributes['effective_to'] ?? null) !== $lockedAssignment->effective_to?->toDateString()
                || $attributes['status'] !== $lockedAssignment->status->value
            )) {
                throw ValidationException::withMessages(['attendance_policy_id' => 'An inactive or archived policy can only be retained while editing assignment notes.']);
            }

            $this->createAssignment->ensureNoOverlap($employee, $attributes, $lockedAssignment->id);

            $lockedAssignment->update([
                'employee_id' => $employee->id,
                'attendance_policy_id' => $policy->id,
                'effective_from' => $attributes['effective_from'],
                'effective_to' => $attributes['effective_to'] ?? null,
                'status' => $attributes['status'],
                'notes' => $attributes['notes'] ?? null,
                'updated_by' => $actor->id,
            ]);

            return $lockedAssignment->refresh();
        });
    }
}
