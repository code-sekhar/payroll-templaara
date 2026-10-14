<?php

namespace App\Services\Employees;

use App\Exceptions\Employees\EmployeeIdentityException;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Tenancy\ActiveCompany;
use Illuminate\Http\Request;

final class EmployeePortalAccess
{
    public function __construct(
        private readonly EmployeeIdentityResolver $identityResolver,
        private readonly ActiveCompany $activeCompany,
    ) {}

    public function employeeFor(User $user, Company $company): ?Employee
    {
        if (! $user->hasRole('employee')) {
            return null;
        }

        try {
            return $this->identityResolver->resolve($user, $company);
        } catch (EmployeeIdentityException) {
            return null;
        }
    }

    public function isEmployeeOnly(User $user, Company $company): bool
    {
        return $this->hasOnlyEmployeeRole($user, $company)
            && $this->employeeFor($user, $company) !== null;
    }

    public function hasOnlyEmployeeRole(User $user, Company $company): bool
    {
        return ! $user->isSuperAdmin() && $user->getRoleNames()->all() === ['employee'];
    }

    public function canAccessManagement(User $user): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->getRoleNames()->contains(fn (string $role): bool => $role !== 'employee');
    }

    public function employeeOnlyLoginDestination(Request $request, User $user): ?string
    {
        /** @var Company|null $company */
        $company = $this->activeCompany->resolve($request);

        if ($company === null || ! $this->hasOnlyEmployeeRole($user, $company)) {
            return null;
        }

        return $this->employeeFor($user, $company) !== null
            ? route('employee.portal', absolute: false)
            : route('dashboard', absolute: false);
    }
}
