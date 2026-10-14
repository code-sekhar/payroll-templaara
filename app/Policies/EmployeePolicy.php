<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;
use App\Tenancy\ActiveCompany;

class EmployeePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return app(ActiveCompany::class)->current() !== null && ($user->can('employee.view') || $user->can('employee.view_team'));
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Employee $employee): bool
    {
        if (! (app(ActiveCompany::class)->current()?->is($employee->company) ?? false)) {
            return false;
        }

        if ($user->can('employee.view')) {
            return true;
        }

        if ($employee->user_id === $user->id && $user->can('employee.view_own')) {
            return true;
        }

        return $user->can('employee.view_team') && $user->employees()
            ->where('company_id', $employee->company_id)
            ->whereKey($employee->manager_id)
            ->exists();
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return app(ActiveCompany::class)->current() !== null && $user->can('employee.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Employee $employee): bool
    {
        return (app(ActiveCompany::class)->current()?->is($employee->company) ?? false) && $user->can('employee.update');
    }

    public function inviteLogin(User $user, Employee $employee): bool
    {
        if (! (app(ActiveCompany::class)->current()?->is($employee->company) ?? false)) {
            return false;
        }

        return $user->isSuperAdmin() || $user->can('employee.login.invite');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Employee $employee): bool
    {
        return (app(ActiveCompany::class)->current()?->is($employee->company) ?? false) && $user->can('employee.delete');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Employee $employee): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Employee $employee): bool
    {
        return false;
    }
}
