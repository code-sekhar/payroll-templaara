<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceDayStatus;
use App\Enums\WorkScheduleDayType;
use App\Exceptions\WorkSchedules\NoWorkScheduleAssignmentException;
use App\Models\Employee;
use App\Services\Holidays\HolidayResolver;
use App\Services\Leave\ApprovedLeaveResolver;
use App\Services\WorkSchedules\WorkScheduleResolver;
use Carbon\CarbonInterface;

final class AttendanceDayResolver
{
    public function __construct(
        private readonly WorkScheduleResolver $workScheduleResolver,
        private readonly HolidayResolver $holidayResolver,
        private readonly ApprovedLeaveResolver $approvedLeaveResolver,
    ) {}

    public function resolve(Employee $employee, CarbonInterface $date): ResolvedAttendanceDay
    {
        $workSchedule = null;

        try {
            $workSchedule = $this->workScheduleResolver->resolve($employee, $date);
        } catch (NoWorkScheduleAssignmentException) {
            // Holiday resolution still runs so the result retains diagnostic calendar context.
        }

        // Invalid configuration exceptions from domain resolvers intentionally propagate.
        $holiday = $this->holidayResolver->resolve($employee, $date);
        $approvedLeave = $this->approvedLeaveResolver->resolve($employee, $date);

        if ($workSchedule === null) {
            $status = AttendanceDayStatus::NoSchedule;
        } elseif ($holiday->isHoliday()) {
            $status = AttendanceDayStatus::Holiday;
        } elseif ($workSchedule->dayType === WorkScheduleDayType::Off) {
            $status = AttendanceDayStatus::OffDay;
        } elseif ($approvedLeave->hasApprovedLeave()
            && $approvedLeave->requestedAmountCents >= ($workSchedule->dayType === WorkScheduleDayType::HalfDay ? 50 : 100)) {
            $status = AttendanceDayStatus::OnLeave;
        } else {
            // HALF_DAY is a configured workday; this foundation only classifies working vs off.
            $status = AttendanceDayStatus::WorkingDay;
        }

        return new ResolvedAttendanceDay(
            $workSchedule?->employee ?? $holiday->employee,
            $workSchedule?->date ?? $holiday->date,
            $status,
            $workSchedule,
            $holiday,
            $approvedLeave,
        );
    }
}
