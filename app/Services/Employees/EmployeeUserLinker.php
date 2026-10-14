<?php

namespace App\Services\Employees;

use App\Exceptions\Employees\EmployeeIdentityException;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\User;

class EmployeeUserLinker
{
    public function link(Company $company, Employee $employee, User $user): Employee
    {
        if ((int) $employee->company_id !== (int) $company->getKey() || $employee->trashed()) {
            throw new EmployeeIdentityException('EMPLOYEE_COMPANY_MISMATCH');
        }

        $isActiveMember = CompanyUser::query()
            ->where('company_id', $company->getKey())
            ->where('user_id', $user->getKey())
            ->where('status', 'active')
            ->exists();

        if (! $isActiveMember) {
            throw new EmployeeIdentityException('USER_NOT_ACTIVE_COMPANY_MEMBER');
        }

        if ($employee->user_id !== null) {
            if ((int) $employee->user_id !== (int) $user->getKey()) {
                throw new EmployeeIdentityException('EMPLOYEE_ALREADY_LINKED');
            }

            return $employee;
        }

        $userAlreadyLinked = Employee::withTrashed()
            ->where('company_id', $company->getKey())
            ->where('user_id', $user->getKey())
            ->whereKeyNot($employee->getKey())
            ->exists();

        if ($userAlreadyLinked) {
            throw new EmployeeIdentityException('USER_ALREADY_LINKED_IN_COMPANY');
        }

        $employee->user_id = $user->getKey();
        $employee->save();

        return $employee->refresh();
    }
}
