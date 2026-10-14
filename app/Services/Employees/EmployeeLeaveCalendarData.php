<?php

namespace App\Services\Employees;

final readonly class EmployeeLeaveCalendarData
{
    /**
     * @param  list<string>  $weekdays
     * @param  list<array{date: string, leaveItems: list<array{leaveType: string, status: string, statusLabel: string, amount: string, dayType: string, href: string}>, holidayName: string|null, weeklyOff: bool, scheduleAvailable: bool}>  $days
     */
    public function __construct(
        public string $month,
        public string $monthLabel,
        public string $previousMonth,
        public string $nextMonth,
        public string $today,
        public string $timezone,
        public array $weekdays,
        public array $days,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'month' => $this->month,
            'monthLabel' => $this->monthLabel,
            'previousMonth' => $this->previousMonth,
            'nextMonth' => $this->nextMonth,
            'today' => $this->today,
            'timezone' => $this->timezone,
            'weekdays' => $this->weekdays,
            'days' => $this->days,
        ];
    }
}
