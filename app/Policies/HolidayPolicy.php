<?php

namespace App\Policies;

use App\Enums\HolidayCalendarStatus;
use App\Models\Holiday;
use App\Models\HolidayCalendar;
use App\Models\User;
use App\Tenancy\ActiveCompany;

class HolidayPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return app(ActiveCompany::class)->current() !== null && $user->can('holiday.view');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Holiday $holiday): bool
    {
        return $this->belongsToActiveCompany($holiday) && $user->can('holiday.view');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user, HolidayCalendar $holidayCalendar): bool
    {
        $company = app(ActiveCompany::class)->current();

        return $company !== null
            && (int) $holidayCalendar->company_id === (int) $company->id
            && $holidayCalendar->status === HolidayCalendarStatus::Active
            && $user->can('holiday.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Holiday $holiday): bool
    {
        return $this->belongsToActiveCompany($holiday) && $user->can('holiday.update');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Holiday $holiday): bool
    {
        return $this->belongsToActiveCompany($holiday) && $user->can('holiday.delete');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Holiday $holiday): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Holiday $holiday): bool
    {
        return false;
    }

    private function belongsToActiveCompany(Holiday $holiday): bool
    {
        $company = app(ActiveCompany::class)->current();

        return $company !== null && (int) $holiday->company_id === (int) $company->id;
    }
}
