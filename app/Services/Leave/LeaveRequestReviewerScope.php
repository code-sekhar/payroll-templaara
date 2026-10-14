<?php

namespace App\Services\Leave;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\PermissionRegistrar;

final class LeaveRequestReviewerScope
{
    public function __construct(private readonly PermissionRegistrar $permissionRegistrar) {}

    /**
     * @param  Builder<LeaveRequest>  $query
     * @return Builder<LeaveRequest>
     */
    public function apply(Builder $query, User $reviewer, Company $company): Builder
    {
        $query->where('company_id', $company->getKey());

        if (! $this->hasActiveCompanyAccess($reviewer, $company)) {
            return $query->whereRaw('1 = 0');
        }

        return $this->withCompanyPermissions($reviewer, (int) $company->getKey(), function () use ($query, $reviewer, $company): Builder {
            if ($reviewer->can('employee.view')) {
                return $query;
            }

            if (! $reviewer->can('employee.view_team')) {
                return $query->whereRaw('1 = 0');
            }

            $managerIds = $reviewer->employees()->where('company_id', $company->getKey())->select('id');

            return $query->whereHas('employee', fn (Builder $employees): Builder => $employees
                ->where('company_id', $company->getKey())
                ->where(fn (Builder $scope): Builder => $scope->whereIn('id', $managerIds)->orWhereIn('manager_id', $managerIds)));
        });
    }

    public function allowsRequest(User $reviewer, LeaveRequest $request, Company $company): bool
    {
        if ((int) $request->company_id !== (int) $company->getKey() || ! $this->hasActiveCompanyAccess($reviewer, $company)) {
            return false;
        }

        return $this->withCompanyPermissions($reviewer, (int) $company->getKey(), function () use ($reviewer, $request, $company): bool {
            return $reviewer->can('leave_request.view')
                && $this->apply(LeaveRequest::query(), $reviewer, $company)->whereKey($request->getKey())->exists();
        });
    }

    public function allowsDecision(User $reviewer, LeaveRequest $request, Company $company, Employee $employee, string $permission): bool
    {
        if (! in_array($permission, ['leave_request.approve', 'leave_request.reject'], true)
            || (int) $request->company_id !== (int) $company->getKey()
            || (int) $employee->company_id !== (int) $company->getKey()
            || (int) $employee->getKey() !== (int) $request->employee_id
            || (int) $request->requested_by === (int) $reviewer->getKey()
            || (int) $employee->user_id === (int) $reviewer->getKey()
            || ! $this->hasActiveCompanyAccess($reviewer, $company)) {
            return false;
        }

        return $this->withCompanyPermissions($reviewer, (int) $company->getKey(), function () use ($reviewer, $request, $company, $permission): bool {
            if (! $reviewer->can('leave_request.view') || ! $reviewer->can($permission)) {
                return false;
            }

            return $this->apply(LeaveRequest::query(), $reviewer, $company)->whereKey($request->getKey())->exists();
        });
    }

    public function canViewCompanyRequests(User $reviewer, Company $company): bool
    {
        return $this->hasActiveCompanyAccess($reviewer, $company)
            && $this->withCompanyPermissions($reviewer, (int) $company->getKey(), fn (): bool => $reviewer->can('employee.view'));
    }

    private function hasActiveCompanyAccess(User $reviewer, Company $company): bool
    {
        if (! Company::query()->whereKey($company->getKey())->where('status', 'active')->exists()) {
            return false;
        }

        return $this->withCompanyPermissions($reviewer, (int) $company->getKey(), function () use ($reviewer, $company): bool {
            return $reviewer->isSuperAdmin()
                || CompanyUser::query()->where('company_id', $company->getKey())
                    ->where('user_id', $reviewer->getKey())->where('status', 'active')->sharedLock()->exists();
        });
    }

    private function withCompanyPermissions(User $reviewer, int $companyId, callable $callback): mixed
    {
        $previousTeamId = $this->permissionRegistrar->getPermissionsTeamId();
        $this->permissionRegistrar->setPermissionsTeamId($companyId);

        try {
            $reviewer->unsetRelation('roles')->unsetRelation('permissions');

            return $callback();
        } finally {
            $this->permissionRegistrar->setPermissionsTeamId($previousTeamId);

            $reviewer->unsetRelation('roles')->unsetRelation('permissions');
        }
    }
}
