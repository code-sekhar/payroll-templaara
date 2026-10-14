<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceRegularizationItemAction;
use App\Enums\AttendanceRegularizationStatus;
use App\Models\AttendancePunch;
use App\Models\AttendancePunchCorrection;
use App\Models\Employee;
use Carbon\CarbonImmutable;

/** Reads the bounded, approved effective punch stream without mutating raw evidence. */
final class EffectiveAttendancePunchReader
{
    /** @return list<AttendancePunch|EffectiveAttendancePunch> */
    public function read(Employee $employee, CarbonImmutable $attendanceDate, AttendancePunchWindow $window): array
    {
        $rawPunches = AttendancePunch::query()
            ->where('company_id', $employee->company_id)
            ->where('employee_id', $employee->getKey())
            ->where('punched_at', '>=', $window->start->utc());

        $rawPunches->where('punched_at', $window->includesEnd ? '<=' : '<', $window->end->utc());
        $rawEvents = $rawPunches->orderBy('punched_at')->orderBy('id')->get();

        $corrections = AttendancePunchCorrection::query()
            ->where('company_id', $employee->company_id)
            ->where('employee_id', $employee->getKey())
            ->whereDate('attendance_date', $attendanceDate->toDateString())
            ->whereHas('regularizationRequest', fn ($query) => $query->where('status', AttendanceRegularizationStatus::Approved->value))
            ->orderBy('id')
            ->get();

        $suppressedPunchIds = $corrections
            ->filter(fn (AttendancePunchCorrection $correction): bool => in_array($correction->action, [AttendanceRegularizationItemAction::Replace, AttendanceRegularizationItemAction::Remove], true))
            ->pluck('original_attendance_punch_id')->filter()->map(fn ($id): int => (int) $id)->all();

        $events = $rawEvents
            ->reject(fn (AttendancePunch $punch): bool => in_array((int) $punch->getKey(), $suppressedPunchIds, true))
            ->values()
            ->all();

        foreach ($corrections as $correction) {
            if ($correction->action === AttendanceRegularizationItemAction::Remove) {
                continue;
            }

            $instant = CarbonImmutable::instance($correction->effective_punched_at)->setTimezone($window->start->getTimezone());
            if ($window->contains($instant)) {
                $events[] = EffectiveAttendancePunch::fromCorrection($correction);
            }
        }

        usort($events, static function (AttendancePunch|EffectiveAttendancePunch $left, AttendancePunch|EffectiveAttendancePunch $right): int {
            $leftEvent = $left instanceof EffectiveAttendancePunch ? $left : EffectiveAttendancePunch::fromRaw($left);
            $rightEvent = $right instanceof EffectiveAttendancePunch ? $right : EffectiveAttendancePunch::fromRaw($right);

            return $leftEvent->deterministicSortKey() <=> $rightEvent->deterministicSortKey();
        });

        return $events;
    }
}
