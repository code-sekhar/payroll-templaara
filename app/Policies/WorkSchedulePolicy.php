<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkSchedule;
use App\Tenancy\ActiveCompany;

class WorkSchedulePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return app(ActiveCompany::class)->current() !== null && $user->can('work_schedule.view');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, WorkSchedule $workSchedule): bool
    {
        return $this->belongsToActiveCompany($workSchedule) && $user->can('work_schedule.view');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return app(ActiveCompany::class)->current() !== null && $user->can('work_schedule.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, WorkSchedule $workSchedule): bool
    {
        return $this->belongsToActiveCompany($workSchedule) && $user->can('work_schedule.update');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, WorkSchedule $workSchedule): bool
    {
        return $this->belongsToActiveCompany($workSchedule) && $user->can('work_schedule.delete');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, WorkSchedule $workSchedule): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, WorkSchedule $workSchedule): bool
    {
        return false;
    }

    public function activate(User $user, WorkSchedule $workSchedule): bool
    {
        return $this->belongsToActiveCompany($workSchedule)
            && $workSchedule->status?->value === 'inactive'
            && $user->can('work_schedule.activate');
    }

    public function deactivate(User $user, WorkSchedule $workSchedule): bool
    {
        return $this->belongsToActiveCompany($workSchedule)
            && $workSchedule->status?->value === 'active'
            && $user->can('work_schedule.deactivate');
    }

    private function belongsToActiveCompany(WorkSchedule $workSchedule): bool
    {
        $company = app(ActiveCompany::class)->current();

        return $company !== null && (int) $workSchedule->company_id === (int) $company->id;
    }
}
