<?php

namespace App\Services\WorkSchedules;

use App\Enums\DayOfWeek;
use App\Enums\WorkScheduleDayType;
use App\Models\Employee;
use App\Models\EmployeeWorkScheduleAssignment;
use App\Models\Shift;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleDay;
use Carbon\CarbonImmutable;

final readonly class ResolvedWorkSchedule
{
    public function __construct(
        public Employee $employee,
        public CarbonImmutable $date,
        public EmployeeWorkScheduleAssignment $assignment,
        public WorkSchedule $workSchedule,
        public WorkScheduleDay $scheduleDay,
        public DayOfWeek $dayOfWeek,
        public WorkScheduleDayType $dayType,
        public ?Shift $shift,
        public ?CarbonImmutable $expectedStart,
        public ?CarbonImmutable $expectedEnd,
        public int $expectedWorkingMinutes,
    ) {}

    public function status(): WorkScheduleDayType
    {
        return $this->dayType;
    }
}
