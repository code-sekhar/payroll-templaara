<?php

namespace App\Services\Attendance;

use App\Enums\AttendancePolicyStatus;
use App\Exceptions\Attendance\InvalidAttendancePolicyConfigurationException;
use App\Exceptions\Attendance\NoAttendancePolicyAssignmentException;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;
use ValueError;

final class AttendancePolicyResolver
{
    public function resolve(Employee $employee, CarbonInterface $date): ResolvedAttendancePolicy
    {
        if (! $employee->exists || $employee->getKey() === null) {
            throw new InvalidArgumentException('The employee must be a persisted, active employee.');
        }

        $employeeId = (int) $employee->getKey();
        $resolvedEmployee = Employee::query()
            ->with('company')
            ->whereKey($employeeId)
            ->where('company_id', $employee->company_id)
            ->first();

        if (! $resolvedEmployee || ! $resolvedEmployee->company) {
            throw new InvalidArgumentException('The employee must exist and belong to a company.');
        }

        $companyId = (int) $resolvedEmployee->company_id;
        $timezone = $resolvedEmployee->company->timezone ?: config('app.timezone', 'UTC');
        $localDate = $this->localDate($date, $timezone, $employeeId);
        $assignment = $resolvedEmployee->attendancePolicyAssignmentForDate($localDate);

        if (! $assignment) {
            throw new NoAttendancePolicyAssignmentException($employeeId, $localDate);
        }

        if ((int) $assignment->employee_id !== $employeeId || (int) $assignment->company_id !== $companyId) {
            throw new InvalidAttendancePolicyConfigurationException($employeeId, $localDate, 'assignment_company_mismatch');
        }

        $policy = $assignment->attendancePolicy;
        if (! $policy || $policy->trashed()) {
            throw new InvalidAttendancePolicyConfigurationException($employeeId, $localDate, 'attendance_policy_missing_or_deleted');
        }

        if ((int) $policy->company_id !== $companyId) {
            throw new InvalidAttendancePolicyConfigurationException($employeeId, $localDate, 'attendance_policy_company_mismatch');
        }

        try {
            if (! $policy->status instanceof AttendancePolicyStatus) {
                throw new InvalidAttendancePolicyConfigurationException($employeeId, $localDate, 'attendance_policy_status_invalid');
            }
        } catch (ValueError $exception) {
            throw new InvalidAttendancePolicyConfigurationException($employeeId, $localDate, 'attendance_policy_status_invalid', $exception);
        }

        $minutes = [
            'grace_in_minutes' => $policy->grace_in_minutes,
            'grace_out_minutes' => $policy->grace_out_minutes,
            'late_after_minutes' => $policy->late_after_minutes,
            'early_leave_after_minutes' => $policy->early_leave_after_minutes,
            'half_day_before_minutes' => $policy->half_day_before_minutes,
            'absent_before_minutes' => $policy->absent_before_minutes,
            'overtime_after_minutes' => $policy->overtime_after_minutes,
        ];

        foreach ($minutes as $field => $value) {
            if (! is_int($value) || $value < 0 || $value > 1440) {
                throw new InvalidAttendancePolicyConfigurationException($employeeId, $localDate, "{$field}_invalid");
            }
        }

        if (! is_bool($policy->overtime_enabled)) {
            throw new InvalidAttendancePolicyConfigurationException($employeeId, $localDate, 'overtime_enabled_invalid');
        }

        if ($policy->grace_in_minutes > $policy->late_after_minutes) {
            throw new InvalidAttendancePolicyConfigurationException($employeeId, $localDate, 'grace_in_exceeds_late_after');
        }

        if ($policy->grace_out_minutes > $policy->early_leave_after_minutes) {
            throw new InvalidAttendancePolicyConfigurationException($employeeId, $localDate, 'grace_out_exceeds_early_leave_after');
        }

        return new ResolvedAttendancePolicy($resolvedEmployee, $localDate, $assignment, $policy);
    }

    private function localDate(CarbonInterface $date, string $timezone, int $employeeId): CarbonImmutable
    {
        $dateString = $date->format('Y-m-d');

        try {
            new DateTimeZone($timezone);
        } catch (Throwable $exception) {
            $fallbackTimezone = config('app.timezone', 'UTC');

            try {
                new DateTimeZone($fallbackTimezone);
            } catch (Throwable) {
                $fallbackTimezone = 'UTC';
            }

            throw new InvalidAttendancePolicyConfigurationException(
                $employeeId,
                CarbonImmutable::parse($dateString, $fallbackTimezone)->startOfDay(),
                'company_timezone_invalid',
                $exception,
            );
        }

        return CarbonImmutable::parse($dateString, $timezone)->startOfDay();
    }
}
