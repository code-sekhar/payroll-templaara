<?php

namespace App\Services\Attendance;

use App\Models\AttendancePolicy;
use App\Models\Employee;
use App\Models\EmployeeAttendancePolicyAssignment;
use Carbon\CarbonImmutable;

final readonly class ResolvedAttendancePolicy
{
    public function __construct(
        private Employee $resolvedEmployee,
        private CarbonImmutable $resolvedDate,
        private EmployeeAttendancePolicyAssignment $resolvedAssignment,
        private AttendancePolicy $resolvedPolicy,
    ) {}

    public function employee(): Employee
    {
        return $this->resolvedEmployee;
    }

    public function date(): CarbonImmutable
    {
        return $this->resolvedDate;
    }

    public function assignment(): EmployeeAttendancePolicyAssignment
    {
        return $this->resolvedAssignment;
    }

    public function policy(): AttendancePolicy
    {
        return $this->resolvedPolicy;
    }

    public function graceInMinutes(): int
    {
        return $this->resolvedPolicy->grace_in_minutes;
    }

    public function graceOutMinutes(): int
    {
        return $this->resolvedPolicy->grace_out_minutes;
    }

    public function lateAfterMinutes(): int
    {
        return $this->resolvedPolicy->late_after_minutes;
    }

    public function earlyLeaveAfterMinutes(): int
    {
        return $this->resolvedPolicy->early_leave_after_minutes;
    }

    public function halfDayBeforeMinutes(): int
    {
        return $this->resolvedPolicy->half_day_before_minutes;
    }

    public function absentBeforeMinutes(): int
    {
        return $this->resolvedPolicy->absent_before_minutes;
    }

    public function overtimeEnabled(): bool
    {
        return $this->resolvedPolicy->overtime_enabled;
    }

    public function overtimeAfterMinutes(): int
    {
        return $this->resolvedPolicy->overtime_after_minutes;
    }
}
