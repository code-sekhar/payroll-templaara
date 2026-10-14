<?php

namespace App\Actions\Employees;

use App\Exceptions\Employees\EmployeeIdentityException;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\User;
use App\Services\Employees\EmployeeUserLinker;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

final class LinkEmployeeToUser
{
    public function __construct(private readonly EmployeeUserLinker $linker) {}

    public function execute(Company $company, Employee $employee, User $user, User $actor): Employee
    {
        try {
            return DB::transaction(function () use ($company, $employee, $user, $actor): Employee {
                $lockedCompany = Company::query()->lockForUpdate()->findOrFail($company->getKey());
                $lockedEmployee = Employee::withTrashed()->lockForUpdate()->findOrFail($employee->getKey());
                $lockedUser = User::query()->lockForUpdate()->findOrFail($user->getKey());

                if ($lockedCompany->status !== 'active') {
                    throw new EmployeeIdentityException('COMPANY_INACTIVE');
                }

                if ((int) $lockedEmployee->company_id !== (int) $lockedCompany->getKey() || $lockedEmployee->trashed()) {
                    throw new EmployeeIdentityException('EMPLOYEE_COMPANY_MISMATCH');
                }

                $membership = CompanyUser::query()
                    ->where('company_id', $lockedCompany->getKey())
                    ->where('user_id', $lockedUser->getKey())
                    ->where('status', 'active')
                    ->lockForUpdate()
                    ->first();

                if ($membership === null) {
                    throw new EmployeeIdentityException('USER_NOT_ACTIVE_COMPANY_MEMBER');
                }

                $this->authorizeActor($actor, $lockedCompany);

                return $this->linker->link($lockedCompany, $lockedEmployee, $lockedUser);
            });
        } catch (QueryException $exception) {
            if ($this->isEmployeeIdentityUniqueViolation($exception)) {
                throw new EmployeeIdentityException('USER_ALREADY_LINKED_IN_COMPANY');
            }

            throw $exception;
        }
    }

    private function authorizeActor(User $actor, Company $company): void
    {
        $isSuperAdmin = $actor->isSuperAdmin();
        $hasActiveMembership = CompanyUser::query()
            ->where('company_id', $company->getKey())
            ->where('user_id', $actor->getKey())
            ->where('status', 'active')
            ->exists();

        if (! $isSuperAdmin && ! $hasActiveMembership) {
            throw new EmployeeIdentityException('LINK_ACTOR_NOT_AUTHORIZED');
        }

        $registrar = app(PermissionRegistrar::class);
        $previousTeamId = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($company->getKey());
        $actor->unsetRelation('roles')->unsetRelation('permissions');

        try {
            if (! $isSuperAdmin && ! $actor->can('employee.update')) {
                throw new EmployeeIdentityException('LINK_ACTOR_NOT_AUTHORIZED');
            }
        } finally {
            $registrar->setPermissionsTeamId($previousTeamId);
            $actor->unsetRelation('roles')->unsetRelation('permissions');
        }
    }

    private function isEmployeeIdentityUniqueViolation(QueryException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'employees_company_id_user_id_unique')
            || str_contains($message, 'employees.company_id, employees.user_id');
    }
}
