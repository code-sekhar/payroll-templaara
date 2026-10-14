<?php

namespace App\Services\Holidays;

use App\Enums\HolidayCalendarStatus;
use App\Enums\HolidayStatus;
use App\Exceptions\Holidays\InvalidHolidayCalendarConfigurationException;
use App\Models\Employee;
use App\Models\Holiday;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;
use ValueError;

final class HolidayResolver
{
    public function resolve(Employee $employee, DateTimeInterface|string $date): ResolvedHoliday
    {
        if (! $employee->exists || $employee->getKey() === null) {
            throw new InvalidArgumentException('The employee must be a persisted, active employee.');
        }

        $employeeId = (int) $employee->getKey();
        $resolvedEmployee = Employee::query()->with('company')
            ->whereKey($employeeId)
            ->where('company_id', $employee->company_id)
            ->first();

        if (! $resolvedEmployee || ! $resolvedEmployee->company) {
            throw new InvalidArgumentException('The employee must exist and belong to a company.');
        }

        $companyId = (int) $resolvedEmployee->company_id;
        $timezone = $resolvedEmployee->company->timezone ?: config('app.timezone', 'UTC');
        $localDate = $this->localDate($date, $timezone, $employeeId);
        $assignment = $resolvedEmployee->holidayCalendarAssignmentForDate($localDate->toDateString());

        if (! $assignment) {
            return new ResolvedHoliday($resolvedEmployee, $localDate, null, null, null);
        }

        if ((int) $assignment->employee_id !== $employeeId || (int) $assignment->company_id !== $companyId) {
            throw new InvalidHolidayCalendarConfigurationException($employeeId, $localDate, 'assignment_company_mismatch');
        }

        $calendar = $assignment->holidayCalendar;
        if (! $calendar || $calendar->trashed()) {
            throw new InvalidHolidayCalendarConfigurationException($employeeId, $localDate, 'calendar_missing_or_archived');
        }
        if ((int) $calendar->company_id !== $companyId) {
            throw new InvalidHolidayCalendarConfigurationException($employeeId, $localDate, 'calendar_company_mismatch');
        }

        try {
            $calendarStatus = $calendar->status;
        } catch (ValueError $exception) {
            throw new InvalidHolidayCalendarConfigurationException($employeeId, $localDate, 'invalid_calendar_status', $exception);
        }
        if ($calendarStatus !== HolidayCalendarStatus::Active) {
            throw new InvalidHolidayCalendarConfigurationException($employeeId, $localDate, 'calendar_inactive');
        }
        if ((int) $calendar->year !== (int) $localDate->format('Y')) {
            throw new InvalidHolidayCalendarConfigurationException($employeeId, $localDate, 'calendar_year_mismatch');
        }

        $dateHolidays = Holiday::withTrashed()
            ->where('holiday_calendar_id', $calendar->id)
            ->whereDate('date', $localDate->toDateString())
            ->get();

        foreach ($dateHolidays as $dateHoliday) {
            if ((int) $dateHoliday->company_id !== $companyId || (int) $dateHoliday->holiday_calendar_id !== (int) $calendar->id) {
                throw new InvalidHolidayCalendarConfigurationException($employeeId, $localDate, 'holiday_company_mismatch');
            }
        }

        $activeHolidays = $dateHolidays->filter(function (Holiday $holiday) use ($employeeId, $localDate): bool {
            if ($holiday->trashed()) {
                return false;
            }

            try {
                return $holiday->status === HolidayStatus::Active;
            } catch (ValueError $exception) {
                throw new InvalidHolidayCalendarConfigurationException($employeeId, $localDate, 'invalid_holiday_status', $exception);
            }
        });

        if ($activeHolidays->count() > 1) {
            throw new InvalidHolidayCalendarConfigurationException($employeeId, $localDate, 'duplicate_active_holidays');
        }

        $holiday = $activeHolidays->first();
        if ($holiday) {
            try {
                $holiday->type;
            } catch (ValueError $exception) {
                throw new InvalidHolidayCalendarConfigurationException($employeeId, $localDate, 'invalid_holiday_type', $exception);
            }
        }

        return new ResolvedHoliday($resolvedEmployee, $localDate, $assignment, $calendar, $holiday);
    }

    private function localDate(DateTimeInterface|string $date, string $timezone, int $employeeId): CarbonImmutable
    {
        try {
            new DateTimeZone($timezone);
        } catch (Throwable $exception) {
            throw new InvalidHolidayCalendarConfigurationException(
                $employeeId,
                CarbonImmutable::now(config('app.timezone', 'UTC'))->startOfDay(),
                'invalid_company_timezone',
                $exception,
            );
        }

        try {
            // DATE inputs are business calendar dates: preserve their date component without timezone conversion.
            $dateString = $date instanceof DateTimeInterface
                ? $date->format('Y-m-d')
                : CarbonImmutable::parse($date, $timezone)->toDateString();

            return CarbonImmutable::parse($dateString, $timezone)->startOfDay();
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('The resolution date must be valid.', previous: $exception);
        }
    }
}
