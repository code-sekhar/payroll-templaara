<?php

namespace App\Policies;

use App\Models\EmployeeAttendancePolicyAssignment;
use App\Models\User;
use App\Tenancy\ActiveCompany;

class EmployeeAttendancePolicyAssignmentPolicy
{
    public function viewAny(User $user): bool
    {
        return app(ActiveCompany::class)->current() !== null && $user->can('attendance_policy_assignment.view');
    }

    public function view(User $user, EmployeeAttendancePolicyAssignment $assignment): bool
    {
        return $this->belongsToActiveCompany($assignment) && $user->can('attendance_policy_assignment.view');
    }

    public function create(User $user): bool
    {
        return app(ActiveCompany::class)->current() !== null && $user->can('attendance_policy_assignment.create');
    }

    public function update(User $user, EmployeeAttendancePolicyAssignment $assignment): bool
    {
        return $this->belongsToActiveCompany($assignment) && $user->can('attendance_policy_assignment.update');
    }

    public function delete(User $user, EmployeeAttendancePolicyAssignment $assignment): bool
    {
        return $this->belongsToActiveCompany($assignment) && $user->can('attendance_policy_assignment.delete');
    }

    public function restore(User $user, EmployeeAttendancePolicyAssignment $assignment): bool
    {
        return false;
    }

    public function forceDelete(User $user, EmployeeAttendancePolicyAssignment $assignment): bool
    {
        return false;
    }

    private function belongsToActiveCompany(EmployeeAttendancePolicyAssignment $assignment): bool
    {
        $company = app(ActiveCompany::class)->current();

        return $company !== null && (int) $assignment->company_id === (int) $company->id;
    }
}
