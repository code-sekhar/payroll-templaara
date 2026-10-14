<?php

namespace App\Policies;

use App\Models\LeaveType;
use App\Models\User;
use App\Tenancy\ActiveCompany;

class LeaveTypePolicy
{
    public function viewAny(User $user): bool
    {
        return app(ActiveCompany::class)->current() !== null && $user->can('leave_type.view');
    }

    public function view(User $user, LeaveType $leaveType): bool
    {
        return $this->belongsToActiveCompany($leaveType) && $user->can('leave_type.view');
    }

    public function create(User $user): bool
    {
        return app(ActiveCompany::class)->current() !== null && $user->can('leave_type.create');
    }

    public function update(User $user, LeaveType $leaveType): bool
    {
        return $this->belongsToActiveCompany($leaveType) && $user->can('leave_type.update');
    }

    public function delete(User $user, LeaveType $leaveType): bool
    {
        return $this->belongsToActiveCompany($leaveType) && $user->can('leave_type.delete');
    }

    public function activate(User $user, LeaveType $leaveType): bool
    {
        return $this->belongsToActiveCompany($leaveType)
            && $leaveType->status?->value === 'inactive'
            && $user->can('leave_type.activate');
    }

    public function deactivate(User $user, LeaveType $leaveType): bool
    {
        return $this->belongsToActiveCompany($leaveType)
            && $leaveType->status?->value === 'active'
            && $user->can('leave_type.deactivate');
    }

    public function restore(User $user, LeaveType $leaveType): bool
    {
        return false;
    }

    public function forceDelete(User $user, LeaveType $leaveType): bool
    {
        return false;
    }

    private function belongsToActiveCompany(LeaveType $leaveType): bool
    {
        $company = app(ActiveCompany::class)->current();

        return $company !== null && (int) $leaveType->company_id === (int) $company->id;
    }
}
