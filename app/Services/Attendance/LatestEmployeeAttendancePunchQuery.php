<?php

namespace App\Services\Attendance;

use App\Models\AttendancePunch;
use App\Models\Company;
use App\Models\Employee;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;

final class LatestEmployeeAttendancePunchQuery
{
    public function forEmployeeAt(Employee $employee, Company $company, CarbonInterface $instant): ?AttendancePunch
    {
        if ((int) $employee->company_id !== (int) $company->getKey()) {
            throw new AuthorizationException;
        }

        $query = AttendancePunch::query()
            ->where('company_id', $company->getKey())
            ->where('employee_id', $employee->getKey())
            ->where('punched_at', '<=', $instant->copy()->utc())
            ->orderByDesc('punched_at')
            ->orderByDesc('id');

        return $query->first();
    }
}
