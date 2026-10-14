<?php

namespace App\Policies;

use App\Models\AttendancePolicy;
use App\Models\User;
use App\Tenancy\ActiveCompany;

class AttendancePolicyPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return app(ActiveCompany::class)->current() !== null && $user->can('attendance_policy.view');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, AttendancePolicy $attendancePolicy): bool
    {
        return $this->belongsToActiveCompany($attendancePolicy) && $user->can('attendance_policy.view');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return app(ActiveCompany::class)->current() !== null && $user->can('attendance_policy.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, AttendancePolicy $attendancePolicy): bool
    {
        return $this->belongsToActiveCompany($attendancePolicy) && $user->can('attendance_policy.update');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, AttendancePolicy $attendancePolicy): bool
    {
        return $this->belongsToActiveCompany($attendancePolicy) && $user->can('attendance_policy.delete');
    }

    public function activate(User $user, AttendancePolicy $attendancePolicy): bool
    {
        return $this->belongsToActiveCompany($attendancePolicy)
            && $attendancePolicy->status->value === 'inactive'
            && $user->can('attendance_policy.activate');
    }

    public function deactivate(User $user, AttendancePolicy $attendancePolicy): bool
    {
        return $this->belongsToActiveCompany($attendancePolicy)
            && $attendancePolicy->status->value === 'active'
            && $user->can('attendance_policy.deactivate');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, AttendancePolicy $attendancePolicy): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, AttendancePolicy $attendancePolicy): bool
    {
        return false;
    }

    private function belongsToActiveCompany(AttendancePolicy $attendancePolicy): bool
    {
        return app(ActiveCompany::class)->current()?->getKey() === $attendancePolicy->company_id;
    }
}
