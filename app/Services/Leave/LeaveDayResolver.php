<?php

namespace App\Services\Leave;

use App\Enums\LeaveRequestDayType;
use App\Enums\WorkScheduleDayType;
use App\Exceptions\Leave\LeaveRequestConfigurationException;
use App\Models\Employee;
use App\Services\Holidays\HolidayResolver;
use App\Services\WorkSchedules\WorkScheduleResolver;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

final class LeaveDayResolver
{
    public function __construct(
        private readonly WorkScheduleResolver $workScheduleResolver,
        private readonly HolidayResolver $holidayResolver,
    ) {}

    public function resolve(Employee $employee, DateTimeInterface|string $startDate, DateTimeInterface|string $endDate, bool $halfDay = false): ResolvedLeavePeriod
    {
        $timezone = $employee->company?->timezone ?: config('app.timezone', 'UTC');
        $start = $this->asDate($startDate, $timezone);
        $end = $this->asDate($endDate, $timezone);
        if ($end->lt($start)) {
            throw new InvalidArgumentException('The leave end date must be on or after the start date.');
        }
        if ($start->diffInDays($end) > 365) {
            throw new InvalidArgumentException('A leave request may not exceed 366 calendar days.');
        }
        if ($halfDay && ! $start->isSameDay($end)) {
            throw new InvalidArgumentException('A half-day request must cover exactly one calendar date.');
        }

        $days = [];
        $totalCents = 0;
        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            $schedule = $this->workScheduleResolver->resolve($employee, $date->toDateString());
            $holiday = $this->holidayResolver->resolve($employee, $date->toDateString());

            if ($holiday->isHoliday()) {
                $dayType = LeaveRequestDayType::Holiday;
                $amount = '0.00';
            } elseif ($schedule->dayType === WorkScheduleDayType::Off) {
                $dayType = LeaveRequestDayType::OffDay;
                $amount = '0.00';
            } elseif ($halfDay || $schedule->dayType === WorkScheduleDayType::HalfDay) {
                $dayType = LeaveRequestDayType::HalfDay;
                $amount = '0.50';
            } else {
                $dayType = LeaveRequestDayType::FullDay;
                $amount = '1.00';
            }

            if ($halfDay && ($dayType !== LeaveRequestDayType::HalfDay || $amount !== '0.50' || $holiday->isHoliday())) {
                throw new LeaveRequestConfigurationException('HALF_DAY_NOT_WORKING', 'A half-day request must be on a scheduled working date.');
            }

            $totalCents += LeaveAmount::toCents($amount);
            $days[] = new ResolvedLeaveDay($date, $dayType, $amount, $schedule, $holiday);
        }

        return new ResolvedLeavePeriod($start, $end, $days, LeaveAmount::fromCents($totalCents));
    }

    private function asDate(DateTimeInterface|string $date, string $timezone): CarbonImmutable
    {
        $value = $date instanceof DateTimeInterface ? $date->format('Y-m-d') : CarbonImmutable::parse($date, $timezone)->toDateString();

        return CarbonImmutable::parse($value, $timezone)->startOfDay();
    }
}
