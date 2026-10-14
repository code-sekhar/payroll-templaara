<?php

namespace App\Services\Attendance;

final readonly class EmployeeAttendanceMonthData
{
    /** @param list<EmployeeAttendanceDayData> $days */
    public function __construct(
        public string $month,
        public string $monthLabel,
        public string $timezone,
        public string $today,
        public int $recordedDays,
        public ?string $previousMonth,
        public ?string $nextMonth,
        public array $days,
    ) {}

    /** @return array<string, int|string|null|list<array<string, bool|string|null|list<string>>>> */
    public function toArray(): array
    {
        return [
            'month' => $this->month,
            'monthLabel' => $this->monthLabel,
            'timezone' => $this->timezone,
            'today' => $this->today,
            'recordedDays' => $this->recordedDays,
            'previousMonth' => $this->previousMonth,
            'nextMonth' => $this->nextMonth,
            'days' => array_map(fn (EmployeeAttendanceDayData $day): array => $day->toArray(), $this->days),
        ];
    }
}
