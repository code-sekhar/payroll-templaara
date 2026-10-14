<?php

namespace App\Services\Attendance;

use App\Enums\CalculatedAttendanceStatus;
use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Services\Leave\ResolvedApprovedLeave;
use Carbon\CarbonImmutable;

/** @phpstan-type PunchPairList list<PunchPair> */
final readonly class CalculatedDailyAttendance
{
    /**
     * @param  list<AttendancePunch|EffectiveAttendancePunch>  $punches
     * @param  list<PunchPair>  $punchPairs
     */
    public function __construct(
        public Employee $employee,
        public CarbonImmutable $attendanceDate,
        public CalculatedAttendanceStatus $status,
        public ResolvedAttendanceDay $resolvedAttendanceDay,
        public ?ResolvedAttendancePolicy $resolvedAttendancePolicy,
        public ?CarbonImmutable $expectedStart,
        public ?CarbonImmutable $expectedEnd,
        public int $expectedMinutes,
        public ?CarbonImmutable $firstCheckIn,
        public ?CarbonImmutable $lastCheckOut,
        public int $workedMinutes,
        public int $lateMinutes,
        public int $earlyLeaveMinutes,
        public int $overtimeMinutes,
        public bool $isLate,
        public bool $isEarlyLeave,
        public array $punches,
        public array $punchPairs,
    ) {}

    public function approvedLeave(): ResolvedApprovedLeave
    {
        return $this->resolvedAttendanceDay->approvedLeave();
    }

    public function leaveAmount(): string
    {
        return $this->approvedLeave()->requestedAmount;
    }

    public function isPaidLeave(): ?bool
    {
        return $this->approvedLeave()->isPaid();
    }
}
