<?php

namespace App\Policies;

use App\Models\LeavePolicy;
use App\Models\User;
use App\Tenancy\ActiveCompany;

class LeavePolicyPolicy
{
    public function viewAny(User $user): bool
    {
        return app(ActiveCompany::class)->current() !== null && $user->can('leave_policy.view');
    }

    public function view(User $user, LeavePolicy $leavePolicy): bool
    {
        return $this->belongsToActiveCompany($leavePolicy) && $user->can('leave_policy.view');
    }

    public function create(User $user): bool
    {
        return app(ActiveCompany::class)->current() !== null && $user->can('leave_policy.create');
    }

    public function update(User $user, LeavePolicy $leavePolicy): bool
    {
        return $this->belongsToActiveCompany($leavePolicy) && $user->can('leave_policy.update');
    }

    public function delete(User $user, LeavePolicy $leavePolicy): bool
    {
        return $this->belongsToActiveCompany($leavePolicy) && $user->can('leave_policy.delete');
    }

    public function activate(User $user, LeavePolicy $leavePolicy): bool
    {
        return $this->belongsToActiveCompany($leavePolicy)
            && $leavePolicy->status?->value === 'inactive'
            && $user->can('leave_policy.activate');
    }

    public function deactivate(User $user, LeavePolicy $leavePolicy): bool
    {
        return $this->belongsToActiveCompany($leavePolicy)
            && $leavePolicy->status?->value === 'active'
            && $user->can('leave_policy.deactivate');
    }

    public function restore(User $user, LeavePolicy $leavePolicy): bool
    {
        return false;
    }

    public function forceDelete(User $user, LeavePolicy $leavePolicy): bool
    {
        return false;
    }

    private function belongsToActiveCompany(LeavePolicy $leavePolicy): bool
    {
        $company = app(ActiveCompany::class)->current();

        return $company !== null && (int) $leavePolicy->company_id === (int) $company->id;
    }
}
