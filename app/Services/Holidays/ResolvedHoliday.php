<?php

namespace App\Services\Holidays;

use App\Enums\HolidayStatus;
use App\Enums\HolidayType;
use App\Models\Employee;
use App\Models\EmployeeHolidayCalendarAssignment;
use App\Models\Holiday;
use App\Models\HolidayCalendar;
use Carbon\CarbonImmutable;

final readonly class ResolvedHoliday
{
    public function __construct(
        public Employee $employee,
        public CarbonImmutable $date,
        public ?EmployeeHolidayCalendarAssignment $assignment,
        public ?HolidayCalendar $calendar,
        public ?Holiday $holiday,
    ) {}

    public function isHoliday(): bool
    {
        return $this->holiday !== null;
    }

    public function hasAssignment(): bool
    {
        return $this->assignment !== null;
    }

    public function assignment(): ?EmployeeHolidayCalendarAssignment
    {
        return $this->assignment;
    }

    public function calendar(): ?HolidayCalendar
    {
        return $this->calendar;
    }

    public function holiday(): ?Holiday
    {
        return $this->holiday;
    }

    public function isOptional(): bool
    {
        return $this->holiday?->is_optional ?? false;
    }

    public function holidayType(): ?HolidayType
    {
        return $this->holiday?->type;
    }

    public function holidayStatus(): ?HolidayStatus
    {
        return $this->holiday?->status;
    }

    public function holidayName(): ?string
    {
        return $this->holiday?->name;
    }

    public function holidayDescription(): ?string
    {
        return $this->holiday?->description;
    }
}
