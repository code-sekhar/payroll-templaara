<?php

namespace App\Policies;

use App\Models\Branch;
use App\Models\User;
use App\Tenancy\ActiveCompany;

class BranchPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return app(ActiveCompany::class)->current() !== null && $user->can('branch.view');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Branch $branch): bool
    {
        return (app(ActiveCompany::class)->current()?->is($branch->company) ?? false) && $user->can('branch.view');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return app(ActiveCompany::class)->current() !== null && $user->can('branch.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Branch $branch): bool
    {
        return (app(ActiveCompany::class)->current()?->is($branch->company) ?? false) && $user->can('branch.update');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Branch $branch): bool
    {
        return (app(ActiveCompany::class)->current()?->is($branch->company) ?? false) && $user->can('branch.delete');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Branch $branch): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Branch $branch): bool
    {
        return false;
    }
}
