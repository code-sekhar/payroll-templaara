<?php

use App\Actions\LeaveRequests\CancelApprovedLeaveRequest;
use App\Actions\LeaveRequests\RematerializeCancelledLeaveAttendance;
use App\Actions\SeedCompanyRoles;
use App\Enums\AttendancePolicyStatus;
use App\Enums\AttendancePunchType;
use App\Enums\CalculatedAttendanceStatus;
use App\Enums\DailyAttendanceRecordState;
use App\Enums\LeaveBalanceAccountStatus;
use App\Enums\LeaveLedgerTransactionType;
use App\Enums\LeaveRequestDayType;
use App\Enums\LeaveRequestStatus;
use App\Enums\WorkScheduleDayType;
use App\Exceptions\Leave\ApprovedLeaveCancellationException;
use App\Models\AttendancePolicy;
use App\Models\AttendancePunch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\DailyAttendanceRecord;
use App\Models\DailyAttendanceRecordHistory;
use App\Models\Employee;
use App\Models\EmployeeAttendancePolicyAssignment;
use App\Models\EmployeeLeaveBalanceAccount;
use App\Models\EmployeeLeavePolicyAssignment;
use App\Models\EmployeeWorkScheduleAssignment;
use App\Models\LeaveLedgerEntry;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleDay;
use App\Services\Attendance\DailyAttendanceMaterializer;
use App\Services\Leave\LeaveAmount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/** @param list<array{date: string, amount: string}> $days
 * @return array{Company, Employee, LeaveRequest, EmployeeLeaveBalanceAccount, User, LeavePolicy, LeaveType}
 */
function cancelledLeaveAttendanceFixture(
    array $days = [['date' => '2026-10-05', 'amount' => '1.00']],
    array $policyChanges = [],
    bool $cancelRequest = true,
): array {
    $company = Company::factory()->create(['timezone' => 'Asia/Kolkata']);
    $employee = Employee::factory()->create(['company_id' => $company->id]);
    $schedule = WorkSchedule::factory()->create(['company_id' => $company->id]);
    $shift = $company->shifts()->create([
        'name' => 'Cancellation test shift',
        'code' => 'CL-'.$company->id,
        'start_time' => '09:00',
        'end_time' => '18:00',
        'break_duration_minutes' => 60,
        'grace_period_minutes' => 0,
        'is_overnight' => false,
        'status' => 'active',
    ]);
    EmployeeWorkScheduleAssignment::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'work_schedule_id' => $schedule->id,
        'effective_from' => '2026-01-01',
    ]);
    foreach ($days as $day) {
        WorkScheduleDay::query()->where('work_schedule_id', $schedule->id)
            ->where('day_of_week', CarbonImmutable::parse($day['date'])->dayOfWeekIso)
            ->update(['day_type' => WorkScheduleDayType::Working->value, 'shift_id' => $shift->id]);
    }

    $attendancePolicy = AttendancePolicy::factory()->create(array_merge([
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
    ], $policyChanges));
    EmployeeAttendancePolicyAssignment::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_policy_id' => $attendancePolicy->id,
        'effective_from' => '2026-01-01',
    ]);

    $type = LeaveType::factory()->create(['company_id' => $company->id, 'is_paid' => false]);
    $leavePolicy = LeavePolicy::factory()->create(['company_id' => $company->id, 'leave_type_id' => $type->id]);
    $assignment = EmployeeLeavePolicyAssignment::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'leave_policy_id' => $leavePolicy->id,
        'effective_from' => '2026-01-01',
    ]);
    $account = EmployeeLeaveBalanceAccount::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'leave_policy_id' => $leavePolicy->id,
        'employee_leave_policy_assignment_id' => $assignment->id,
        'period_start' => '2026-01-01',
        'period_end' => '2026-12-31',
    ]);

    $actor = User::factory()->create();
    CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($company);
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($company->id);
    $actor->assignRole('hr_admin');
    $registrar->setPermissionsTeamId(null);
    $reviewer = User::factory()->create();
    $requester = User::factory()->create();

    $sortedDates = array_column($days, 'date');
    sort($sortedDates);
    $amountCents = array_sum(array_map(fn (array $day): int => LeaveAmount::toCents($day['amount']), $days));
    $request = LeaveRequest::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'leave_policy_id' => $leavePolicy->id,
        'employee_leave_policy_assignment_id' => $assignment->id,
        'leave_balance_account_id' => $account->id,
        'start_date' => min($sortedDates),
        'end_date' => max($sortedDates),
        'requested_days' => LeaveAmount::fromCents($amountCents),
        'status' => LeaveRequestStatus::Approved,
        'reviewed_by' => $reviewer->id,
        'reviewed_at' => now(),
        'requested_by' => $requester->id,
    ]);

    foreach ($days as $day) {
        $request->days()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'leave_date' => $day['date'],
            'day_type' => match ($day['amount']) {
                '0.00' => LeaveRequestDayType::Holiday,
                '0.50' => LeaveRequestDayType::HalfDay,
                default => LeaveRequestDayType::FullDay,
            },
            'requested_amount' => $day['amount'],
        ]);
    }

    $account->ledgerEntries()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'leave_policy_id' => $leavePolicy->id,
        'employee_leave_policy_assignment_id' => $assignment->id,
        'transaction_type' => LeaveLedgerTransactionType::LeaveUsed,
        'amount' => LeaveAmount::fromCents(-$amountCents),
        'effective_date' => min($sortedDates),
        'description' => "Approved leave request LR-{$request->id}",
        'reference_type' => LeaveRequest::class,
        'reference_id' => $request->id,
        'created_by' => $reviewer->id,
        'created_at' => now(),
    ]);

    if ($cancelRequest) {
        app(CancelApprovedLeaveRequest::class)->execute($request, $actor, 'Approved leave cancelled for testing');
    }

    return [$company, $employee, $request->fresh(), $account, $actor, $leavePolicy, $type];
}

function cancelledLeaveAttendanceMaterialize(Employee $employee, string $date): DailyAttendanceRecord
{
    return app(DailyAttendanceMaterializer::class)->materialize(
        $employee,
        CarbonImmutable::parse($date, $employee->company->timezone),
    );
}

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 12:00:00', 'Asia/Kolkata'));
});

test('cancelled full-day leave recalculates on leave to absent and history preserves old leave snapshot', function () {
    [, $employee, $request, , $actor] = cancelledLeaveAttendanceFixture([['date' => '2026-10-05', 'amount' => '1.00']], [], false);
    $record = cancelledLeaveAttendanceMaterialize($employee, '2026-10-05');
    $beforeHistoryCount = $record->histories()->count();
    app(CancelApprovedLeaveRequest::class)->execute($request, $actor, 'Approved leave cancelled for testing');

    $result = app(RematerializeCancelledLeaveAttendance::class)->execute($request);

    expect($result->failedDates)->toBe([]);

    $history = $record->fresh()->histories()->where('leave_request_id', $request->id)->firstOrFail();
    expect($record->fresh()->status)->toBe(CalculatedAttendanceStatus::Absent)
        ->and($record->record_state)->toBe(DailyAttendanceRecordState::Final)
        ->and($record->fresh()->leave_request_id)->toBeNull()
        ->and($record->fresh()->leave_request_day_id)->toBeNull()
        ->and($record->fresh()->leave_type_id)->toBeNull()
        ->and($record->fresh()->leave_amount)->toBeNull()
        ->and($record->fresh()->leave_is_paid)->toBeNull()
        ->and($history->status)->toBe(CalculatedAttendanceStatus::OnLeave)
        ->and($history->change_reason->value)->toBe('initial_materialization')
        ->and($history->leave_request_id)->toBe($request->id)
        ->and($history->leave_request_day_id)->toBe($request->days()->firstOrFail()->id)
        ->and($history->leave_type_id)->toBe($request->leave_type_id)
        ->and($history->leave_amount)->toBe('1.00')
        ->and($history->leave_is_paid)->toBeFalse()
        ->and($record->histories()->count())->toBe($beforeHistoryCount + 1)
        ->and($result->affectedDates)->toBe(['2026-10-05'])
        ->and($result->changedDates)->toBe(['2026-10-05'])
        ->and($result->failedDates)->toBe([]);
});

test('cancelled leave recalculates with existing punches and materializer idempotency prevents duplicate history', function () {
    [, $employee, $request, , $actor] = cancelledLeaveAttendanceFixture([['date' => '2026-10-05', 'amount' => '1.00']], [], false);
    AttendancePunch::factory()->create([
        'company_id' => $employee->company_id,
        'employee_id' => $employee->id,
        'punch_type' => AttendancePunchType::CheckIn,
        'punched_at' => CarbonImmutable::parse('2026-10-05 09:00', 'Asia/Kolkata')->utc(),
        'timezone' => 'Asia/Kolkata',
    ]);
    AttendancePunch::factory()->create([
        'company_id' => $employee->company_id,
        'employee_id' => $employee->id,
        'punch_type' => AttendancePunchType::CheckOut,
        'punched_at' => CarbonImmutable::parse('2026-10-05 18:00', 'Asia/Kolkata')->utc(),
        'timezone' => 'Asia/Kolkata',
    ]);
    $record = cancelledLeaveAttendanceMaterialize($employee, '2026-10-05');
    expect($record->status)->toBe(CalculatedAttendanceStatus::OnLeave);
    app(CancelApprovedLeaveRequest::class)->execute($request, $actor, 'Approved leave cancelled for testing');
    $punchSnapshots = AttendancePunch::query()->where('employee_id', $employee->id)->orderBy('id')->get()
        ->map(fn (AttendancePunch $punch): string => $punch->punched_at->toISOString())->all();

    $action = app(RematerializeCancelledLeaveAttendance::class);
    $first = $action->execute($request);
    expect($first->failedDates)->toBe([]);
    $historyCount = $record->histories()->count();
    $second = $action->execute($request);

    expect($record->fresh()->status)->toBe(CalculatedAttendanceStatus::Present)
        ->and($first->changedDates)->toBe(['2026-10-05'])
        ->and($second->unchangedDates)->toBe(['2026-10-05'])
        ->and($record->histories()->count())->toBe($historyCount)
        ->and(AttendancePunch::query()->where('employee_id', $employee->id)->orderBy('id')->get()
            ->map(fn (AttendancePunch $punch): string => $punch->punched_at->toISOString())->all())->toBe($punchSnapshots);
});

test('cancelled partial leave recalculates against full expected minutes', function () {
    [, $employee, $request, , $actor] = cancelledLeaveAttendanceFixture([['date' => '2026-10-05', 'amount' => '0.50']], [], false);
    foreach ([['check_in', '09:00'], ['check_out', '18:00']] as [$type, $time]) {
        AttendancePunch::factory()->create([
            'company_id' => $employee->company_id,
            'employee_id' => $employee->id,
            'punch_type' => $type,
            'punched_at' => CarbonImmutable::parse("2026-10-05 {$time}", 'Asia/Kolkata')->utc(),
            'timezone' => 'Asia/Kolkata',
        ]);
    }
    $record = cancelledLeaveAttendanceMaterialize($employee, '2026-10-05');
    expect($record->status)->toBe(CalculatedAttendanceStatus::PartialLeave);
    app(CancelApprovedLeaveRequest::class)->execute($request, $actor, 'Approved leave cancelled for testing');

    app(RematerializeCancelledLeaveAttendance::class)->execute($request);

    expect($record->fresh()->status)->toBe(CalculatedAttendanceStatus::Present)
        ->and($record->fresh()->expected_minutes)->toBe(480)
        ->and($record->fresh()->leave_amount)->toBeNull();
});

test('future and current affected dates keep the calculator provisional statuses', function () {
    [, $employee, $futureRequest] = cancelledLeaveAttendanceFixture([['date' => '2026-10-12', 'amount' => '1.00']]);
    WorkScheduleDay::query()->where('work_schedule_id', EmployeeWorkScheduleAssignment::query()
        ->where('employee_id', $employee->id)->value('work_schedule_id'))
        ->where('day_of_week', 1)->update([
            'day_type' => WorkScheduleDayType::Working->value,
            'shift_id' => Shift::query()->where('company_id', $employee->company_id)->value('id'),
        ]);
    app(RematerializeCancelledLeaveAttendance::class)->execute($futureRequest);
    $future = DailyAttendanceRecord::query()->where('employee_id', $employee->id)->whereDate('attendance_date', '2026-10-12')->firstOrFail();
    expect($future->status)->toBe(CalculatedAttendanceStatus::NotYetDue)
        ->and($future->record_state)->toBe(DailyAttendanceRecordState::Provisional);

    [, $currentEmployee, $currentRequest] = cancelledLeaveAttendanceFixture([['date' => '2026-10-10', 'amount' => '1.00']]);
    WorkScheduleDay::query()->where('work_schedule_id', EmployeeWorkScheduleAssignment::query()
        ->where('employee_id', $currentEmployee->id)->value('work_schedule_id'))
        ->where('day_of_week', 6)->update([
            'day_type' => WorkScheduleDayType::Working->value,
            'shift_id' => Shift::query()->where('company_id', $currentEmployee->company_id)->value('id'),
        ]);
    app(RematerializeCancelledLeaveAttendance::class)->execute($currentRequest);
    $current = DailyAttendanceRecord::query()->where('employee_id', $currentEmployee->id)->whereDate('attendance_date', '2026-10-10')->firstOrFail();
    expect($current->status)->toBe(CalculatedAttendanceStatus::InProgress)
        ->and($current->record_state)->toBe(DailyAttendanceRecordState::Provisional);
});

test('zero-charge snapshots are skipped and positive dates are processed chronologically', function () {
    [, $employee, $request] = cancelledLeaveAttendanceFixture([
        ['date' => '2026-10-06', 'amount' => '1.00'],
        ['date' => '2026-10-05', 'amount' => '1.00'],
        ['date' => '2026-10-07', 'amount' => '0.00'],
    ]);
    $result = app(RematerializeCancelledLeaveAttendance::class)->execute($request);

    expect($result->affectedDates)->toBe(['2026-10-05', '2026-10-06'])
        ->and($result->processedDates)->toBe(['2026-10-05', '2026-10-06'])
        ->and(DailyAttendanceRecord::query()->count())->toBe(2)
        ->and(DailyAttendanceRecord::query()->whereDate('attendance_date', '2026-10-07')->exists())->toBeFalse();
});

test('invalid request states and corrupt cancellation accounting fail before attendance changes', function (string $state) {
    [$company, $employee, $request, , $actor] = cancelledLeaveAttendanceFixture(
        [['date' => '2026-10-05', 'amount' => '1.00']],
        [],
        $state !== 'missing_reversal' && $state !== 'mismatched_reversal',
    );
    if ($state === 'approved') {
        DB::table('leave_requests')->where('id', $request->id)->update(['status' => LeaveRequestStatus::Approved->value]);
    } elseif ($state === 'rejected') {
        DB::table('leave_requests')->where('id', $request->id)->update(['status' => LeaveRequestStatus::Rejected->value]);
    } elseif (in_array($state, ['missing_reversal', 'mismatched_reversal'], true)) {
        DB::table('leave_requests')->where('id', $request->id)->update([
            'status' => LeaveRequestStatus::Cancelled->value,
            'cancelled_by' => $actor->id,
            'cancelled_at' => now(),
            'cancellation_reason' => 'Corrupt cancellation fixture',
        ]);
    }

    if ($state === 'mismatched_reversal') {
        $usage = $request->balanceAccount->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::LeaveUsed->value)->firstOrFail();
        $request->balanceAccount->ledgerEntries()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'leave_type_id' => $request->leave_type_id,
            'leave_policy_id' => $request->leave_policy_id,
            'employee_leave_policy_assignment_id' => $request->employee_leave_policy_assignment_id,
            'transaction_type' => LeaveLedgerTransactionType::LeaveReversed,
            'amount' => '0.50',
            'effective_date' => '2026-10-05',
            'description' => 'Corrupt test reversal',
            'reference_type' => LeaveLedgerEntry::class,
            'reference_id' => $usage->id,
            'created_at' => now(),
        ]);
    }

    try {
        app(RematerializeCancelledLeaveAttendance::class)->execute($request->fresh());
        test()->fail('Expected invalid cancellation state to fail.');
    } catch (ApprovedLeaveCancellationException $exception) {
        expect($exception->reason)->toBe(match ($state) {
            'approved', 'rejected' => 'APPROVED_LEAVE_CANCELLATION_REQUIRED',
            'missing_reversal' => 'CANCELLED_LEAVE_REVERSAL_MISSING',
            default => 'LEAVE_REVERSAL_AMOUNT_MISMATCH',
        });
    }

    expect(DailyAttendanceRecord::query()->count())->toBe(0);
})->with(['approved', 'rejected', 'missing_reversal', 'mismatched_reversal']);

test('closed historical balance period blocks synchronization safely', function () {
    [, , $request, $account] = cancelledLeaveAttendanceFixture();
    $account->update(['status' => LeaveBalanceAccountStatus::Closed]);

    try {
        app(RematerializeCancelledLeaveAttendance::class)->execute($request);
        test()->fail('Expected a closed leave period to block synchronization.');
    } catch (ApprovedLeaveCancellationException $exception) {
        expect($exception->reason)->toBe('LEAVE_BALANCE_PERIOD_CLOSED');
    }

    expect(DailyAttendanceRecord::query()->count())->toBe(0)
        ->and($account->fresh()->status)->toBe(LeaveBalanceAccountStatus::Closed);
});

test('per-date configuration failure is isolated and a later retry processes the failed date', function () {
    [, $employee, $request, , $actor] = cancelledLeaveAttendanceFixture([
        ['date' => '2026-10-06', 'amount' => '1.00'],
        ['date' => '2026-10-07', 'amount' => '0.00'],
        ['date' => '2026-10-08', 'amount' => '1.00'],
    ], [], false);
    $existing = cancelledLeaveAttendanceMaterialize($employee, '2026-10-08');
    $scheduleId = EmployeeWorkScheduleAssignment::query()->where('employee_id', $employee->id)->value('work_schedule_id');
    WorkScheduleDay::query()->where('work_schedule_id', $scheduleId)->where('day_of_week', 2)->update(['shift_id' => null]);
    expect(WorkScheduleDay::query()->where('work_schedule_id', $scheduleId)->where('day_of_week', 4)->value('shift_id'))->not->toBeNull()
        ->and(WorkScheduleDay::query()->where('work_schedule_id', $scheduleId)->where('day_of_week', 2)->value('shift_id'))->toBeNull();
    app(CancelApprovedLeaveRequest::class)->execute($request, $actor, 'Approved leave cancelled for testing');

    $action = app(RematerializeCancelledLeaveAttendance::class);
    $partial = $action->execute($request);

    expect($existing->fresh()->status)->toBe(CalculatedAttendanceStatus::Absent)
        ->and($partial->changedDates)->toBe(['2026-10-08'])
        ->and($partial->failedDates)->toHaveCount(1)
        ->and($partial->failedDates[0]['date'])->toBe('2026-10-06')
        ->and($request->fresh()->status)->toBe(LeaveRequestStatus::Cancelled);

    WorkScheduleDay::query()->where('work_schedule_id', $scheduleId)->where('day_of_week', 2)
        ->update(['shift_id' => Shift::query()->where('company_id', $employee->company_id)->value('id')]);
    $retry = $action->execute($request->fresh());

    expect($retry->unchangedDates)->toContain('2026-10-08')
        ->and($retry->changedDates)->toContain('2026-10-06')
        ->and($retry->failedDates)->toBe([]);
});

test('rematerialization changes attendance only and leaves cancelled leave accounting and snapshots untouched', function () {
    [, $employee, $request, $account] = cancelledLeaveAttendanceFixture();
    $daySnapshot = $request->days()->firstOrFail()->getAttributes();
    $requestSnapshot = [$request->status, $request->cancelled_by, $request->cancelled_at?->toISOString(), $request->cancellation_reason];
    $accountSnapshot = $account->fresh()->getAttributes();
    $ledgerSnapshot = $account->ledgerEntries()->orderBy('id')->get()->map(fn ($entry): array => $entry->getAttributes())->all();
    $attendanceHistory = DailyAttendanceRecordHistory::query()->count();

    app(RematerializeCancelledLeaveAttendance::class)->execute($request);

    $freshRequest = $request->fresh();
    expect([$freshRequest->status, $freshRequest->cancelled_by, $freshRequest->cancelled_at?->toISOString(), $freshRequest->cancellation_reason])->toBe($requestSnapshot)
        ->and($freshRequest->days()->firstOrFail()->getAttributes())->toBe($daySnapshot)
        ->and($account->fresh()->getAttributes())->toBe($accountSnapshot)
        ->and($account->ledgerEntries()->orderBy('id')->get()->map(fn ($entry): array => $entry->getAttributes())->all())->toBe($ledgerSnapshot)
        ->and(DailyAttendanceRecordHistory::query()->count())->toBeGreaterThanOrEqual($attendanceHistory);
});

test('rematerialization command supports retry and rejects unknown or inapplicable requests', function () {
    $this->artisan('attendance:rematerialize-cancelled-leave')->assertExitCode(2);
    $this->artisan('attendance:rematerialize-cancelled-leave', ['--request' => 999999])->assertExitCode(2);
    [, $employee, $request] = cancelledLeaveAttendanceFixture();

    $this->artisan('attendance:rematerialize-cancelled-leave', ['--request' => $request->id])
        ->expectsOutputToContain('Affected: 1; Processed: 1; Changed: 1; Unchanged: 0; Failed: 0.')
        ->assertExitCode(0);
    $this->artisan('attendance:rematerialize-cancelled-leave', ['--request' => $request->id])
        ->expectsOutputToContain('Affected: 1; Processed: 1; Changed: 0; Unchanged: 1; Failed: 0.')
        ->assertExitCode(0);

    DB::table('leave_requests')->where('id', $request->id)->update(['status' => LeaveRequestStatus::Pending->value]);
    $this->artisan('attendance:rematerialize-cancelled-leave', ['--request' => $request->id])->assertExitCode(2);
});

test('failed attendance date produces command exit one without undoing cancellation', function () {
    [, $employee, $request] = cancelledLeaveAttendanceFixture();
    $scheduleId = EmployeeWorkScheduleAssignment::query()->where('employee_id', $employee->id)->value('work_schedule_id');
    WorkScheduleDay::query()->where('work_schedule_id', $scheduleId)->where('day_of_week', 1)->update(['shift_id' => null]);

    $this->artisan('attendance:rematerialize-cancelled-leave', ['--request' => $request->id])
        ->expectsOutputToContain('Failed: 1.')
        ->assertExitCode(1);

    expect($request->fresh()->status)->toBe(LeaveRequestStatus::Cancelled)
        ->and($request->balanceAccount->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::LeaveReversed->value)->count())->toBe(1);
});
