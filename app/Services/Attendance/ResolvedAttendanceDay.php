<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceDayStatus;
use App\Models\Employee;
use App\Services\Holidays\ResolvedHoliday;
use App\Services\Leave\ResolvedApprovedLeave;
use App\Services\WorkSchedules\ResolvedWorkSchedule;
use Carbon\CarbonImmutable;

final readonly class ResolvedAttendanceDay
{
    public function __construct(
        private Employee $resolvedEmployee,
        private CarbonImmutable $resolvedDate,
        private AttendanceDayStatus $resolvedStatus,
        private ?ResolvedWorkSchedule $resolvedWorkSchedule,
        private ResolvedHoliday $resolvedHoliday,
        private ResolvedApprovedLeave $resolvedApprovedLeave,
    ) {}

    public function employee(): Employee
    {
        return $this->resolvedEmployee;
    }

    public function date(): CarbonImmutable
    {
        return $this->resolvedDate;
    }

    public function status(): AttendanceDayStatus
    {
        return $this->resolvedStatus;
    }

    public function workSchedule(): ?ResolvedWorkSchedule
    {
        return $this->resolvedWorkSchedule;
    }

    public function holiday(): ResolvedHoliday
    {
        return $this->resolvedHoliday;
    }

    public function approvedLeave(): ResolvedApprovedLeave
    {
        return $this->resolvedApprovedLeave;
    }
}
