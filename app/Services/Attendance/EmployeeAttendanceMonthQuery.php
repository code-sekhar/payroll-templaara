<?php

namespace App\Services\Attendance;

use App\Enums\CalculatedAttendanceStatus;
use App\Models\AttendanceRegularizationRequest;
use App\Models\Company;
use App\Models\DailyAttendanceRecord;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

final class EmployeeAttendanceMonthQuery
{
    public function forEmployee(Employee $employee, Company $company, ?string $month = null, bool $includeCorrectionState = false): EmployeeAttendanceMonthData
    {
        abort_unless((int) $employee->company_id === (int) $company->getKey(), 403);

        $timezone = $company->timezone ?: config('app.timezone', 'UTC');

        try {
            new DateTimeZone($timezone);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('The company timezone must be a valid IANA timezone.', previous: $exception);
        }

        $now = CarbonImmutable::now($timezone);
        $monthStart = $month === null
            ? $now->startOfMonth()
            : CarbonImmutable::createFromFormat('!Y-m', $month, $timezone)->startOfMonth();
        $monthEnd = $monthStart->endOfMonth();
        $today = $now->toDateString();

        $records = DailyAttendanceRecord::query()
            ->where('company_id', $company->getKey())
            ->where('employee_id', $employee->getKey())
            ->where('attendance_date', '>=', $monthStart->toDateString())
            ->where('attendance_date', '<', $monthStart->addMonth()->toDateString())
            ->with([
                'shift:id,name',
                'holiday:id,name',
                'leaveType:id,name',
            ])
            ->get([
                'id', 'attendance_date', 'status', 'expected_start_at', 'expected_end_at', 'expected_minutes',
                'first_check_in_at', 'last_check_out_at', 'worked_minutes', 'late_minutes', 'early_leave_minutes',
                'is_late', 'is_early_leave', 'shift_id', 'holiday_id',
                'leave_request_id', 'leave_type_id',
            ])
            ->keyBy(fn (DailyAttendanceRecord $record): string => $record->attendance_date->toDateString());

        $pendingCorrectionDates = $includeCorrectionState
            ? AttendanceRegularizationRequest::query()
                ->where('company_id', $company->getKey())
                ->where('employee_id', $employee->getKey())
                ->where('status', 'pending')
                ->where('attendance_date', '>=', $monthStart->toDateString())
                ->where('attendance_date', '<', $monthStart->addMonth()->toDateString())
                ->pluck('attendance_date')
                ->map(fn ($date): string => CarbonImmutable::parse($date, $timezone)->toDateString())
                ->all()
            : [];

        $days = [];
        foreach (CarbonImmutable::parse($monthStart->toDateString(), $timezone)->daysUntil($monthEnd->toDateString()) as $date) {
            $dateString = $date->toDateString();
            $record = $records->get($dateString);
            $isFuture = $dateString > $today;
            $status = $record?->status;
            $holiday = $record !== null && ($record->holiday_id !== null || $status === CalculatedAttendanceStatus::Holiday);
            $approvedLeave = $record !== null && (
                $record->leave_request_id !== null
                || in_array($status, [CalculatedAttendanceStatus::OnLeave, CalculatedAttendanceStatus::PartialLeave], true)
            );
            $workedMinutes = $record?->worked_minutes ?? 0;

            $days[] = new EmployeeAttendanceDayData(
                date: $dateString,
                dateLabel: $date->format('j M'),
                weekday: $date->translatedFormat('l'),
                isToday: $dateString === $today,
                isFuture: $isFuture,
                withinEmploymentPeriod: ! $employee->joining_date || $dateString >= $employee->joining_date->toDateString(),
                recorded: $record !== null,
                attendanceStatus: $status?->value,
                statusLabel: $this->statusLabel($status),
                scheduledWork: $record !== null && ($record->expected_start_at !== null || ($record->expected_minutes ?? 0) > 0),
                shiftName: $record?->shift?->name,
                scheduledStart: $this->localDateTime($record?->expected_start_at, $dateString, $timezone),
                scheduledEnd: $this->localDateTime($record?->expected_end_at, $dateString, $timezone),
                checkIn: $this->localDateTime($record?->first_check_in_at, $dateString, $timezone),
                checkOut: $this->localDateTime($record?->last_check_out_at, $dateString, $timezone),
                workedDuration: $workedMinutes > 0 ? $this->duration($workedMinutes) : null,
                holiday: $holiday,
                holidayName: $holiday ? $record?->holiday?->name : null,
                approvedLeave: $approvedLeave,
                leaveType: $approvedLeave ? $record?->leaveType?->name : null,
                exceptions: $this->exceptions($record, $dateString, $today, $holiday, $approvedLeave),
                correctionPending: in_array($dateString, $pendingCorrectionDates, true),
            );
        }

        return new EmployeeAttendanceMonthData(
            month: $monthStart->format('Y-m'),
            monthLabel: $monthStart->translatedFormat('F Y'),
            timezone: $timezone,
            today: $today,
            recordedDays: $records->count(),
            previousMonth: $monthStart->format('Y-m') > '0001-01'
                ? $monthStart->subMonth()->format('Y-m')
                : null,
            nextMonth: $monthStart->format('Y-m') < '9999-12'
                ? $monthStart->addMonth()->format('Y-m')
                : null,
            days: $days,
        );
    }

    private function statusLabel(?CalculatedAttendanceStatus $status): ?string
    {
        return match ($status) {
            CalculatedAttendanceStatus::Present => 'Present',
            CalculatedAttendanceStatus::PartialLeave => 'Partial leave',
            CalculatedAttendanceStatus::OnLeave => 'On leave',
            CalculatedAttendanceStatus::HalfDay => 'Half day',
            CalculatedAttendanceStatus::Absent => 'Absent',
            CalculatedAttendanceStatus::Holiday => 'Holiday',
            CalculatedAttendanceStatus::OffDay => 'Weekly off',
            CalculatedAttendanceStatus::Incomplete => 'Incomplete',
            CalculatedAttendanceStatus::NoSchedule => 'No schedule',
            CalculatedAttendanceStatus::InProgress => 'In progress',
            CalculatedAttendanceStatus::NotYetDue => 'Not yet due',
            null => null,
        };
    }

    private function localDateTime(?CarbonImmutable $dateTime, string $attendanceDate, string $timezone): ?string
    {
        if ($dateTime === null) {
            return null;
        }

        $local = $dateTime->setTimezone($timezone);
        $localDate = $local->toDateString();
        $suffix = $localDate > $attendanceDate
            ? ' (+'.CarbonImmutable::parse($attendanceDate, $timezone)->diffInDays($local->startOfDay()).' day)'
            : ($localDate < $attendanceDate ? ' (previous day)' : '');

        return $local->format('g:i A').$suffix;
    }

    private function duration(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        return $hours > 0
            ? $hours.'h'.($remainingMinutes > 0 ? ' '.$remainingMinutes.'m' : '')
            : $remainingMinutes.'m';
    }

    /** @return list<string> */
    private function exceptions(?DailyAttendanceRecord $record, string $date, string $today, bool $holiday, bool $approvedLeave): array
    {
        if ($record === null || $date > $today) {
            return [];
        }

        $exceptions = [];

        if ($record->status === CalculatedAttendanceStatus::Incomplete) {
            $exceptions[] = 'Attendance details are incomplete.';
        }

        if ($holiday || $approvedLeave) {
            return $exceptions;
        }

        if ($record->is_late && $record->late_minutes > 0) {
            $exceptions[] = 'Late arrival · '.$this->duration($record->late_minutes);
        }

        if ($record->is_early_leave && $record->early_leave_minutes > 0) {
            $exceptions[] = 'Early departure · '.$this->duration($record->early_leave_minutes);
        }

        return $exceptions;
    }
}
