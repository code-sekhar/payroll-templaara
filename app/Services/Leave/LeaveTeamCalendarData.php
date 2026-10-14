<?php

namespace App\Services\Leave;

final readonly class LeaveTeamCalendarData
{
    /**
     * @param  list<string>  $weekdays
     * @param  list<array{date: string, dayNumber: int, approvedCount: int, pendingCount: int}>  $days
     * @param  array{data: list<array{employeeName: string, employeeCode: string|null, leaveType: string, status: string, statusLabel: string, amount: string, dayType: string, href: string}>, total: int, current_page: int, last_page: int, per_page: int, prev_page_url: string|null, next_page_url: string|null}  $selectedDay
     * @param  array<string, mixed>  $filters
     * @param  list<array{id: int, name: string}>  $leaveTypes
     * @param  list<array{id: int, name: string}>  $departments
     */
    public function __construct(
        public string $month,
        public string $monthLabel,
        public string $previousMonth,
        public string $nextMonth,
        public string $today,
        public string $selectedDate,
        public int $firstWeekdayOffset,
        public string $timezone,
        public array $weekdays,
        public array $days,
        public int $approvedEvents,
        public int $pendingEvents,
        public array $selectedDay,
        public array $filters,
        public array $leaveTypes,
        public array $departments,
        public bool $canFilterDepartments,
        public string $indexUrl,
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
            'selectedDate' => $this->selectedDate,
            'firstWeekdayOffset' => $this->firstWeekdayOffset,
            'timezone' => $this->timezone,
            'weekdays' => $this->weekdays,
            'days' => $this->days,
            'approvedEvents' => $this->approvedEvents,
            'pendingEvents' => $this->pendingEvents,
            'selectedDay' => $this->selectedDay,
            'filters' => $this->filters,
            'leaveTypes' => $this->leaveTypes,
            'departments' => $this->departments,
            'canFilterDepartments' => $this->canFilterDepartments,
            'indexUrl' => $this->indexUrl,
        ];
    }
}
