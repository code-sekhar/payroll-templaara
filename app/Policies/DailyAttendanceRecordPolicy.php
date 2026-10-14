<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\DailyAttendanceRecord;
use App\Models\Employee;
use App\Models\User;
use App\Tenancy\ActiveCompany;
use Illuminate\Database\Eloquent\Builder;

class DailyAttendanceRecordPolicy
{
    public function viewAny(User $user, Company $company): bool
    {
        return $this->isActiveCompany($company)
            && ($user->can('attendance.view') || $user->can('attendance.view_team'));
    }

    public function view(User $user, DailyAttendanceRecord $record): bool
    {
        if (! $this->belongsToActiveCompany($record) || ! $user->can('attendance.view_details')) {
            return false;
        }

        return $user->can('employee.view') || $this->visibleToManager($user, $record->employee);
    }

    public function viewOwnAny(User $user, Company $company): bool
    {
        return $this->isActiveCompany($company) && $user->can('attendance.self_view');
    }

    public function viewOwn(User $user, DailyAttendanceRecord $record): bool
    {
        return $this->belongsToActiveCompany($record)
            && $user->can('attendance.self_view')
            && (int) $record->employee?->user_id === (int) $user->getKey();
    }

    private function isActiveCompany(Company $company): bool
    {
        return app(ActiveCompany::class)->current()?->is($company) ?? false;
    }

    private function belongsToActiveCompany(DailyAttendanceRecord $record): bool
    {
        return (int) app(ActiveCompany::class)->current()?->getKey() === (int) $record->company_id;
    }

    private function visibleToManager(User $user, ?Employee $employee): bool
    {
        if (! $employee || ! $user->can('employee.view_team')) {
            return false;
        }

        $managerEmployeeIds = $user->employees()
            ->where('company_id', $employee->company_id)
            ->select('id');

        return Employee::query()
            ->where('company_id', $employee->company_id)
            ->whereKey($employee->getKey())
            ->where(fn (Builder $query): Builder => $query
                ->whereIn('id', $managerEmployeeIds)
                ->orWhereIn('manager_id', $managerEmployeeIds))
            ->exists();
    }
}
