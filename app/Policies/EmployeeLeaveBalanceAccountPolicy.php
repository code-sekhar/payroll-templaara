<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\EmployeeLeaveBalanceAccount;
use App\Models\User;
use App\Tenancy\ActiveCompany;
use Illuminate\Database\Eloquent\Builder;

class EmployeeLeaveBalanceAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return app(ActiveCompany::class)->current() !== null && $user->can('leave_balance.view');
    }

    public function view(User $user, EmployeeLeaveBalanceAccount $account): bool
    {
        return $this->belongsToActiveCompany($account)
            && $user->can('leave_balance.view')
            && ($user->can('employee.view') || $this->canViewEmployee($user, $account->employee));
    }

    public function create(User $user): bool
    {
        return app(ActiveCompany::class)->current() !== null && $user->can('leave_balance.create');
    }

    public function adjust(User $user, EmployeeLeaveBalanceAccount $account): bool
    {
        return $this->belongsToActiveCompany($account) && $user->can('leave_balance.adjust');
    }

    public function close(User $user, EmployeeLeaveBalanceAccount $account): bool
    {
        return $this->belongsToActiveCompany($account) && $user->can('leave_balance.close');
    }

    private function belongsToActiveCompany(EmployeeLeaveBalanceAccount $account): bool
    {
        return (int) (app(ActiveCompany::class)->current()?->id ?? 0) === (int) $account->company_id;
    }

    private function canViewEmployee(User $user, ?Employee $employee): bool
    {
        if ($employee === null || ! $user->can('employee.view_team')) {
            return false;
        }

        $managerIds = $user->employees()->where('company_id', $employee->company_id)->select('id');

        return Employee::query()->where('company_id', $employee->company_id)->whereKey($employee->id)
            ->where(fn (Builder $query): Builder => $query->whereIn('id', $managerIds)->orWhereIn('manager_id', $managerIds))
            ->exists();
    }
}
