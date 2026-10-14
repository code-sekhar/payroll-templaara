<?php

namespace App\Actions\LeavePolicyAssignments;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeLeavePolicyAssignment;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateEmployeeLeavePolicyAssignment
{
    public function __construct(private readonly CreateEmployeeLeavePolicyAssignment $createAssignment) {}

    /** @param array<string, mixed> $attributes */
    public function handle(Company $company, EmployeeLeavePolicyAssignment $assignment, User $actor, array $attributes): EmployeeLeavePolicyAssignment
    {
        abort_unless((int) $assignment->company_id === (int) $company->id, 404);

        return DB::transaction(function () use ($company, $assignment, $actor, $attributes): EmployeeLeavePolicyAssignment {
            $locked = $company->employeeLeavePolicyAssignments()->whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            $employeeIds = array_unique([$locked->employee_id, (int) $attributes['employee_id']]);
            sort($employeeIds);
            $employees = Employee::query()->where('company_id', $company->id)->whereIn('id', $employeeIds)
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $employee = $employees->get((int) $attributes['employee_id']);

            if (! $employee) {
                throw ValidationException::withMessages(['employee_id' => 'Select an active employee in this company.']);
            }

            $policy = LeavePolicy::withTrashed()->where('company_id', $company->id)->whereKey($attributes['leave_policy_id'])->sharedLock()->first();
            $type = $policy ? LeaveType::withTrashed()->where('company_id', $company->id)->whereKey($policy->leave_type_id)->sharedLock()->first() : null;
            $sameConfiguration = $policy && $type && (int) $locked->leave_policy_id === (int) $policy->id && (int) $locked->leave_type_id === (int) $type->id;

            if (! $policy || ! $type || (($policy->trashed() || $type->trashed()) && ! $sameConfiguration)) {
                throw ValidationException::withMessages(['leave_policy_id' => 'Select a valid leave policy in this company.']);
            }

            $archivedConfiguration = $sameConfiguration && ($policy->trashed() || $type->trashed());
            if ($archivedConfiguration && (
                (int) $attributes['employee_id'] !== (int) $locked->employee_id
                || $attributes['effective_from'] !== $locked->effective_from->toDateString()
                || ($attributes['effective_to'] ?? null) !== $locked->effective_to?->toDateString()
                || $attributes['status'] !== $locked->status->value
            )) {
                throw ValidationException::withMessages(['leave_policy_id' => 'Archived leave configuration can only be retained while editing assignment notes.']);
            }

            $retainsExistingStatus = $sameConfiguration && $attributes['status'] === $locked->status->value;
            if (! $retainsExistingStatus) {
                $this->createAssignment->assertAssignableStatuses($policy, $type, $attributes['status']);
            }
            $attributes['leave_type_id'] = $type->id;
            $this->createAssignment->ensureNoOverlap($employee, $attributes, $locked->id);

            $locked->update([
                'employee_id' => $employee->id,
                'leave_type_id' => $type->id,
                'leave_policy_id' => $policy->id,
                'effective_from' => $attributes['effective_from'],
                'effective_to' => $attributes['effective_to'] ?? null,
                'status' => $attributes['status'],
                'notes' => $attributes['notes'] ?? null,
                'updated_by' => $actor->id,
            ]);

            return $locked->refresh();
        });
    }
}
