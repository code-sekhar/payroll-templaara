<?php

namespace App\Policies;

use App\Models\AttendancePunch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Tenancy\ActiveCompany;
use Illuminate\Database\Eloquent\Builder;

class AttendancePunchPolicy
{
    public function viewAny(User $user, Company $company): bool
    {
        return $this->isActiveCompany($company) && $user->can('attendance_punch.view');
    }

    public function view(User $user, AttendancePunch $attendancePunch): bool
    {
        if (! $this->belongsToActiveCompany($attendancePunch) || ! $user->can('attendance_punch.view')) {
            return false;
        }

        return $user->can('employee.view') || $this->isVisibleToManager($user, $attendancePunch);
    }

    public function createManual(User $user, Company $company): bool
    {
        return $this->isActiveCompany($company) && $user->can('attendance_punch.create_manual');
    }

    public function selfPunch(User $user, Company $company, ?Employee $employee = null): bool
    {
        if (! $this->isActiveCompany($company) || ! $user->can('attendance_punch.self_punch')) {
            return false;
        }

        return $employee === null
            || ((int) $employee->company_id === (int) $company->id && (int) $employee->user_id === (int) $user->id);
    }

    public function update(User $user, AttendancePunch $attendancePunch): bool
    {
        return false;
    }

    public function delete(User $user, AttendancePunch $attendancePunch): bool
    {
        return false;
    }

    private function isVisibleToManager(User $user, AttendancePunch $attendancePunch): bool
    {
        if (! $user->can('employee.view_team')) {
            return false;
        }

        $managerEmployeeIds = $user->employees()
            ->where('company_id', $attendancePunch->company_id)
            ->select('id');

        return Employee::query()
            ->where('company_id', $attendancePunch->company_id)
            ->whereKey($attendancePunch->employee_id)
            ->where(fn (Builder $query): Builder => $query
                ->whereIn('id', $managerEmployeeIds)
                ->orWhereIn('manager_id', $managerEmployeeIds))
            ->exists();
    }

    private function belongsToActiveCompany(AttendancePunch $attendancePunch): bool
    {
        return app(ActiveCompany::class)->current()?->getKey() === $attendancePunch->company_id;
    }

    private function isActiveCompany(Company $company): bool
    {
        return app(ActiveCompany::class)->current()?->is($company) ?? false;
    }
}
