<?php

namespace App\Services\WorkSchedules;

use App\Enums\DayOfWeek;
use App\Enums\WorkScheduleDayType;
use App\Exceptions\WorkSchedules\InvalidWorkScheduleConfigurationException;
use App\Exceptions\WorkSchedules\NoWorkScheduleAssignmentException;
use App\Models\Employee;
use App\Models\Shift;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use Throwable;

final class WorkScheduleResolver
{
    public function resolve(Employee $employee, DateTimeInterface|string $date): ResolvedWorkSchedule
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
        $assignment = $resolvedEmployee->workScheduleAssignmentForDate($localDate);

        if (! $assignment) {
            throw new NoWorkScheduleAssignmentException($employeeId, $localDate);
        }

        if ((int) $assignment->company_id !== $companyId) {
            throw new InvalidWorkScheduleConfigurationException($employeeId, $localDate, 'assignment_company_mismatch');
        }

        $schedule = $assignment->workSchedule;
        if (! $schedule || $schedule->trashed()) {
            throw new InvalidWorkScheduleConfigurationException($employeeId, $localDate, 'schedule_missing_or_archived');
        }
        if ((int) $schedule->company_id !== $companyId) {
            throw new InvalidWorkScheduleConfigurationException($employeeId, $localDate, 'schedule_company_mismatch');
        }

        $dayOfWeek = DayOfWeek::from($localDate->dayOfWeekIso);
        $days = $schedule->days()
            ->where('company_id', $companyId)
            ->where('day_of_week', $dayOfWeek->value)
            ->with(['shift' => fn (BelongsTo $query): BelongsTo => $query->where('company_id', $companyId)])
            ->get();

        if ($days->count() !== 1) {
            throw new InvalidWorkScheduleConfigurationException($employeeId, $localDate, $days->isEmpty() ? 'schedule_day_missing' : 'duplicate_schedule_day');
        }

        $scheduleDay = $days->first();
        if ((int) $scheduleDay->company_id !== $companyId) {
            throw new InvalidWorkScheduleConfigurationException($employeeId, $localDate, 'schedule_day_company_mismatch');
        }

        try {
            $dayType = $scheduleDay->day_type;
        } catch (\ValueError $exception) {
            throw new InvalidWorkScheduleConfigurationException($employeeId, $localDate, 'invalid_day_type', $exception);
        }

        if ($dayType === WorkScheduleDayType::Off) {
            if ($scheduleDay->shift_id !== null || $scheduleDay->shift !== null) {
                throw new InvalidWorkScheduleConfigurationException($employeeId, $localDate, 'off_day_has_shift');
            }

            return new ResolvedWorkSchedule(
                $resolvedEmployee, $localDate, $assignment, $schedule, $scheduleDay, $dayOfWeek,
                $dayType, null, null, null, 0,
            );
        }

        if ($scheduleDay->shift_id === null || ! $scheduleDay->shift) {
            throw new InvalidWorkScheduleConfigurationException($employeeId, $localDate, 'working_day_shift_missing');
        }

        $shift = $scheduleDay->shift;
        if ((int) $shift->company_id !== $companyId) {
            throw new InvalidWorkScheduleConfigurationException($employeeId, $localDate, 'shift_company_mismatch');
        }

        try {
            $startTime = $this->normalizeTime($shift->start_time);
            $endTime = $this->normalizeTime($shift->end_time);
            $isOvernight = Shift::isOvernightTime($startTime, $endTime);
            $durationMinutes = Shift::durationMinutesFor($startTime, $endTime);
            $breakMinutes = (int) $shift->break_duration_minutes;
            $expectedWorkingMinutes = $shift->net_duration_minutes;
        } catch (Throwable $exception) {
            throw new InvalidWorkScheduleConfigurationException($employeeId, $localDate, 'invalid_shift_timing', $exception);
        }

        if ($durationMinutes <= 0 || $breakMinutes < 0 || $breakMinutes > $durationMinutes || $expectedWorkingMinutes < 0) {
            throw new InvalidWorkScheduleConfigurationException($employeeId, $localDate, 'invalid_shift_duration');
        }
        if ($shift->is_overnight !== $isOvernight) {
            throw new InvalidWorkScheduleConfigurationException($employeeId, $localDate, 'shift_overnight_mismatch');
        }

        $expectedStart = CarbonImmutable::parse($localDate->toDateString().' '.$startTime, $timezone);
        $expectedEndDate = $isOvernight ? $localDate->addDay() : $localDate;
        $expectedEnd = CarbonImmutable::parse($expectedEndDate->toDateString().' '.$endTime, $timezone);

        return new ResolvedWorkSchedule(
            $resolvedEmployee, $localDate, $assignment, $schedule, $scheduleDay, $dayOfWeek,
            $dayType, $shift, $expectedStart, $expectedEnd, $expectedWorkingMinutes,
        );
    }

    private function localDate(DateTimeInterface|string $date, string $timezone, int $employeeId): CarbonImmutable
    {
        try {
            new DateTimeZone($timezone);
        } catch (Throwable $exception) {
            throw new InvalidWorkScheduleConfigurationException(
                $employeeId,
                CarbonImmutable::now(config('app.timezone', 'UTC'))->startOfDay(),
                'invalid_company_timezone',
                $exception,
            );
        }

        try {
            $localDate = $date instanceof DateTimeInterface
                ? CarbonImmutable::instance($date)->setTimezone($timezone)
                : CarbonImmutable::parse($date, $timezone);

            return $localDate->startOfDay();
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('The resolution date must be valid.', previous: $exception);
        }
    }

    private function normalizeTime(string $time): string
    {
        if (! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/D', $time)) {
            throw new InvalidArgumentException('Shift time must be a valid local time.');
        }

        return strlen($time) === 5 ? $time.':00' : $time;
    }
}
