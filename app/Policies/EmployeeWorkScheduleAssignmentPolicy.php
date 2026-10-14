<?php

namespace App\Policies;

use App\Models\EmployeeWorkScheduleAssignment;
use App\Models\User;
use App\Tenancy\ActiveCompany;

class EmployeeWorkScheduleAssignmentPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return app(ActiveCompany::class)->current() !== null && $user->can('work_schedule_assignment.view');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, EmployeeWorkScheduleAssignment $employeeWorkScheduleAssignment): bool
    {
        return $this->belongsToActiveCompany($employeeWorkScheduleAssignment) && $user->can('work_schedule_assignment.view');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return app(ActiveCompany::class)->current() !== null && $user->can('work_schedule_assignment.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, EmployeeWorkScheduleAssignment $employeeWorkScheduleAssignment): bool
    {
        return $this->belongsToActiveCompany($employeeWorkScheduleAssignment) && $user->can('work_schedule_assignment.update');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, EmployeeWorkScheduleAssignment $employeeWorkScheduleAssignment): bool
    {
        return $this->belongsToActiveCompany($employeeWorkScheduleAssignment) && $user->can('work_schedule_assignment.delete');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, EmployeeWorkScheduleAssignment $employeeWorkScheduleAssignment): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, EmployeeWorkScheduleAssignment $employeeWorkScheduleAssignment): bool
    {
        return false;
    }

    private function belongsToActiveCompany(EmployeeWorkScheduleAssignment $assignment): bool
    {
        $company = app(ActiveCompany::class)->current();

        return $company !== null && (int) $assignment->company_id === (int) $company->id;
    }
}
