<?php

namespace App\Policies;

use App\Models\EmployeeHolidayCalendarAssignment;
use App\Models\User;
use App\Tenancy\ActiveCompany;

class EmployeeHolidayCalendarAssignmentPolicy
{
    public function viewAny(User $user): bool
    {
        return app(ActiveCompany::class)->current() !== null && $user->can('holiday_calendar_assignment.view');
    }

    public function view(User $user, EmployeeHolidayCalendarAssignment $assignment): bool
    {
        return $this->belongsToActiveCompany($assignment) && $user->can('holiday_calendar_assignment.view');
    }

    public function create(User $user): bool
    {
        return app(ActiveCompany::class)->current() !== null && $user->can('holiday_calendar_assignment.create');
    }

    public function update(User $user, EmployeeHolidayCalendarAssignment $assignment): bool
    {
        return $this->belongsToActiveCompany($assignment) && $user->can('holiday_calendar_assignment.update');
    }

    public function delete(User $user, EmployeeHolidayCalendarAssignment $assignment): bool
    {
        return $this->belongsToActiveCompany($assignment) && $user->can('holiday_calendar_assignment.delete');
    }

    public function restore(User $user, EmployeeHolidayCalendarAssignment $assignment): bool
    {
        return false;
    }

    public function forceDelete(User $user, EmployeeHolidayCalendarAssignment $assignment): bool
    {
        return false;
    }

    private function belongsToActiveCompany(EmployeeHolidayCalendarAssignment $assignment): bool
    {
        $company = app(ActiveCompany::class)->current();

        return $company !== null && (int) $assignment->company_id === (int) $company->id;
    }
}
