<?php

namespace App\Services\Attendance;

final readonly class EmployeeAttendanceDayData
{
    public function __construct(
        public string $date,
        public string $dateLabel,
        public string $weekday,
        public bool $isToday,
        public bool $isFuture,
        public bool $withinEmploymentPeriod,
        public bool $recorded,
        public ?string $attendanceStatus,
        public ?string $statusLabel,
        public bool $scheduledWork,
        public ?string $shiftName,
        public ?string $scheduledStart,
        public ?string $scheduledEnd,
        public ?string $checkIn,
        public ?string $checkOut,
        public ?string $workedDuration,
        public bool $holiday,
        public ?string $holidayName,
        public bool $approvedLeave,
        public ?string $leaveType,
        /** @var list<string> */
        public array $exceptions,
        public bool $correctionPending,
    ) {}

    /** @return array<string, bool|string|null|list<string>> */
    public function toArray(): array
    {
        return [
            'date' => $this->date,
            'dateLabel' => $this->dateLabel,
            'weekday' => $this->weekday,
            'isToday' => $this->isToday,
            'isFuture' => $this->isFuture,
            'withinEmploymentPeriod' => $this->withinEmploymentPeriod,
            'recorded' => $this->recorded,
            'attendanceStatus' => $this->attendanceStatus,
            'statusLabel' => $this->statusLabel,
            'scheduledWork' => $this->scheduledWork,
            'shiftName' => $this->shiftName,
            'scheduledStart' => $this->scheduledStart,
            'scheduledEnd' => $this->scheduledEnd,
            'checkIn' => $this->checkIn,
            'checkOut' => $this->checkOut,
            'workedDuration' => $this->workedDuration,
            'holiday' => $this->holiday,
            'holidayName' => $this->holidayName,
            'approvedLeave' => $this->approvedLeave,
            'leaveType' => $this->leaveType,
            'exceptions' => $this->exceptions,
            'correctionPending' => $this->correctionPending,
        ];
    }
}
