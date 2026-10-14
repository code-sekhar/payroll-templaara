<?php

namespace App\Services\Employees;

use App\Exceptions\Employees\EmployeeIdentityException;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\User;

final class EmployeeIdentityResolver
{
    public function resolve(User $user, Company $company): Employee
    {
        if ($company->status !== 'active') {
            throw new EmployeeIdentityException('COMPANY_INACTIVE');
        }

        $isActiveMember = CompanyUser::query()
            ->where('company_id', $company->getKey())
            ->where('user_id', $user->getKey())
            ->where('status', 'active')
            ->exists();

        if (! $isActiveMember) {
            throw new EmployeeIdentityException('USER_NOT_ACTIVE_COMPANY_MEMBER');
        }

        $employees = Employee::withTrashed()
            ->where('company_id', $company->getKey())
            ->where('user_id', $user->getKey())
            ->limit(2)
            ->get();

        if ($employees->count() > 1) {
            throw new EmployeeIdentityException('EMPLOYEE_IDENTITY_AMBIGUOUS');
        }

        $employee = $employees->first();

        if ($employee === null || $employee->trashed()) {
            throw new EmployeeIdentityException('EMPLOYEE_IDENTITY_NOT_LINKED');
        }

        return $employee;
    }
}
