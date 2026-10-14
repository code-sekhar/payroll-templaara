<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceDayStatus;
use App\Enums\CalculatedAttendanceStatus;
use App\Exceptions\Attendance\InvalidAttendancePolicyConfigurationException;
use App\Exceptions\WorkSchedules\InvalidWorkScheduleConfigurationException;
use App\Exceptions\WorkSchedules\NoWorkScheduleAssignmentException;
use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Services\WorkSchedules\ResolvedWorkSchedule;
use App\Services\WorkSchedules\WorkScheduleResolver;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Throwable;

final class DailyAttendanceCalculator
{
    private const LEAVE_AMOUNT_SCALE = 100;

    public function __construct(
        private readonly AttendanceDayResolver $attendanceDayResolver,
        private readonly AttendancePolicyResolver $attendancePolicyResolver,
        private readonly AttendancePunchPairer $punchPairer,
        private readonly WorkScheduleResolver $workScheduleResolver,
        private readonly EffectiveAttendancePunchReader $effectivePunchReader,
    ) {}

    public function calculate(Employee $employee, CarbonInterface $date): CalculatedDailyAttendance
    {
        $requestedDate = $this->normalizeDate($employee, $date);
        $resolvedDay = $this->attendanceDayResolver->resolve($employee, $requestedDate);
        $schedule = $resolvedDay->workSchedule();
        $attendanceDate = $resolvedDay->date();
        $now = CarbonImmutable::now($attendanceDate->getTimezone());
        $isFutureDate = $attendanceDate->toDateString() > $now->toDateString();
        $dayStatus = $resolvedDay->status();

        if ($dayStatus === AttendanceDayStatus::NoSchedule) {
            return $this->result(
                $resolvedDay,
                CalculatedAttendanceStatus::NoSchedule,
                null,
                $this->readPunchesForDay($employee, $attendanceDate, $schedule?->expectedStart, $schedule?->expectedEnd, $now, $isFutureDate),
            );
        }

        if ($dayStatus === AttendanceDayStatus::Holiday) {
            return $this->result(
                $resolvedDay,
                CalculatedAttendanceStatus::Holiday,
                null,
                $this->readPunchesForDay($employee, $attendanceDate, $schedule?->expectedStart, $schedule?->expectedEnd, $now, $isFutureDate),
            );
        }

        if ($dayStatus === AttendanceDayStatus::OffDay) {
            return $this->result(
                $resolvedDay,
                CalculatedAttendanceStatus::OffDay,
                null,
                $this->readPunchesForDay($employee, $attendanceDate, $schedule?->expectedStart, $schedule?->expectedEnd, $now, $isFutureDate),
            );
        }

        if ($dayStatus === AttendanceDayStatus::OnLeave) {
            $this->validateExpectedSchedule($employee, $attendanceDate, $schedule ?? throw new InvalidArgumentException('A full-day leave requires a resolved work schedule.'));
            $punches = $this->readPunchesForDay(
                $employee,
                $attendanceDate,
                $schedule->expectedStart,
                $schedule->expectedEnd,
                $now,
                $isFutureDate,
            );
            $pairing = $this->punchPairer->pair($punches->all());

            return $this->result(
                $resolvedDay,
                CalculatedAttendanceStatus::OnLeave,
                null,
                $punches,
                $pairing->workedMinutes(),
                $pairing->firstCheckIn(),
                $pairing->lastCheckOut(),
                punchPairs: $pairing->pairs,
                expectedMinutes: 0,
            );
        }

        if ($dayStatus !== AttendanceDayStatus::WorkingDay || ! $schedule) {
            throw new InvalidArgumentException('The attendance day resolver returned an unsupported day configuration.');
        }

        $this->validateExpectedSchedule($employee, $attendanceDate, $schedule);
        $resolvedPolicy = $this->attendancePolicyResolver->resolve($employee, $attendanceDate);
        $this->validateClassificationThresholds($employee, $attendanceDate, $resolvedPolicy);
        $approvedLeave = $resolvedDay->approvedLeave();
        $hasPartialApprovedLeave = $approvedLeave->hasApprovedLeave();
        $expectedMinutes = $hasPartialApprovedLeave
            ? intdiv(
                $schedule->expectedWorkingMinutes * (self::LEAVE_AMOUNT_SCALE - $approvedLeave->requestedAmountCents),
                self::LEAVE_AMOUNT_SCALE,
            )
            : $schedule->expectedWorkingMinutes;

        if ($isFutureDate) {
            return $this->result(
                $resolvedDay,
                CalculatedAttendanceStatus::NotYetDue,
                $resolvedPolicy,
                collect(),
                expectedMinutes: $expectedMinutes,
            );
        }

        $window = AttendancePunchWindow::forDate($attendanceDate, $schedule->expectedStart, $schedule->expectedEnd);
        $isCurrentDate = $attendanceDate->toDateString() === $now->toDateString();

        if ($isCurrentDate) {
            if ($now < $window->start) {
                return $this->result(
                    $resolvedDay,
                    CalculatedAttendanceStatus::InProgress,
                    $resolvedPolicy,
                    collect(),
                    expectedMinutes: $expectedMinutes,
                );
            }

            $window = $window->cappedAt($now);
        }

        $punches = $this->readPunches($employee, $attendanceDate, $window);
        [$punches, $hasAmbiguousPunch] = $this->removePunchesOwnedByAdjacentShifts($employee, $attendanceDate, $schedule, $punches);
        $pairing = $this->punchPairer->pair($punches->all());
        $workedMinutes = $pairing->workedMinutes();
        $firstCheckIn = $pairing->firstCheckIn();
        $lastCheckOut = $pairing->lastCheckOut();
        $finalizableAfter = $schedule->expectedEnd->addHours(AttendancePunchWindow::POST_SHIFT_BUFFER_HOURS);

        if ($isCurrentDate && $now->getTimestamp() < $finalizableAfter->getTimestamp()) {
            $status = $pairing->isMalformed || $hasAmbiguousPunch
                ? CalculatedAttendanceStatus::Incomplete
                : CalculatedAttendanceStatus::InProgress;

            return $this->result(
                $resolvedDay,
                $status,
                $resolvedPolicy,
                $punches,
                $workedMinutes,
                $firstCheckIn,
                $lastCheckOut,
                punchPairs: $pairing->pairs,
                expectedMinutes: $expectedMinutes,
            );
        }

        if ($punches->isEmpty()) {
            return $this->result(
                $resolvedDay,
                $hasPartialApprovedLeave ? CalculatedAttendanceStatus::Incomplete : CalculatedAttendanceStatus::Absent,
                $resolvedPolicy,
                $punches,
                expectedMinutes: $expectedMinutes,
            );
        }

        if (! $pairing->isComplete() || $hasAmbiguousPunch) {
            return $this->result(
                $resolvedDay,
                CalculatedAttendanceStatus::Incomplete,
                $resolvedPolicy,
                $punches,
                $workedMinutes,
                $firstCheckIn,
                $lastCheckOut,
                punchPairs: $pairing->pairs,
                expectedMinutes: $expectedMinutes,
            );
        }

        if ($hasPartialApprovedLeave) {
            return $this->result(
                $resolvedDay,
                $workedMinutes >= $expectedMinutes
                    ? CalculatedAttendanceStatus::PartialLeave
                    : CalculatedAttendanceStatus::HalfDay,
                $resolvedPolicy,
                $punches,
                $workedMinutes,
                $firstCheckIn,
                $lastCheckOut,
                punchPairs: $pairing->pairs,
                expectedMinutes: $expectedMinutes,
            );
        }

        $rawLateMinutes = $this->wholeMinutesBetween($schedule->expectedStart, $firstCheckIn);
        $lateThreshold = max($resolvedPolicy->graceInMinutes(), $resolvedPolicy->lateAfterMinutes());
        $isLate = $rawLateMinutes > $lateThreshold;
        $lateMinutes = $isLate ? $rawLateMinutes : 0;

        $rawEarlyLeaveMinutes = $this->wholeMinutesBetween($lastCheckOut, $schedule->expectedEnd);
        $earlyLeaveThreshold = max($resolvedPolicy->graceOutMinutes(), $resolvedPolicy->earlyLeaveAfterMinutes());
        $isEarlyLeave = $rawEarlyLeaveMinutes > $earlyLeaveThreshold;
        $earlyLeaveMinutes = $isEarlyLeave ? $rawEarlyLeaveMinutes : 0;

        $rawExtraMinutes = $this->wholeMinutesBetween($schedule->expectedEnd, $lastCheckOut);
        $overtimeMinutes = $resolvedPolicy->overtimeEnabled()
            && $rawExtraMinutes > $resolvedPolicy->overtimeAfterMinutes()
            ? $rawExtraMinutes
            : 0;

        return $this->result(
            $resolvedDay,
            $this->attendanceStatus($workedMinutes, $resolvedPolicy),
            $resolvedPolicy,
            $punches,
            $workedMinutes,
            $firstCheckIn,
            $lastCheckOut,
            $lateMinutes,
            $earlyLeaveMinutes,
            $overtimeMinutes,
            $isLate,
            $isEarlyLeave,
            $pairing->pairs,
            $expectedMinutes,
        );
    }

    private function normalizeDate(Employee $employee, CarbonInterface $date): CarbonImmutable
    {
        $employee->loadMissing('company');
        $timezone = $employee->company?->timezone ?: config('app.timezone', 'UTC');

        try {
            new DateTimeZone($timezone);
        } catch (Throwable) {
            // The domain resolvers own company-timezone configuration errors.
            return CarbonImmutable::instance($date);
        }

        return CarbonImmutable::parse($date->format('Y-m-d'), $timezone)->startOfDay();
    }

    private function validateExpectedSchedule(Employee $employee, CarbonImmutable $date, ResolvedWorkSchedule $schedule): void
    {
        if (! $schedule->expectedStart || ! $schedule->expectedEnd) {
            throw new InvalidWorkScheduleConfigurationException(
                (int) $employee->getKey(),
                $date,
                'expected_shift_boundary_missing',
            );
        }

        if ($schedule->expectedWorkingMinutes <= 0) {
            throw new InvalidWorkScheduleConfigurationException(
                (int) $employee->getKey(),
                $date,
                'expected_working_minutes_invalid',
            );
        }

        if ($schedule->expectedEnd->getTimestamp() <= $schedule->expectedStart->getTimestamp()) {
            throw new InvalidWorkScheduleConfigurationException(
                (int) $employee->getKey(),
                $date,
                'expected_shift_boundaries_invalid',
            );
        }
    }

    private function readPunches(
        Employee $employee,
        CarbonImmutable $attendanceDate,
        AttendancePunchWindow $window,
    ): Collection {
        return collect($this->effectivePunchReader->read($employee, $attendanceDate, $window));
    }

    /** @return Collection<int, AttendancePunch|EffectiveAttendancePunch> */
    private function readPunchesForDay(
        Employee $employee,
        CarbonImmutable $attendanceDate,
        ?CarbonImmutable $expectedStart,
        ?CarbonImmutable $expectedEnd,
        CarbonImmutable $now,
        bool $isFutureDate,
    ): Collection {
        if ($isFutureDate) {
            return collect();
        }

        $window = AttendancePunchWindow::forDate($attendanceDate, $expectedStart, $expectedEnd);

        if ($attendanceDate->toDateString() === $now->toDateString()) {
            if ($now < $window->start) {
                return collect();
            }

            $window = $window->cappedAt($now);
        }

        return $this->readPunches($employee, $attendanceDate, $window);
    }

    /**
     * @param  Collection<int, AttendancePunch|EffectiveAttendancePunch>  $punches
     * @return array{Collection<int, AttendancePunch|EffectiveAttendancePunch>, bool}
     */
    private function removePunchesOwnedByAdjacentShifts(
        Employee $employee,
        CarbonImmutable $attendanceDate,
        ResolvedWorkSchedule $schedule,
        Collection $punches,
    ): array {
        $currentWindow = AttendancePunchWindow::forDate($attendanceDate, $schedule->expectedStart, $schedule->expectedEnd);
        $adjacentSchedules = [];

        foreach ([$attendanceDate->subDay(), $attendanceDate->addDay()] as $adjacentDate) {
            try {
                $adjacentSchedule = $this->workScheduleResolver->resolve($employee, $adjacentDate);
            } catch (NoWorkScheduleAssignmentException) {
                continue;
            }

            if ($adjacentSchedule->expectedStart && $adjacentSchedule->expectedEnd) {
                $adjacentWindow = AttendancePunchWindow::forDate(
                    $adjacentSchedule->date,
                    $adjacentSchedule->expectedStart,
                    $adjacentSchedule->expectedEnd,
                );

                if ($currentWindow->overlaps($adjacentWindow)) {
                    $adjacentSchedules[] = $adjacentSchedule;
                }
            }
        }

        if ($adjacentSchedules === []) {
            return [$punches, false];
        }

        $hasAmbiguousPunch = false;
        $ownedPunches = $punches->reject(function (AttendancePunch|EffectiveAttendancePunch $punch) use ($currentWindow, $schedule, $adjacentSchedules, &$hasAmbiguousPunch): bool {
            $instant = $punch instanceof EffectiveAttendancePunch
                ? $punch->punched_at
                : CarbonImmutable::instance($punch->punched_at);
            $currentDistance = $this->distanceFromShift($instant, $schedule);
            $closestAdjacentDistance = null;

            foreach ($adjacentSchedules as $adjacentSchedule) {
                $adjacentWindow = AttendancePunchWindow::forDate(
                    $adjacentSchedule->date,
                    $adjacentSchedule->expectedStart,
                    $adjacentSchedule->expectedEnd,
                );

                if (! $adjacentWindow->contains($instant)) {
                    continue;
                }

                $distance = $this->distanceFromShift($instant, $adjacentSchedule);
                $closestAdjacentDistance = $closestAdjacentDistance === null
                    ? $distance
                    : min($closestAdjacentDistance, $distance);
            }

            if ($closestAdjacentDistance === null || ! $currentWindow->contains($instant)) {
                return false;
            }

            if ($closestAdjacentDistance < $currentDistance) {
                return true;
            }

            if ($closestAdjacentDistance === $currentDistance) {
                $hasAmbiguousPunch = true;
            }

            return false;
        })->values();

        return [$ownedPunches, $hasAmbiguousPunch];
    }

    private function distanceFromShift(CarbonImmutable $instant, ResolvedWorkSchedule $schedule): int
    {
        if ($instant >= $schedule->expectedStart && $instant <= $schedule->expectedEnd) {
            return 0;
        }

        $boundary = $instant < $schedule->expectedStart ? $schedule->expectedStart : $schedule->expectedEnd;
        $first = min($instant->getTimestamp(), $boundary->getTimestamp());
        $last = max($instant->getTimestamp(), $boundary->getTimestamp());

        return (($last - $first) * 1_000_000)
            + ((int) $boundary->format('u') - (int) $instant->format('u'));
    }

    private function validateClassificationThresholds(
        Employee $employee,
        CarbonImmutable $date,
        ResolvedAttendancePolicy $policy,
    ): void {
        $halfDayThreshold = $policy->halfDayBeforeMinutes();

        if ($halfDayThreshold > 0 && $policy->absentBeforeMinutes() >= $halfDayThreshold) {
            throw new InvalidAttendancePolicyConfigurationException(
                (int) $employee->getKey(),
                $date,
                'invalid_attendance_classification_thresholds',
            );
        }
    }

    private function attendanceStatus(int $workedMinutes, ResolvedAttendancePolicy $policy): CalculatedAttendanceStatus
    {
        $halfDayThreshold = $policy->halfDayBeforeMinutes();

        if ($halfDayThreshold <= 0) {
            return $workedMinutes > 0 ? CalculatedAttendanceStatus::Present : CalculatedAttendanceStatus::Absent;
        }

        if ($workedMinutes <= $policy->absentBeforeMinutes()) {
            return CalculatedAttendanceStatus::Absent;
        }

        if ($workedMinutes < $halfDayThreshold) {
            return CalculatedAttendanceStatus::HalfDay;
        }

        return CalculatedAttendanceStatus::Present;
    }

    private function wholeMinutesBetween(?CarbonImmutable $start, ?CarbonImmutable $end): int
    {
        if (! $start || ! $end || $end <= $start) {
            return 0;
        }

        $elapsedSeconds = $end->getTimestamp() - $start->getTimestamp();
        $elapsedMicroseconds = ($elapsedSeconds * 1_000_000)
            + ((int) $end->format('u') - (int) $start->format('u'));

        return intdiv($elapsedMicroseconds, 60_000_000);
    }

    /** @param Collection<int, AttendancePunch|EffectiveAttendancePunch> $punches
     * @param  list<PunchPair>  $punchPairs
     */
    private function result(
        ResolvedAttendanceDay $resolvedDay,
        CalculatedAttendanceStatus $status,
        ?ResolvedAttendancePolicy $resolvedPolicy,
        Collection $punches,
        int $workedMinutes = 0,
        ?CarbonImmutable $firstCheckIn = null,
        ?CarbonImmutable $lastCheckOut = null,
        int $lateMinutes = 0,
        int $earlyLeaveMinutes = 0,
        int $overtimeMinutes = 0,
        bool $isLate = false,
        bool $isEarlyLeave = false,
        array $punchPairs = [],
        ?int $expectedMinutes = null,
    ): CalculatedDailyAttendance {
        $workSchedule = $resolvedDay->workSchedule();

        return new CalculatedDailyAttendance(
            $resolvedDay->employee(),
            $resolvedDay->date(),
            $status,
            $resolvedDay,
            $resolvedPolicy,
            $workSchedule?->expectedStart,
            $workSchedule?->expectedEnd,
            $expectedMinutes ?? $workSchedule?->expectedWorkingMinutes ?? 0,
            $firstCheckIn,
            $lastCheckOut,
            max(0, $workedMinutes),
            max(0, $lateMinutes),
            max(0, $earlyLeaveMinutes),
            max(0, $overtimeMinutes),
            $isLate,
            $isEarlyLeave,
            $punches->all(),
            $punchPairs,
        );
    }
}
