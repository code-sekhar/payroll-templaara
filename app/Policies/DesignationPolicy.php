<?php

namespace App\Policies;

use App\Models\Designation;
use App\Models\User;
use App\Tenancy\ActiveCompany;

class DesignationPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return app(ActiveCompany::class)->current() !== null && $user->can('designation.view');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Designation $designation): bool
    {
        return (app(ActiveCompany::class)->current()?->is($designation->company) ?? false) && $user->can('designation.view');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return app(ActiveCompany::class)->current() !== null && $user->can('designation.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Designation $designation): bool
    {
        return (app(ActiveCompany::class)->current()?->is($designation->company) ?? false) && $user->can('designation.update');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Designation $designation): bool
    {
        return (app(ActiveCompany::class)->current()?->is($designation->company) ?? false) && $user->can('designation.delete');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Designation $designation): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Designation $designation): bool
    {
        return false;
    }
}
