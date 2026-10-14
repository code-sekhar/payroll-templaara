<?php

namespace App\Policies;

use App\Models\Shift;
use App\Models\User;
use App\Tenancy\ActiveCompany;

class ShiftPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return app(ActiveCompany::class)->current() !== null && $user->can('shift.view');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Shift $shift): bool
    {
        return $this->belongsToActiveCompany($shift) && $user->can('shift.view');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return app(ActiveCompany::class)->current() !== null && $user->can('shift.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Shift $shift): bool
    {
        return $this->belongsToActiveCompany($shift) && $user->can('shift.update');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Shift $shift): bool
    {
        return $this->belongsToActiveCompany($shift)
            && ! $shift->employeeShiftAssignments()->withTrashed()->exists()
            && ! $shift->workScheduleDays()->exists()
            && $user->can('shift.delete');
    }

    public function activate(User $user, Shift $shift): bool
    {
        return $this->belongsToActiveCompany($shift)
            && $shift->status->value === 'inactive'
            && $user->can('shift.activate');
    }

    public function deactivate(User $user, Shift $shift): bool
    {
        return $this->belongsToActiveCompany($shift)
            && $shift->status->value === 'active'
            && $user->can('shift.deactivate');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Shift $shift): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Shift $shift): bool
    {
        return false;
    }

    private function belongsToActiveCompany(Shift $shift): bool
    {
        return app(ActiveCompany::class)->current()?->getKey() === $shift->company_id;
    }
}
