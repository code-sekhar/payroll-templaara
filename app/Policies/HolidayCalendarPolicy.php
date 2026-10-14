<?php

namespace App\Policies;

use App\Enums\HolidayCalendarStatus;
use App\Models\HolidayCalendar;
use App\Models\User;
use App\Tenancy\ActiveCompany;

class HolidayCalendarPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return app(ActiveCompany::class)->current() !== null && $user->can('holiday_calendar.view');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, HolidayCalendar $holidayCalendar): bool
    {
        return $this->belongsToActiveCompany($holidayCalendar) && $user->can('holiday_calendar.view');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return app(ActiveCompany::class)->current() !== null && $user->can('holiday_calendar.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, HolidayCalendar $holidayCalendar): bool
    {
        return $this->belongsToActiveCompany($holidayCalendar) && $user->can('holiday_calendar.update');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, HolidayCalendar $holidayCalendar): bool
    {
        return $this->belongsToActiveCompany($holidayCalendar) && $user->can('holiday_calendar.delete');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, HolidayCalendar $holidayCalendar): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, HolidayCalendar $holidayCalendar): bool
    {
        return false;
    }

    public function activate(User $user, HolidayCalendar $holidayCalendar): bool
    {
        return $this->belongsToActiveCompany($holidayCalendar)
            && $holidayCalendar->status === HolidayCalendarStatus::Inactive
            && $user->can('holiday_calendar.activate');
    }

    public function deactivate(User $user, HolidayCalendar $holidayCalendar): bool
    {
        return $this->belongsToActiveCompany($holidayCalendar)
            && $holidayCalendar->status === HolidayCalendarStatus::Active
            && $user->can('holiday_calendar.deactivate');
    }

    private function belongsToActiveCompany(HolidayCalendar $holidayCalendar): bool
    {
        $company = app(ActiveCompany::class)->current();

        return $company !== null && (int) $holidayCalendar->company_id === (int) $company->id;
    }
}
