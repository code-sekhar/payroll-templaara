<?php

namespace App\Actions\EmployeeAttendancePolicyAssignments;

use App\Enums\AttendancePolicyStatus;
use App\Enums\EmployeeAttendancePolicyAssignmentStatus;
use App\Models\AttendancePolicy;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeAttendancePolicyAssignment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateEmployeeAttendancePolicyAssignment
{
    /** @param array<string, mixed> $attributes */
    public function handle(Company $company, User $actor, array $attributes): EmployeeAttendancePolicyAssignment
    {
        return DB::transaction(function () use ($company, $actor, $attributes): EmployeeAttendancePolicyAssignment {
            $employee = Employee::query()->where('company_id', $company->id)
                ->whereKey($attributes['employee_id'])->lockForUpdate()->first();

            if (! $employee) {
                throw ValidationException::withMessages(['employee_id' => 'Select an active employee in this company.']);
            }

            $policy = AttendancePolicy::query()->where('company_id', $company->id)
                ->whereKey($attributes['attendance_policy_id'])->sharedLock()->first();

            if (! $policy || $policy->status !== AttendancePolicyStatus::Active) {
                throw ValidationException::withMessages(['attendance_policy_id' => 'Select an active attendance policy in this company.']);
            }

            $this->ensureNoOverlap($employee, $attributes);

            return $company->employeeAttendancePolicyAssignments()->create([
                'employee_id' => $employee->id,
                'attendance_policy_id' => $policy->id,
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
        $effectiveFrom = CarbonImmutable::parse($attributes['effective_from'])->toDateString();
        $effectiveTo = filled($attributes['effective_to'] ?? null)
            ? CarbonImmutable::parse($attributes['effective_to'])->toDateString()
            : null;

        if ($effectiveTo !== null && $effectiveTo < $effectiveFrom) {
            throw ValidationException::withMessages(['effective_to' => 'The end date cannot be earlier than the start date.']);
        }

        if ($attributes['status'] !== EmployeeAttendancePolicyAssignmentStatus::Active->value) {
            return;
        }

        $overlaps = $employee->attendancePolicyAssignments()
            ->where('status', EmployeeAttendancePolicyAssignmentStatus::Active->value)
            ->overlappingPeriod($effectiveFrom, $effectiveTo)
            ->when($exceptAssignmentId !== null, fn ($query) => $query->where('id', '!=', $exceptAssignmentId))
            ->exists();

        if ($overlaps) {
            throw ValidationException::withMessages([
                'effective_from' => 'This employee already has an active attendance policy assignment during the selected period. End the current assignment before creating another overlapping period.',
            ]);
        }
    }
}
