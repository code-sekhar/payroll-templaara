<?php

use App\Enums\AttendancePolicyStatus;
use App\Enums\AttendancePunchType;
use App\Enums\CalculatedAttendanceStatus;
use App\Enums\DailyAttendanceHistoryReason;
use App\Enums\DailyAttendanceRecordState;
use App\Enums\DayOfWeek;
use App\Enums\EmployeeAttendancePolicyAssignmentStatus;
use App\Enums\EmployeeWorkScheduleAssignmentStatus;
use App\Enums\HolidayCalendarStatus;
use App\Enums\HolidayStatus;
use App\Enums\HolidayType;
use App\Enums\LeaveRequestStatus;
use App\Enums\WorkScheduleDayType;
use App\Exceptions\Attendance\NoAttendancePolicyAssignmentException;
use App\Exceptions\WorkSchedules\InvalidWorkScheduleConfigurationException;
use App\Models\AttendancePolicy;
use App\Models\AttendancePunch;
use App\Models\Company;
use App\Models\DailyAttendanceRecord;
use App\Models\DailyAttendanceRecordHistory;
use App\Models\Employee;
use App\Models\EmployeeAttendancePolicyAssignment;
use App\Models\EmployeeHolidayCalendarAssignment;
use App\Models\EmployeeLeaveBalanceAccount;
use App\Models\EmployeeLeavePolicyAssignment;
use App\Models\EmployeeWorkScheduleAssignment;
use App\Models\Holiday;
use App\Models\HolidayCalendar;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleDay;
use App\Services\Attendance\DailyAttendanceMaterializer;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** @return array{Company, Employee, WorkSchedule, Shift, AttendancePolicy, EmployeeWorkScheduleAssignment, EmployeeAttendancePolicyAssignment} */
function materializerFixture(
    bool $withSchedule = true,
    string $timezone = 'Asia/Kolkata',
    string $startTime = '09:00',
    string $endTime = '18:00',
    int $breakMinutes = 60,
    array $policyAttributes = [],
): array {
    $company = Company::factory()->create(['timezone' => $timezone]);
    $employee = Employee::factory()->create(['company_id' => $company->id]);
    $schedule = WorkSchedule::factory()->create(['company_id' => $company->id]);
    $shift = $company->shifts()->create([
        'name' => 'Materializer Shift',
        'code' => 'MAT-'.fake()->unique()->numerify('#####'),
        'start_time' => $startTime,
        'end_time' => $endTime,
        'break_duration_minutes' => $breakMinutes,
        'grace_period_minutes' => 0,
        'is_overnight' => Shift::isOvernightTime($startTime, $endTime),
        'status' => 'active',
    ]);
    $scheduleAssignment = null;

    if ($withSchedule) {
        $scheduleAssignment = EmployeeWorkScheduleAssignment::factory()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'work_schedule_id' => $schedule->id,
            'effective_from' => '2026-01-01',
            'status' => EmployeeWorkScheduleAssignmentStatus::Active,
        ]);
        WorkScheduleDay::query()->where('work_schedule_id', $schedule->id)
            ->where('day_of_week', DayOfWeek::Monday->value)
            ->update(['day_type' => WorkScheduleDayType::Working->value, 'shift_id' => $shift->id]);
    }

    $policy = AttendancePolicy::factory()->create(array_merge([
        'company_id' => $company->id,
        'status' => AttendancePolicyStatus::Active,
        'grace_in_minutes' => 0,
        'grace_out_minutes' => 0,
        'late_after_minutes' => 0,
        'early_leave_after_minutes' => 0,
        'half_day_before_minutes' => 0,
        'absent_before_minutes' => 0,
        'overtime_enabled' => false,
        'overtime_after_minutes' => 0,
    ], $policyAttributes));
    $policyAssignment = EmployeeAttendancePolicyAssignment::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_policy_id' => $policy->id,
        'effective_from' => '2026-01-01',
        'status' => EmployeeAttendancePolicyAssignmentStatus::Active,
    ]);

    return [$company, $employee, $schedule, $shift, $policy, $scheduleAssignment, $policyAssignment];
}

function materializerPunch(Employee $employee, AttendancePunchType $type, string $localTime): AttendancePunch
{
    $timezone = $employee->company->timezone;

    return AttendancePunch::factory()->create([
        'company_id' => $employee->company_id,
        'employee_id' => $employee->id,
        'punch_type' => $type,
        'punched_at' => CarbonImmutable::parse($localTime, $timezone)->utc(),
        'timezone' => $timezone,
    ]);
}

function materializeFixture(Employee $employee, ?User $actor = null): DailyAttendanceRecord
{
    return app(DailyAttendanceMaterializer::class)->materialize(
        $employee,
        CarbonImmutable::parse('2026-10-05', $employee->company->timezone),
        $actor,
    );
}

function materializerApprovedLeave(Employee $employee, string $amount = '1.00'): array
{
    $type = LeaveType::factory()->create(['company_id' => $employee->company_id, 'is_paid' => false]);
    $policy = LeavePolicy::factory()->create(['company_id' => $employee->company_id, 'leave_type_id' => $type->id]);
    $assignment = EmployeeLeavePolicyAssignment::factory()->create([
        'company_id' => $employee->company_id,
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'leave_policy_id' => $policy->id,
        'effective_from' => '2026-01-01',
    ]);
    $account = EmployeeLeaveBalanceAccount::factory()->create([
        'company_id' => $employee->company_id,
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'leave_policy_id' => $policy->id,
        'employee_leave_policy_assignment_id' => $assignment->id,
        'period_start' => '2026-01-01',
        'period_end' => '2026-12-31',
    ]);
    $request = LeaveRequest::factory()->approved()->create([
        'company_id' => $employee->company_id,
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'leave_policy_id' => $policy->id,
        'employee_leave_policy_assignment_id' => $assignment->id,
        'leave_balance_account_id' => $account->id,
        'start_date' => '2026-10-05',
        'end_date' => '2026-10-05',
        'requested_days' => $amount,
        'duration' => $amount === '0.50' ? 'half_day' : 'full_day',
    ]);

    return [$request, $request->days()->sole(), $type, $account];
}

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 12:00:00', 'Asia/Kolkata'));
});

test('materialization persists the calculator snapshot and its initial history', function () {
    [, $employee, $schedule, $shift, $policy, $scheduleAssignment, $policyAssignment] = materializerFixture();
    materializerPunch($employee, AttendancePunchType::CheckIn, '2026-10-05 09:01');
    materializerPunch($employee, AttendancePunchType::CheckOut, '2026-10-05 18:07');
    $actor = User::factory()->create();

    $record = materializeFixture($employee, $actor);
    $history = $record->histories()->sole();

    expect($record->attendance_date->toDateString())->toBe('2026-10-05')
        ->and($record->status)->toBe(CalculatedAttendanceStatus::Present)
        ->and($record->record_state)->toBe(DailyAttendanceRecordState::Final)
        ->and($record->expected_minutes)->toBe(480)
        ->and($record->worked_minutes)->toBe(546)
        ->and($record->late_minutes)->toBe(1)
        ->and($record->early_leave_minutes)->toBe(0)
        ->and($record->first_check_in_at->utc()->format('Y-m-d H:i'))->toBe('2026-10-05 03:31')
        ->and($record->last_check_out_at->utc()->format('Y-m-d H:i'))->toBe('2026-10-05 12:37')
        ->and($record->expected_start_at->utc()->format('Y-m-d H:i'))->toBe('2026-10-05 03:30')
        ->and($record->expected_end_at->utc()->format('Y-m-d H:i'))->toBe('2026-10-05 12:30')
        ->and($record->work_schedule_id)->toBe($schedule->id)
        ->and($record->work_schedule_assignment_id)->toBe($scheduleAssignment->id)
        ->and($record->shift_id)->toBe($shift->id)
        ->and($record->attendance_policy_id)->toBe($policy->id)
        ->and($record->attendance_policy_assignment_id)->toBe($policyAssignment->id)
        ->and($record->calculation_version)->toBe(DailyAttendanceMaterializer::CALCULATION_VERSION)
        ->and($record->calculated_at)->not->toBeNull()
        ->and($record->finalized_at)->not->toBeNull()
        ->and($record->created_by)->toBe($actor->id)
        ->and($record->updated_by)->toBe($actor->id)
        ->and($history->change_reason)->toBe(DailyAttendanceHistoryReason::InitialMaterialization)
        ->and($history->actor_id)->toBe($actor->id)
        ->and($history->worked_minutes)->toBe($record->worked_minutes)
        ->and($history->status)->toBe($record->status);
});

test('unchanged materialization updates calculation metadata without duplicating rows or history', function () {
    [, $employee] = materializerFixture();
    materializerPunch($employee, AttendancePunchType::CheckIn, '2026-10-05 09:00');
    materializerPunch($employee, AttendancePunchType::CheckOut, '2026-10-05 18:00');
    $first = materializeFixture($employee);
    $originalCalculatedAt = $first->calculated_at;
    $this->travel(1)->minutes();

    $second = materializeFixture($employee);

    expect($second->id)->toBe($first->id)
        ->and(DailyAttendanceRecord::query()->count())->toBe(1)
        ->and(DailyAttendanceRecordHistory::query()->count())->toBe(1)
        ->and($second->finalized_at->equalTo($first->finalized_at))->toBeTrue()
        ->and($second->calculated_at->greaterThan($originalCalculatedAt))->toBeTrue();
});

test('provisional materialization becomes final on the same record after the day completes', function () {
    [, $employee] = materializerFixture();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 16:00:00', 'Asia/Kolkata'));
    materializerPunch($employee, AttendancePunchType::CheckIn, '2026-10-05 09:00');

    $provisional = materializeFixture($employee);
    $this->travelTo(CarbonImmutable::parse('2026-10-06 02:00:00', 'Asia/Kolkata'));
    materializerPunch($employee, AttendancePunchType::CheckOut, '2026-10-05 18:00');
    $final = materializeFixture($employee);

    expect($provisional->status)->toBe(CalculatedAttendanceStatus::InProgress)
        ->and($provisional->record_state)->toBe(DailyAttendanceRecordState::Provisional)
        ->and($provisional->finalized_at)->toBeNull()
        ->and($final->id)->toBe($provisional->id)
        ->and($final->status)->toBe(CalculatedAttendanceStatus::Present)
        ->and($final->record_state)->toBe(DailyAttendanceRecordState::Final)
        ->and($final->finalized_at)->not->toBeNull()
        ->and($final->histories()->count())->toBe(2);
});

test('incomplete attendance can become final after a later raw punch is appended', function () {
    [, $employee] = materializerFixture();
    materializerPunch($employee, AttendancePunchType::CheckIn, '2026-10-05 09:00');
    $incomplete = materializeFixture($employee);
    materializerPunch($employee, AttendancePunchType::CheckOut, '2026-10-05 18:00');

    $final = materializeFixture($employee);

    expect($incomplete->status)->toBe(CalculatedAttendanceStatus::Incomplete)
        ->and($incomplete->record_state)->toBe(DailyAttendanceRecordState::NeedsReview)
        ->and($final->id)->toBe($incomplete->id)
        ->and($final->status)->toBe(CalculatedAttendanceStatus::Present)
        ->and($final->record_state)->toBe(DailyAttendanceRecordState::Final)
        ->and($final->histories()->count())->toBe(2);
});

test('finalization is preserved when a final snapshot changes meaningfully', function () {
    [, $employee] = materializerFixture();
    materializerPunch($employee, AttendancePunchType::CheckIn, '2026-10-05 09:00');
    materializerPunch($employee, AttendancePunchType::CheckOut, '2026-10-05 18:00');
    $first = materializeFixture($employee);
    $firstFinalizedAt = $first->finalized_at;
    materializerPunch($employee, AttendancePunchType::CheckIn, '2026-10-05 19:00');
    materializerPunch($employee, AttendancePunchType::CheckOut, '2026-10-05 19:30');

    $recalculated = materializeFixture($employee);

    expect($recalculated->id)->toBe($first->id)
        ->and($recalculated->worked_minutes)->toBe(570)
        ->and($recalculated->finalized_at->equalTo($firstFinalizedAt))->toBeTrue()
        ->and($recalculated->histories()->count())->toBe(2)
        ->and($recalculated->histories()->latest('id')->first()->change_reason)->toBe(DailyAttendanceHistoryReason::Recalculation);
});

test('final records clear finalized time when recalculation needs review', function () {
    [, $employee, , , , $scheduleAssignment] = materializerFixture();
    materializerPunch($employee, AttendancePunchType::CheckIn, '2026-10-05 09:00');
    materializerPunch($employee, AttendancePunchType::CheckOut, '2026-10-05 18:00');
    $final = materializeFixture($employee);
    $scheduleAssignment->delete();

    $review = materializeFixture($employee);

    expect($review->id)->toBe($final->id)
        ->and($review->status)->toBe(CalculatedAttendanceStatus::NoSchedule)
        ->and($review->record_state)->toBe(DailyAttendanceRecordState::NeedsReview)
        ->and($review->finalized_at)->toBeNull()
        ->and($review->histories()->count())->toBe(2);
});

test('final records can become provisional again when the requested date is future', function () {
    [, $employee] = materializerFixture();
    materializerPunch($employee, AttendancePunchType::CheckIn, '2026-10-05 09:00');
    materializerPunch($employee, AttendancePunchType::CheckOut, '2026-10-05 18:00');
    $final = materializeFixture($employee);
    $this->travelTo(CarbonImmutable::parse('2026-10-04 12:00:00', 'Asia/Kolkata'));

    $future = materializeFixture($employee);

    expect($future->id)->toBe($final->id)
        ->and($future->status)->toBe(CalculatedAttendanceStatus::NotYetDue)
        ->and($future->record_state)->toBe(DailyAttendanceRecordState::Provisional)
        ->and($future->finalized_at)->toBeNull()
        ->and($future->histories()->count())->toBe(2);
});

test('a future date and a no schedule date materialize in their distinct states', function () {
    [, $futureEmployee] = materializerFixture();
    $this->travelTo(CarbonImmutable::parse('2026-10-04 12:00:00', 'Asia/Kolkata'));
    $future = materializeFixture($futureEmployee);

    [, $noScheduleEmployee] = materializerFixture(withSchedule: false);
    $noSchedule = materializeFixture($noScheduleEmployee);

    expect($future->status)->toBe(CalculatedAttendanceStatus::NotYetDue)
        ->and($future->record_state)->toBe(DailyAttendanceRecordState::Provisional)
        ->and($noSchedule->status)->toBe(CalculatedAttendanceStatus::NoSchedule)
        ->and($noSchedule->record_state)->toBe(DailyAttendanceRecordState::NeedsReview)
        ->and($noSchedule->expected_minutes)->toBe(0);
});

test('incomplete and no schedule records never receive finalized timestamps', function () {
    [, $employee] = materializerFixture();
    materializerPunch($employee, AttendancePunchType::CheckIn, '2026-10-05 09:00');
    $incomplete = materializeFixture($employee);
    [, $noScheduleEmployee] = materializerFixture(withSchedule: false);
    $noSchedule = materializeFixture($noScheduleEmployee);

    expect($incomplete->status)->toBe(CalculatedAttendanceStatus::Incomplete)
        ->and($incomplete->finalized_at)->toBeNull()
        ->and($noSchedule->status)->toBe(CalculatedAttendanceStatus::NoSchedule)
        ->and($noSchedule->finalized_at)->toBeNull();
});

test('holiday and off-day outcomes are persisted as final with holiday references when applicable', function () {
    [, $employee, $schedule] = materializerFixture();
    WorkScheduleDay::query()->where('work_schedule_id', $schedule->id)
        ->where('day_of_week', DayOfWeek::Monday->value)
        ->update(['day_type' => WorkScheduleDayType::Off->value, 'shift_id' => null]);
    $calendar = HolidayCalendar::factory()->create([
        'company_id' => $employee->company_id,
        'year' => 2026,
        'status' => HolidayCalendarStatus::Active,
    ]);
    $assignment = EmployeeHolidayCalendarAssignment::factory()->create([
        'company_id' => $employee->company_id,
        'employee_id' => $employee->id,
        'holiday_calendar_id' => $calendar->id,
        'effective_from' => '2026-10-01',
        'status' => 'active',
    ]);
    $holiday = Holiday::factory()->create([
        'company_id' => $employee->company_id,
        'holiday_calendar_id' => $calendar->id,
        'date' => '2026-10-05',
        'name' => 'Company Holiday',
        'type' => HolidayType::Company,
        'status' => HolidayStatus::Active,
    ]);

    $record = materializeFixture($employee);

    expect($record->status)->toBe(CalculatedAttendanceStatus::Holiday)
        ->and($record->record_state)->toBe(DailyAttendanceRecordState::Final)
        ->and($record->holiday_calendar_id)->toBe($calendar->id)
        ->and($record->holiday_id)->toBe($holiday->id)
        ->and($assignment->company_id)->toBe($employee->company_id);
});

test('materialization preserves raw punches and stores an overnight attendance date and absolute instants', function () {
    [, $employee] = materializerFixture(startTime: '22:00', endTime: '06:00', breakMinutes: 0);
    $checkIn = materializerPunch($employee, AttendancePunchType::CheckIn, '2026-10-05 21:58');
    $checkOut = materializerPunch($employee, AttendancePunchType::CheckOut, '2026-10-06 06:04');
    $punchSnapshot = [$checkIn->fresh()->getAttributes(), $checkOut->fresh()->getAttributes()];

    $record = materializeFixture($employee);

    expect($record->attendance_date->toDateString())->toBe('2026-10-05')
        ->and($record->expected_start_at->utc()->format('Y-m-d H:i'))->toBe('2026-10-05 16:30')
        ->and($record->expected_end_at->utc()->format('Y-m-d H:i'))->toBe('2026-10-06 00:30')
        ->and($record->last_check_out_at->utc()->format('Y-m-d H:i'))->toBe('2026-10-06 00:34')
        ->and(AttendancePunch::query()->count())->toBe(2)
        ->and([$checkIn->fresh()->getAttributes(), $checkOut->fresh()->getAttributes()])->toBe($punchSnapshot);
});

test('materialization accepts a null actor and does not create audit history on failed calculation', function () {
    [, $employee] = materializerFixture();
    $record = materializeFixture($employee);
    [, $missingPolicyEmployee] = materializerFixture();
    $missingPolicyEmployee->attendancePolicyAssignments()->delete();

    expect($record->created_by)->toBeNull()
        ->and($record->updated_by)->toBeNull()
        ->and(fn () => materializeFixture($missingPolicyEmployee))->toThrow(NoAttendancePolicyAssignmentException::class)
        ->and(DailyAttendanceRecord::query()->count())->toBe(1)
        ->and(DailyAttendanceRecordHistory::query()->count())->toBe(1);
});

test('invalid schedule configuration creates neither a daily record nor a history row', function () {
    [, $employee, , $shift] = materializerFixture(breakMinutes: 540);
    $shift->update(['break_duration_minutes' => 540]);

    expect(fn () => materializeFixture($employee))->toThrow(InvalidWorkScheduleConfigurationException::class);

    expect(DailyAttendanceRecord::query()->count())->toBe(0)
        ->and(DailyAttendanceRecordHistory::query()->count())->toBe(0);
});

test('an absent threshold result exactly matches the calculator output', function () {
    [, $employee] = materializerFixture();

    $record = materializeFixture($employee);

    expect($record->status)->toBe(CalculatedAttendanceStatus::Absent)
        ->and($record->worked_minutes)->toBe(0)
        ->and($record->record_state)->toBe(DailyAttendanceRecordState::Final);
});

test('a second attendance record for the same employee date is rejected by the unique key', function () {
    [, $employee] = materializerFixture();
    materializeFixture($employee);

    expect(fn () => DailyAttendanceRecord::factory()->create([
        'company_id' => $employee->company_id,
        'employee_id' => $employee->id,
        'attendance_date' => '2026-10-05',
    ]))->toThrow(QueryException::class);
});

test('database constraints reject cross-company employees and snapshot references', function () {
    [, $employeeA] = materializerFixture();
    [, $employeeB, , $shiftB, , $scheduleAssignmentB] = materializerFixture();

    expect(fn () => DailyAttendanceRecord::factory()->create([
        'company_id' => $employeeA->company_id,
        'employee_id' => $employeeB->id,
    ]))->toThrow(QueryException::class);

    expect(fn () => DailyAttendanceRecord::factory()->create([
        'company_id' => $employeeA->company_id,
        'employee_id' => $employeeA->id,
        'shift_id' => $shiftB->id,
    ]))->toThrow(QueryException::class);

    expect(fn () => DailyAttendanceRecord::factory()->create([
        'company_id' => $employeeA->company_id,
        'employee_id' => $employeeA->id,
        'work_schedule_assignment_id' => $scheduleAssignmentB->id,
    ]))->toThrow(QueryException::class);

    [$foreignRequest, $foreignDay, $foreignType] = materializerApprovedLeave($employeeB);
    $record = materializeFixture($employeeA);
    expect(fn () => DB::table('daily_attendance_records')->where('id', $record->id)->update([
        'leave_request_id' => $foreignRequest->id,
        'leave_request_day_id' => $foreignDay->id,
        'leave_type_id' => $foreignType->id,
        'leave_amount' => '1.00',
        'leave_is_paid' => false,
    ]))->toThrow(QueryException::class);
});

test('history snapshots remain append-only and only meaningful changes create new entries', function () {
    [, $employee] = materializerFixture();
    materializerPunch($employee, AttendancePunchType::CheckIn, '2026-10-05 09:00');
    materializerPunch($employee, AttendancePunchType::CheckOut, '2026-10-05 18:00');
    $record = materializeFixture($employee);
    $this->travel(1)->minutes();
    $actor = User::factory()->create();
    materializeFixture($employee, $actor);

    expect($record->histories()->count())->toBe(1)
        ->and($record->fresh()->updated_by)->toBe($actor->id);

    DB::table('daily_attendance_records')->where('id', $record->id)->update(['worked_minutes' => 481]);
    $this->travel(1)->minutes();
    $recalculated = materializeFixture($employee);
    $history = $recalculated->histories()->latest('id')->first();

    expect($recalculated->histories()->count())->toBe(2)
        ->and($history->worked_minutes)->toBe($recalculated->worked_minutes)
        ->and($history->change_reason)->toBe(DailyAttendanceHistoryReason::Recalculation);
});

test('history rows reject direct model updates and deletes', function () {
    [, $employee] = materializerFixture();
    $record = materializeFixture($employee);
    $history = $record->histories()->firstOrFail();

    expect(fn () => $history->update(['worked_minutes' => 12]))
        ->toThrow(LogicException::class)
        ->and(fn () => $history->delete())->toThrow(LogicException::class);
});

test('history insertion failure rolls back the daily snapshot transaction', function () {
    [, $employee] = materializerFixture();
    DB::unprepared("CREATE TRIGGER abort_daily_attendance_history BEFORE INSERT ON daily_attendance_record_histories BEGIN SELECT RAISE(ABORT, 'history insert failed'); END");

    expect(fn () => materializeFixture($employee))->toThrow(QueryException::class);

    expect(DailyAttendanceRecord::query()->count())->toBe(0)
        ->and(DailyAttendanceRecordHistory::query()->count())->toBe(0);
});

test('daily record migrations include unique identity and tenant ownership constraints', function () {
    expect(Schema::hasTable('daily_attendance_records'))->toBeTrue()
        ->and(Schema::hasTable('daily_attendance_record_histories'))->toBeTrue()
        ->and(Schema::hasColumn('daily_attendance_records', 'attendance_date'))->toBeTrue()
        ->and(Schema::hasColumn('daily_attendance_records', 'finalized_at'))->toBeTrue()
        ->and(Schema::hasColumn('daily_attendance_record_histories', 'change_reason'))->toBeTrue()
        ->and(Schema::hasColumn('daily_attendance_records', 'leave_request_id'))->toBeTrue()
        ->and(Schema::hasColumn('daily_attendance_records', 'leave_amount'))->toBeTrue()
        ->and(Schema::hasColumn('daily_attendance_records', 'leave_is_paid'))->toBeTrue()
        ->and(Schema::hasColumn('daily_attendance_record_histories', 'leave_request_day_id'))->toBeTrue();
});

test('approved full-day leave materializes as final with exact paid context and history', function () {
    [, $employee] = materializerFixture();
    [$request, $day, $type] = materializerApprovedLeave($employee);

    $record = materializeFixture($employee);
    $history = $record->histories()->sole();

    expect($record->status)->toBe(CalculatedAttendanceStatus::OnLeave)
        ->and($record->record_state)->toBe(DailyAttendanceRecordState::Final)
        ->and($record->leave_request_id)->toBe($request->id)
        ->and($record->leave_request_day_id)->toBe($day->id)
        ->and($record->leave_type_id)->toBe($type->id)
        ->and($record->leave_amount)->toBe('1.00')
        ->and($record->leave_is_paid)->toBeFalse()
        ->and($record->leaveRequest?->is($request))->toBeTrue()
        ->and($record->leaveRequestDay?->is($day))->toBeTrue()
        ->and($record->leaveType?->is($type))->toBeTrue()
        ->and($history->leave_request_id)->toBe($request->id)
        ->and($history->leave_request_day_id)->toBe($day->id)
        ->and($history->leave_amount)->toBe('1.00')
        ->and($history->leave_is_paid)->toBeFalse();
});

test('partial leave materializes completed partial leave as final with exact amount', function () {
    [, $employee] = materializerFixture();
    [$request, $day] = materializerApprovedLeave($employee, '0.50');
    materializerPunch($employee, AttendancePunchType::CheckIn, '2026-10-05 09:00');
    materializerPunch($employee, AttendancePunchType::CheckOut, '2026-10-05 13:00');

    $record = materializeFixture($employee);

    expect($record->status)->toBe(CalculatedAttendanceStatus::PartialLeave)
        ->and($record->record_state)->toBe(DailyAttendanceRecordState::Final)
        ->and($record->leave_request_id)->toBe($request->id)
        ->and($record->leave_request_day_id)->toBe($day->id)
        ->and($record->leave_amount)->toBe('0.50')
        ->and($record->leave_is_paid)->toBeFalse();
});

test('half day and incomplete materializations retain partial leave snapshots', function (bool $withCheckout, CalculatedAttendanceStatus $status, DailyAttendanceRecordState $state) {
    [, $employee] = materializerFixture();
    [$request] = materializerApprovedLeave($employee, '0.50');
    materializerPunch($employee, AttendancePunchType::CheckIn, '2026-10-05 09:00');
    if ($withCheckout) {
        materializerPunch($employee, AttendancePunchType::CheckOut, '2026-10-05 11:00');
    }

    $record = materializeFixture($employee);

    expect($record->status)->toBe($status)
        ->and($record->record_state)->toBe($state)
        ->and($record->leave_request_id)->toBe($request->id)
        ->and($record->leave_amount)->toBe('0.50')
        ->and($record->leave_is_paid)->toBeFalse();
})->with([
    'insufficient completed work' => [true, CalculatedAttendanceStatus::HalfDay, DailyAttendanceRecordState::Final],
    'incomplete sequence' => [false, CalculatedAttendanceStatus::Incomplete, DailyAttendanceRecordState::NeedsReview],
]);

test('provisional partial leave materialization retains its leave snapshot', function () {
    [, $employee] = materializerFixture();
    [$request] = materializerApprovedLeave($employee, '0.50');
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00:00', 'Asia/Kolkata'));

    $record = materializeFixture($employee);

    expect($record->status)->toBe(CalculatedAttendanceStatus::InProgress)
        ->and($record->record_state)->toBe(DailyAttendanceRecordState::Provisional)
        ->and($record->leave_request_id)->toBe($request->id)
        ->and($record->leave_amount)->toBe('0.50');
});

test('ordinary results store null leave snapshots and recalculation clears stale leave references', function () {
    [, $employee] = materializerFixture();
    [$request] = materializerApprovedLeave($employee);
    $withLeave = materializeFixture($employee);
    expect($withLeave->leave_request_id)->toBe($request->id);

    DB::table('leave_requests')->where('id', $request->id)->update(['status' => LeaveRequestStatus::Pending->value]);
    $withoutLeave = materializeFixture($employee);

    expect($withoutLeave->id)->toBe($withLeave->id)
        ->and($withoutLeave->status)->toBe(CalculatedAttendanceStatus::Absent)
        ->and($withoutLeave->leave_request_id)->toBeNull()
        ->and($withoutLeave->leave_request_day_id)->toBeNull()
        ->and($withoutLeave->leave_type_id)->toBeNull()
        ->and($withoutLeave->leave_amount)->toBeNull()
        ->and($withoutLeave->leave_is_paid)->toBeNull()
        ->and($withoutLeave->histories()->count())->toBe(2);
});

test('absent transitions to on leave on the same record and unchanged reruns do not append history', function () {
    [, $employee] = materializerFixture();
    $absent = materializeFixture($employee);
    [$request] = materializerApprovedLeave($employee);
    $ledgerCount = $request->balanceAccount->ledgerEntries()->count();

    $onLeave = materializeFixture($employee);
    $historyCount = $onLeave->histories()->count();
    $rerun = materializeFixture($employee);

    expect($onLeave->id)->toBe($absent->id)
        ->and($onLeave->status)->toBe(CalculatedAttendanceStatus::OnLeave)
        ->and($onLeave->histories()->count())->toBe(2)
        ->and($rerun->histories()->count())->toBe($historyCount)
        ->and($request->fresh()->status)->toBe(LeaveRequestStatus::Approved)
        ->and($request->balanceAccount->ledgerEntries()->count())->toBe($ledgerCount);
});

test('paid flag changes are meaningful leave history changes', function () {
    [, $employee] = materializerFixture();
    [$request, , $type] = materializerApprovedLeave($employee);
    $first = materializeFixture($employee);
    $type->update(['is_paid' => true]);

    $second = materializeFixture($employee);

    expect($second->id)->toBe($first->id)
        ->and($second->leave_is_paid)->toBeTrue()
        ->and($second->histories()->count())->toBe(2)
        ->and($second->histories()->latest('id')->first()->leave_is_paid)->toBeTrue();
});

test('a changed approved leave reference and amount are meaningful history changes', function () {
    [, $employee] = materializerFixture();
    [$request] = materializerApprovedLeave($employee, '1.00');
    $date = CarbonImmutable::parse('2026-10-05', 'Asia/Kolkata');
    $materializer = app(DailyAttendanceMaterializer::class);
    $first = $materializer->materialize($employee, $date);

    DB::table('daily_attendance_records')->where('id', $first->id)->update([
        'leave_request_id' => null,
        'leave_request_day_id' => null,
        'leave_type_id' => null,
        'leave_amount' => '0.50',
        'leave_is_paid' => null,
    ]);

    $second = $materializer->materialize($employee, $date);

    expect($second->id)->toBe($first->id)
        ->and($second->leave_request_id)->toBe($request->id)
        ->and($second->leave_amount)->toBe('1.00')
        ->and($second->histories()->count())->toBe(2)
        ->and($second->histories()->latest('id')->first()->leave_amount)->toBe('1.00');
});
