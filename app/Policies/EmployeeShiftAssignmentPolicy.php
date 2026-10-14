<?php

namespace App\Policies;

use App\Models\EmployeeShiftAssignment;
use App\Models\User;
use App\Tenancy\ActiveCompany;

class EmployeeShiftAssignmentPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return app(ActiveCompany::class)->current() !== null && $user->can('shift_assignment.view');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, EmployeeShiftAssignment $assignment): bool
    {
        return $this->belongsToActiveCompany($assignment) && $user->can('shift_assignment.view');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return app(ActiveCompany::class)->current() !== null && $user->can('shift_assignment.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, EmployeeShiftAssignment $assignment): bool
    {
        return $this->belongsToActiveCompany($assignment) && $user->can('shift_assignment.update');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, EmployeeShiftAssignment $assignment): bool
    {
        return $this->belongsToActiveCompany($assignment) && $user->can('shift_assignment.delete');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, EmployeeShiftAssignment $employeeShiftAssignment): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, EmployeeShiftAssignment $employeeShiftAssignment): bool
    {
        return false;
    }

    private function belongsToActiveCompany(EmployeeShiftAssignment $assignment): bool
    {
        $company = app(ActiveCompany::class)->current();

        return $company !== null
            && (int) $assignment->company_id === (int) $company->id;
    }
}
