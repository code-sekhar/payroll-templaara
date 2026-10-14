<?php

namespace App\Services\Attendance;

use App\Enums\PunchLocationMode;
use App\Models\AttendancePolicy;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeAttendancePolicyAssignment;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;
use ValueError;

final class PunchLocationPolicyResolver
{
    public function resolve(Employee $employee, Company $company, CarbonInterface $at, bool $lockForUpdate = false, bool $loadWorksites = true): PunchLocationPolicyData
    {
        if ((int) $employee->company_id !== (int) $company->getKey()) {
            throw ValidationException::withMessages(['location' => 'Attendance location is not configured. Contact HR.']);
        }

        $assignmentQuery = EmployeeAttendancePolicyAssignment::query()
            ->where('company_id', $company->getKey())
            ->where('employee_id', $employee->getKey())
            ->effectiveOn($at->toDateString())
            ->orderByDesc('effective_from');

        if ($lockForUpdate) {
            $assignmentQuery->lockForUpdate();
        }

        $assignment = $assignmentQuery->first();

        if ($assignment === null) {
            return new PunchLocationPolicyData(PunchLocationMode::Disabled);
        }

        $policyQuery = AttendancePolicy::query()->where('company_id', $company->getKey());
        if ($lockForUpdate) {
            $policyQuery->lockForUpdate();
        }

        $policy = $policyQuery->find($assignment->attendance_policy_id);

        if ($policy === null) {
            throw ValidationException::withMessages(['location' => 'Attendance location is not configured. Contact HR.']);
        }

        try {
            $mode = $policy->location_mode instanceof PunchLocationMode
                ? $policy->location_mode
                : PunchLocationMode::from((string) $policy->location_mode);
        } catch (ValueError) {
            throw ValidationException::withMessages(['location' => 'Attendance location is not configured. Contact HR.']);
        }

        if ($mode !== PunchLocationMode::GeofenceRequired) {
            return new PunchLocationPolicyData($mode);
        }

        $worksitesQuery = $policy->worksites()
            ->where('attendance_worksites.company_id', $company->getKey())
            ->where('attendance_worksites.is_active', true)
            ->whereNull('attendance_worksites.deleted_at')
            ->orderBy('attendance_worksites.id');

        if ($lockForUpdate) {
            $worksitesQuery->lockForUpdate();
        }

        if (! $loadWorksites) {
            return new PunchLocationPolicyData($mode, [], $worksitesQuery->exists());
        }

        $worksites = $worksitesQuery->get()->all();

        return new PunchLocationPolicyData($mode, $worksites, $worksites !== []);
    }
}
