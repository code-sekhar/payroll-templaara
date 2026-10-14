<?php

namespace App\Policies;

use App\Models\Department;
use App\Models\User;
use App\Tenancy\ActiveCompany;

class DepartmentPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return app(ActiveCompany::class)->current() !== null && $user->can('department.view');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Department $department): bool
    {
        return (app(ActiveCompany::class)->current()?->is($department->company) ?? false) && $user->can('department.view');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return app(ActiveCompany::class)->current() !== null && $user->can('department.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Department $department): bool
    {
        return (app(ActiveCompany::class)->current()?->is($department->company) ?? false) && $user->can('department.update');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Department $department): bool
    {
        return (app(ActiveCompany::class)->current()?->is($department->company) ?? false) && $user->can('department.delete');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Department $department): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Department $department): bool
    {
        return false;
    }
}
