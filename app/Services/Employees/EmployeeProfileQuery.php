<?php

namespace App\Services\Employees;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\EmployeeWorkScheduleAssignment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final class EmployeeProfileQuery
{
    public function forEmployee(Employee $resolvedEmployee, Company $company): EmployeeProfileData
    {
        abort_unless((int) $resolvedEmployee->company_id === (int) $company->getKey(), 403);

        $employee = Employee::query()
            ->where('company_id', $company->getKey())
            ->whereKey($resolvedEmployee->getKey())
            ->with([
                'department' => fn ($query) => $query->select(['id', 'company_id', 'name'])->where('company_id', $company->getKey()),
                'designation' => fn ($query) => $query->select(['id', 'company_id', 'name'])->where('company_id', $company->getKey()),
                'branch' => fn ($query) => $query->select(['id', 'company_id', 'name'])->where('company_id', $company->getKey()),
                'manager' => fn ($query) => $query->select(['id', 'company_id', 'first_name', 'middle_name', 'last_name'])->where('company_id', $company->getKey()),
                'user:id,email',
            ])
            ->firstOrFail([
                'id', 'company_id', 'user_id', 'employee_code', 'first_name', 'middle_name', 'last_name',
                'personal_email', 'personal_phone', 'department_id', 'designation_id', 'branch_id', 'manager_id',
                'joining_date', 'employment_type', 'employment_status',
            ]);

        $localDate = CarbonImmutable::now($company->timezone ?: config('app.timezone', 'UTC'))->toDateString();
        $shiftAssignment = EmployeeShiftAssignment::query()
            ->where('company_id', $company->getKey())
            ->where('employee_id', $employee->getKey())
            ->effectiveOn($localDate)
            ->with('shift:id,company_id,name')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
        $scheduleAssignment = EmployeeWorkScheduleAssignment::query()
            ->where('company_id', $company->getKey())
            ->where('employee_id', $employee->getKey())
            ->effectiveOn($localDate)
            ->with('workSchedule:id,company_id,name')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();

        return new EmployeeProfileData([
            'identity' => [
                'name' => $employee->full_name,
                'employeeCode' => $employee->employee_code,
            ],
            'personal' => [
                'email' => $employee->personal_email,
                'phone' => $employee->personal_phone,
            ],
            'employment' => [
                'department' => $employee->department?->name,
                'designation' => $employee->designation?->name,
                'branch' => $employee->branch?->name,
                'type' => Str::headline($employee->employment_type->value),
                'status' => Str::headline($employee->employment_status->value),
                'joiningDate' => $employee->joining_date?->toDateString(),
                'manager' => $employee->manager?->full_name,
            ],
            'workAssignment' => [
                'shift' => $shiftAssignment?->shift?->name,
                'workSchedule' => $scheduleAssignment?->workSchedule?->name,
            ],
            'account' => [
                'loginEmail' => $employee->user?->email,
                'connected' => $employee->user_id !== null && $employee->user !== null,
            ],
        ]);
    }
}
