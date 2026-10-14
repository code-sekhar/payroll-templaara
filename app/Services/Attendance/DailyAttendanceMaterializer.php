<?php

namespace App\Services\Attendance;

use App\Enums\CalculatedAttendanceStatus;
use App\Enums\DailyAttendanceHistoryReason;
use App\Enums\DailyAttendanceRecordState;
use App\Models\DailyAttendanceRecord;
use App\Models\DailyAttendanceRecordHistory;
use App\Models\Employee;
use App\Models\User;
use App\Services\Leave\LeaveAmount;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use UnitEnum;

final class DailyAttendanceMaterializer
{
    public const CALCULATION_VERSION = 1;

    /** @var list<string> */
    private const MEANINGFUL_FIELDS = [
        'status', 'record_state', 'expected_start_at', 'expected_end_at', 'expected_minutes',
        'first_check_in_at', 'last_check_out_at', 'worked_minutes', 'late_minutes', 'early_leave_minutes',
        'overtime_minutes', 'is_late', 'is_early_leave', 'work_schedule_id', 'work_schedule_assignment_id',
        'shift_id', 'holiday_calendar_id', 'holiday_id', 'attendance_policy_id',
        'attendance_policy_assignment_id', 'leave_request_id', 'leave_request_day_id', 'leave_type_id',
        'leave_amount', 'leave_is_paid', 'calculation_version',
    ];

    public function __construct(private readonly DailyAttendanceCalculator $calculator) {}

    public function materialize(Employee $employee, CarbonInterface $date, ?User $actor = null): DailyAttendanceRecord
    {
        if (! $employee->exists || $employee->getKey() === null) {
            throw new InvalidArgumentException('A persisted employee is required to materialize attendance.');
        }

        return DB::transaction(function () use ($employee, $date, $actor): DailyAttendanceRecord {
            $lockedEmployee = Employee::query()
                ->where('company_id', $employee->company_id)
                ->whereKey($employee->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $calculation = $this->calculator->calculate($lockedEmployee, $date);
            $now = CarbonImmutable::now('UTC');
            $attributes = $this->attributesFromCalculation($calculation);
            $attributes['calculated_at'] = $now;
            $attributes['updated_by'] = $actor?->getKey();

            $record = DailyAttendanceRecord::query()
                ->where('company_id', $lockedEmployee->company_id)
                ->where('employee_id', $lockedEmployee->getKey())
                ->whereDate('attendance_date', $calculation->attendanceDate->toDateString())
                ->lockForUpdate()
                ->first();

            $isInitialMaterialization = $record === null;
            $hasMeaningfulChange = $isInitialMaterialization || $this->hasMeaningfulChange($record, $attributes);
            $previousState = $record?->record_state;
            $previousFinalizedAt = $record?->finalized_at;

            if ($isInitialMaterialization) {
                $record = new DailyAttendanceRecord([
                    ...$attributes,
                    'created_by' => $actor?->getKey(),
                ]);
            } else {
                $record->fill($attributes);
            }

            $newState = $attributes['record_state'];
            $record->finalized_at = $newState === DailyAttendanceRecordState::Final
                ? (($previousState === DailyAttendanceRecordState::Final && $previousFinalizedAt !== null)
                    ? $previousFinalizedAt
                    : $now)
                : null;
            $record->save();

            if ($hasMeaningfulChange) {
                $this->appendHistory(
                    $record,
                    $actor,
                    $isInitialMaterialization
                        ? DailyAttendanceHistoryReason::InitialMaterialization
                        : DailyAttendanceHistoryReason::Recalculation,
                );
            }

            return $record->refresh();
        });
    }

    /** @return array<string, mixed> */
    private function attributesFromCalculation(CalculatedDailyAttendance $calculation): array
    {
        $schedule = $calculation->resolvedAttendanceDay->workSchedule();
        $holiday = $calculation->resolvedAttendanceDay->holiday();
        $leave = $calculation->approvedLeave();
        $policy = $calculation->resolvedAttendancePolicy;

        return [
            'company_id' => $calculation->employee->company_id,
            'employee_id' => $calculation->employee->getKey(),
            'attendance_date' => $calculation->attendanceDate->toDateString(),
            'status' => $calculation->status,
            'record_state' => $this->recordStateFor($calculation->status),
            'expected_start_at' => $calculation->expectedStart?->utc(),
            'expected_end_at' => $calculation->expectedEnd?->utc(),
            'expected_minutes' => $calculation->expectedMinutes,
            'first_check_in_at' => $calculation->firstCheckIn?->utc(),
            'last_check_out_at' => $calculation->lastCheckOut?->utc(),
            'worked_minutes' => $calculation->workedMinutes,
            'late_minutes' => $calculation->lateMinutes,
            'early_leave_minutes' => $calculation->earlyLeaveMinutes,
            'overtime_minutes' => $calculation->overtimeMinutes,
            'is_late' => $calculation->isLate,
            'is_early_leave' => $calculation->isEarlyLeave,
            'work_schedule_id' => $schedule?->workSchedule->getKey(),
            'work_schedule_assignment_id' => $schedule?->assignment->getKey(),
            'shift_id' => $schedule?->shift?->getKey(),
            'holiday_calendar_id' => $holiday->calendar()?->getKey(),
            'holiday_id' => $holiday->holiday()?->getKey(),
            'attendance_policy_id' => $policy?->policy()->getKey(),
            'attendance_policy_assignment_id' => $policy?->assignment()->getKey(),
            'leave_request_id' => $leave->leaveRequest?->getKey(),
            'leave_request_day_id' => $leave->leaveRequestDay?->getKey(),
            'leave_type_id' => $leave->leaveType?->getKey(),
            'leave_amount' => $leave->hasApprovedLeave() ? $leave->requestedAmount : null,
            'leave_is_paid' => $leave->hasApprovedLeave() ? $leave->isPaid() : null,
            'calculation_version' => self::CALCULATION_VERSION,
        ];
    }

    private function recordStateFor(CalculatedAttendanceStatus $status): DailyAttendanceRecordState
    {
        return match ($status) {
            CalculatedAttendanceStatus::InProgress,
            CalculatedAttendanceStatus::NotYetDue => DailyAttendanceRecordState::Provisional,
            CalculatedAttendanceStatus::Incomplete,
            CalculatedAttendanceStatus::NoSchedule => DailyAttendanceRecordState::NeedsReview,
            CalculatedAttendanceStatus::Present,
            CalculatedAttendanceStatus::PartialLeave,
            CalculatedAttendanceStatus::OnLeave,
            CalculatedAttendanceStatus::HalfDay,
            CalculatedAttendanceStatus::Absent,
            CalculatedAttendanceStatus::Holiday,
            CalculatedAttendanceStatus::OffDay => DailyAttendanceRecordState::Final,
        };
    }

    /** @param array<string, mixed> $attributes */
    private function hasMeaningfulChange(DailyAttendanceRecord $record, array $attributes): bool
    {
        foreach (self::MEANINGFUL_FIELDS as $field) {
            if ($this->comparableValue($record->getAttribute($field), $field)
                !== $this->comparableValue($attributes[$field], $field)) {
                return true;
            }
        }

        return false;
    }

    private function comparableValue(mixed $value, string $field): mixed
    {
        if ($value instanceof UnitEnum) {
            return $value instanceof \BackedEnum ? $value->value : $value->name;
        }

        if ($value instanceof CarbonInterface || $value instanceof DateTimeInterface) {
            $date = CarbonImmutable::instance($value);

            return $field === 'attendance_date'
                ? $date->toDateString()
                : $date->utc()->format('Y-m-d H:i:s.u');
        }

        if (in_array($field, ['expected_minutes', 'worked_minutes', 'late_minutes', 'early_leave_minutes', 'overtime_minutes', 'calculation_version'], true) && $value !== null) {
            return (int) $value;
        }

        if ($field === 'leave_amount' && $value !== null) {
            return LeaveAmount::toCents((string) $value);
        }

        if (in_array($field, ['is_late', 'is_early_leave'], true)) {
            return (bool) $value;
        }

        return $value;
    }

    private function appendHistory(
        DailyAttendanceRecord $record,
        ?User $actor,
        DailyAttendanceHistoryReason $reason,
    ): void {
        DailyAttendanceRecordHistory::query()->create([
            'company_id' => $record->company_id,
            'daily_attendance_record_id' => $record->getKey(),
            'employee_id' => $record->employee_id,
            'attendance_date' => $record->attendance_date,
            'status' => $record->status,
            'record_state' => $record->record_state,
            'expected_start_at' => $record->expected_start_at,
            'expected_end_at' => $record->expected_end_at,
            'expected_minutes' => $record->expected_minutes,
            'first_check_in_at' => $record->first_check_in_at,
            'last_check_out_at' => $record->last_check_out_at,
            'worked_minutes' => $record->worked_minutes,
            'late_minutes' => $record->late_minutes,
            'early_leave_minutes' => $record->early_leave_minutes,
            'overtime_minutes' => $record->overtime_minutes,
            'is_late' => $record->is_late,
            'is_early_leave' => $record->is_early_leave,
            'leave_request_id' => $record->leave_request_id,
            'leave_request_day_id' => $record->leave_request_day_id,
            'leave_type_id' => $record->leave_type_id,
            'leave_amount' => $record->leave_amount,
            'leave_is_paid' => $record->leave_is_paid,
            'calculation_version' => $record->calculation_version,
            'calculated_at' => $record->calculated_at,
            'change_reason' => $reason,
            'actor_id' => $actor?->getKey(),
        ]);
    }
}
