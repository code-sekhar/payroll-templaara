<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\EmployeeLeavePolicyAssignment;
use App\Models\User;
use App\Tenancy\ActiveCompany;
use Illuminate\Database\Eloquent\Builder;

class EmployeeLeavePolicyAssignmentPolicy
{
    public function viewAny(User $user): bool
    {
        return app(ActiveCompany::class)->current() !== null && $user->can('leave_policy_assignment.view');
    }

    public function view(User $user, EmployeeLeavePolicyAssignment $assignment): bool
    {
        if (! $this->belongsToActiveCompany($assignment) || ! $user->can('leave_policy_assignment.view')) {
            return false;
        }

        return $user->can('employee.view') || ($assignment->employee && $this->canViewEmployee($user, $assignment->employee));
    }

    public function create(User $user): bool
    {
        return app(ActiveCompany::class)->current() !== null && $user->can('leave_policy_assignment.create');
    }

    public function update(User $user, EmployeeLeavePolicyAssignment $assignment): bool
    {
        return $this->belongsToActiveCompany($assignment) && $user->can('leave_policy_assignment.update');
    }

    public function delete(User $user, EmployeeLeavePolicyAssignment $assignment): bool
    {
        return $this->belongsToActiveCompany($assignment) && $user->can('leave_policy_assignment.delete');
    }

    public function restore(User $user, EmployeeLeavePolicyAssignment $assignment): bool
    {
        return false;
    }

    public function forceDelete(User $user, EmployeeLeavePolicyAssignment $assignment): bool
    {
        return false;
    }

    private function belongsToActiveCompany(EmployeeLeavePolicyAssignment $assignment): bool
    {
        return (int) (app(ActiveCompany::class)->current()?->id ?? 0) === (int) $assignment->company_id;
    }

    private function canViewEmployee(User $user, Employee $employee): bool
    {
        if (! $user->can('employee.view_team')) {
            return false;
        }

        $managerIds = $user->employees()->where('company_id', $employee->company_id)->select('id');

        return Employee::query()->where('company_id', $employee->company_id)->whereKey($employee->id)
            ->where(fn (Builder $query): Builder => $query->whereIn('id', $managerIds)->orWhereIn('manager_id', $managerIds))
            ->exists();
    }
}
