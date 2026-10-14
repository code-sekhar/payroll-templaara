<?php

namespace App\Services\Employees;

use App\Enums\EmployeeHolidayCalendarAssignmentStatus;
use App\Enums\EmployeeWorkScheduleAssignmentStatus;
use App\Enums\LeaveRequestDayType;
use App\Enums\LeaveRequestStatus;
use App\Enums\WorkScheduleDayType;
use App\Exceptions\WorkSchedules\NoWorkScheduleAssignmentException;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeHolidayCalendarAssignment;
use App\Models\EmployeeWorkScheduleAssignment;
use App\Models\LeaveRequestDay;
use App\Services\Holidays\HolidayResolver;
use App\Services\WorkSchedules\WorkScheduleResolver;
use Carbon\CarbonImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

final class EmployeeLeaveCalendarQuery
{
    public function __construct(
        private readonly HolidayResolver $holidayResolver,
        private readonly WorkScheduleResolver $workScheduleResolver,
    ) {}

    public function forEmployee(Employee $employee, Company $company, ?string $requestedMonth): EmployeeLeaveCalendarData
    {
        abort_unless((int) $employee->company_id === (int) $company->getKey(), 403);

        $timezone = $company->timezone ?: config('app.timezone', 'UTC');
        try {
            new DateTimeZone($timezone);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('The active Company timezone is invalid.', previous: $exception);
        }

        $currentDate = CarbonImmutable::now($timezone)->startOfDay();
        $month = $this->month($requestedMonth, $currentDate);
        $monthStart = $month->startOfMonth();
        $monthEnd = $month->endOfMonth();
        $nextMonthStart = $monthStart->addMonth();
        $calendarDays = [];

        foreach (range(0, $monthEnd->day - 1) as $offset) {
            $date = $monthStart->addDays($offset);
            $calendarDays[$date->toDateString()] = [
                'date' => $date->toDateString(),
                'leaveItems' => [],
                'holidayName' => null,
                'weeklyOff' => false,
                'scheduleAvailable' => false,
            ];
        }

        $leaveDays = LeaveRequestDay::query()
            ->where('company_id', $company->getKey())
            ->where('employee_id', $employee->getKey())
            ->where('leave_date', '>=', $monthStart->toDateString())
            ->where('leave_date', '<', $nextMonthStart->toDateString())
            ->whereHas('request', fn ($query) => $query
                ->where('company_id', $company->getKey())
                ->where('employee_id', $employee->getKey())
                ->whereIn('status', [LeaveRequestStatus::Pending->value, LeaveRequestStatus::Approved->value]))
            ->with([
                'request' => fn ($query) => $query
                    ->select(['id', 'company_id', 'employee_id', 'leave_type_id', 'status'])
                    ->where('company_id', $company->getKey())
                    ->where('employee_id', $employee->getKey())
                    ->with(['leaveType' => fn ($typeQuery) => $typeQuery
                        ->select(['id', 'company_id', 'name'])
                        ->where('company_id', $company->getKey())]),
            ])
            ->orderBy('leave_date')
            ->orderBy('leave_request_id')
            ->orderBy('id')
            ->get(['id', 'company_id', 'employee_id', 'leave_request_id', 'leave_date', 'day_type', 'requested_amount']);

        foreach ($leaveDays as $leaveDay) {
            $request = $leaveDay->request;
            if (! $request || ! isset($calendarDays[$leaveDay->leave_date->toDateString()])) {
                continue;
            }

            $calendarDays[$leaveDay->leave_date->toDateString()]['leaveItems'][] = [
                'leaveType' => $request->leaveType?->name ?? 'Leave type unavailable',
                'status' => $request->status->value,
                'statusLabel' => EmployeeLeaveRequestHistoryQuery::statusLabel($request->status),
                'amount' => (string) $leaveDay->requested_amount,
                'dayType' => self::dayTypeLabel($leaveDay->day_type),
                'href' => route('employee.leave.requests.show', $request->getKey()),
            ];
        }

        $holidayAssignments = EmployeeHolidayCalendarAssignment::query()
            ->where('company_id', $company->getKey())
            ->where('employee_id', $employee->getKey())
            ->where('status', EmployeeHolidayCalendarAssignmentStatus::Active->value)
            ->overlappingPeriod($monthStart->toDateString(), $monthEnd->toDateString())
            ->orderBy('id')
            ->get(['id', 'effective_from', 'effective_to']);

        foreach (array_keys($calendarDays) as $date) {
            $hasHolidayAssignment = $holidayAssignments->contains(fn (EmployeeHolidayCalendarAssignment $assignment): bool => $assignment->effective_from->toDateString() <= $date
                && ($assignment->effective_to === null || $assignment->effective_to->toDateString() >= $date));

            if ($hasHolidayAssignment) {
                $holiday = $this->holidayResolver->resolve($employee, $date);
                $calendarDays[$date]['holidayName'] = $holiday->holidayName();
            }
        }

        $assignments = EmployeeWorkScheduleAssignment::query()
            ->where('company_id', $company->getKey())
            ->where('employee_id', $employee->getKey())
            ->where('status', EmployeeWorkScheduleAssignmentStatus::Active->value)
            ->overlappingPeriod($monthStart->toDateString(), $monthEnd->toDateString())
            ->orderBy('id')
            ->get(['id', 'effective_from', 'effective_to']);

        $scheduleGroups = [];
        foreach (array_keys($calendarDays) as $date) {
            $assignment = $assignments->first(fn (EmployeeWorkScheduleAssignment $candidate): bool => $candidate->effective_from->toDateString() <= $date
                && ($candidate->effective_to === null || $candidate->effective_to->toDateString() >= $date));

            if (! $assignment) {
                continue;
            }

            $weekday = CarbonImmutable::parse($date, $timezone)->dayOfWeekIso;
            $scheduleGroups[$assignment->getKey().'-'.$weekday]['dates'][] = $date;
            $scheduleGroups[$assignment->getKey().'-'.$weekday]['representative'] ??= $date;
        }

        foreach ($scheduleGroups as $group) {
            try {
                $schedule = $this->workScheduleResolver->resolve($employee, $group['representative']);
                foreach ($group['dates'] as $date) {
                    $calendarDays[$date]['scheduleAvailable'] = true;
                    $calendarDays[$date]['weeklyOff'] = $schedule->dayType === WorkScheduleDayType::Off;
                }
            } catch (NoWorkScheduleAssignmentException) {
                // No assignment is unavailable context, never an inferred day off.
            }
        }

        return new EmployeeLeaveCalendarData(
            month: $monthStart->format('Y-m'),
            monthLabel: $monthStart->format('F Y'),
            previousMonth: $monthStart->subMonth()->format('Y-m'),
            nextMonth: $monthStart->addMonth()->format('Y-m'),
            today: $currentDate->toDateString(),
            timezone: $timezone,
            weekdays: ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'],
            days: array_values($calendarDays),
        );
    }

    private function month(?string $requestedMonth, CarbonImmutable $currentDate): CarbonImmutable
    {
        if ($requestedMonth === null) {
            return $currentDate->startOfMonth();
        }

        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $requestedMonth)) {
            throw new InvalidArgumentException('The calendar month must use YYYY-MM format.');
        }

        $month = CarbonImmutable::createFromFormat('!Y-m', $requestedMonth, $currentDate->timezone);
        if (! $month || $month->format('Y-m') !== $requestedMonth) {
            throw new InvalidArgumentException('The calendar month is invalid.');
        }

        return $month->startOfMonth();
    }

    private static function dayTypeLabel(LeaveRequestDayType $dayType): string
    {
        return match ($dayType) {
            LeaveRequestDayType::FullDay => 'Full day',
            LeaveRequestDayType::HalfDay => 'Half day',
            LeaveRequestDayType::OffDay => 'Scheduled day off (saved request snapshot)',
            LeaveRequestDayType::Holiday => 'Holiday (saved request snapshot)',
        };
    }
}
