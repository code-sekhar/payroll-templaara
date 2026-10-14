<?php

namespace App\Services\Leave;

use App\Enums\LeaveRequestDayType;
use App\Services\Holidays\ResolvedHoliday;
use App\Services\WorkSchedules\ResolvedWorkSchedule;
use Carbon\CarbonImmutable;

final readonly class ResolvedLeaveDay
{
    public function __construct(
        public CarbonImmutable $date,
        public LeaveRequestDayType $dayType,
        public string $requestedAmount,
        public ResolvedWorkSchedule $workSchedule,
        public ResolvedHoliday $holiday,
    ) {}
}
