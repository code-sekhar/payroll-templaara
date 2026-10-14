<?php

namespace App\Services\Employees;

final readonly class EmployeeLeaveRequestDetailData
{
    /**
     * @param  list<array{label: string, at: string}>  $timeline
     * @param  list<array{date: string, dayType: string, amount: string}>  $days
     */
    public function __construct(
        public string $leaveType,
        public string $status,
        public string $statusLabel,
        public string $startDate,
        public string $endDate,
        public string $requestedDays,
        public string $duration,
        public string $durationLabel,
        public string $requestedAt,
        public ?string $reason,
        public array $timeline,
        public bool $hasDayBreakdown,
        public array $days,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'leaveType' => $this->leaveType,
            'status' => $this->status,
            'statusLabel' => $this->statusLabel,
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'requestedDays' => $this->requestedDays,
            'duration' => $this->duration,
            'durationLabel' => $this->durationLabel,
            'requestedAt' => $this->requestedAt,
            'reason' => $this->reason,
            'timeline' => $this->timeline,
            'hasDayBreakdown' => $this->hasDayBreakdown,
            'days' => $this->days,
        ];
    }
}
