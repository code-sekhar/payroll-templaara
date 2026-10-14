<?php

use App\Enums\AttendancePolicyStatus;
use App\Enums\DailyAttendanceRecordState;
use App\Enums\LeaveRequestDayType;
use App\Enums\LeaveRequestStatus;
use App\Enums\WorkScheduleDayType;
use App\Models\AttendancePolicy;
use App\Models\Company;
use App\Models\DailyAttendanceRecord;
use App\Models\DailyAttendanceRecordHistory;
use App\Models\Employee;
use App\Models\EmployeeAttendancePolicyAssignment;
use App\Models\EmployeeLeaveBalanceAccount;
use App\Models\EmployeeLeavePolicyAssignment;
use App\Models\EmployeeWorkScheduleAssignment;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleDay;
use Carbon\CarbonImmutable;

/** @return array{Company, Employee, LeaveRequest} */
function approvedLeaveCommandFixture(LeaveRequestStatus $requestStatus = LeaveRequestStatus::Approved, string $date = '2026-10-05'): array
{
    $nextDate = CarbonImmutable::parse($date)->addDay()->toDateString();
    $company = Company::factory()->create(['timezone' => 'Asia/Kolkata']);
    $employee = Employee::factory()->create(['company_id' => $company->id]);
    $schedule = WorkSchedule::factory()->create(['company_id' => $company->id]);
    $shift = $company->shifts()->create([
        'name' => 'Command shift',
        'code' => 'CMD-'.fake()->unique()->numerify('#####'),
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
    WorkScheduleDay::query()->where('work_schedule_id', $schedule->id)
        ->where('day_of_week', 1)->update(['day_type' => WorkScheduleDayType::Working->value, 'shift_id' => $shift->id]);

    $attendancePolicy = AttendancePolicy::factory()->create([
        'company_id' => $company->id,
        'status' => AttendancePolicyStatus::Active,
        'half_day_before_minutes' => 0,
        'absent_before_minutes' => 0,
    ]);
    EmployeeAttendancePolicyAssignment::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_policy_id' => $attendancePolicy->id,
        'effective_from' => '2026-01-01',
    ]);

    $type = LeaveType::factory()->create(['company_id' => $company->id]);
    $leavePolicy = LeavePolicy::factory()->create(['company_id' => $company->id, 'leave_type_id' => $type->id]);
    $leaveAssignment = EmployeeLeavePolicyAssignment::factory()->create([
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
        'employee_leave_policy_assignment_id' => $leaveAssignment->id,
        'period_start' => '2026-01-01',
        'period_end' => '2026-12-31',
    ]);

    $factory = LeaveRequest::factory();
    $factory = match ($requestStatus) {
        LeaveRequestStatus::Pending => $factory->pending(),
        LeaveRequestStatus::Rejected => $factory->rejected(),
        LeaveRequestStatus::Cancelled => $factory->cancelled(),
        LeaveRequestStatus::Approved => $factory->approved(),
    };
    $request = $factory->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'leave_policy_id' => $leavePolicy->id,
        'employee_leave_policy_assignment_id' => $leaveAssignment->id,
        'leave_balance_account_id' => $account->id,
        'start_date' => $date,
        'end_date' => $nextDate,
        'requested_days' => '1.00',
    ]);

    if ($requestStatus === LeaveRequestStatus::Approved) {
        $request->days()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'leave_date' => $nextDate,
            'day_type' => LeaveRequestDayType::Holiday,
            'requested_amount' => '0.00',
        ]);
    }

    return [$company, $employee, $request];
}

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 12:00:00', 'Asia/Kolkata'));
});

test('explicit command requires a valid existing approved request', function () {
    $this->artisan('attendance:materialize-leave')->assertExitCode(2);
    $this->artisan('attendance:materialize-leave', ['--request' => 999999])->assertExitCode(2);

    foreach ([LeaveRequestStatus::Pending, LeaveRequestStatus::Rejected, LeaveRequestStatus::Cancelled] as $status) {
        [, , $request] = approvedLeaveCommandFixture($status);
        $this->artisan('attendance:materialize-leave', ['--request' => $request->id])->assertExitCode(2);
        expect(DailyAttendanceRecord::query()->count())->toBe(0);
    }
});

test('explicit command materializes only chargeable days and is idempotent without leave mutation', function () {
    [, $employee, $request] = approvedLeaveCommandFixture();
    $zeroDay = $request->days()->whereDate('leave_date', '2026-10-06')->firstOrFail();
    $requestSnapshot = [
        $request->status->value,
        $request->reviewed_by,
        $request->reviewed_at?->toISOString(),
        $request->review_note,
        $request->requested_days,
    ];
    $daySnapshot = $request->days()->orderBy('leave_date')->get()->map(fn ($day): array => [
        $day->leave_date->toDateString(),
        $day->day_type->value,
        $day->requested_amount,
    ])->all();
    $ledgerCount = $request->balanceAccount->ledgerEntries()->count();

    $this->artisan('attendance:materialize-leave', ['--request' => $request->id])
        ->expectsOutputToContain('Processed: 1; Materialized: 1; Unchanged: 0; Skipped future: 0; Failed: 0.')
        ->expectsOutputToContain('Affected dates: 2026-10-05.')
        ->expectsOutputToContain('Skipped future dates: none.')
        ->assertExitCode(0);

    $record = DailyAttendanceRecord::query()->sole();
    $historyCount = DailyAttendanceRecordHistory::query()->count();

    $this->artisan('attendance:materialize-leave', ['--request' => $request->id])
        ->expectsOutputToContain('Processed: 1; Materialized: 0; Unchanged: 1; Skipped future: 0; Failed: 0.')
        ->assertExitCode(0);

    $freshRequest = $request->fresh();
    expect($record->fresh()->status->value)->toBe('on_leave')
        ->and(DailyAttendanceRecord::query()->count())->toBe(1)
        ->and(DailyAttendanceRecordHistory::query()->count())->toBe($historyCount)
        ->and(DailyAttendanceRecord::query()->whereDate('attendance_date', $zeroDay->leave_date)->count())->toBe(0)
        ->and([
            $freshRequest->status->value,
            $freshRequest->reviewed_by,
            $freshRequest->reviewed_at?->toISOString(),
            $freshRequest->review_note,
            $freshRequest->requested_days,
        ])->toBe($requestSnapshot)
        ->and($request->balanceAccount->ledgerEntries()->count())->toBe($ledgerCount)
        ->and($request->days()->orderBy('leave_date')->get()->map(fn ($day): array => [
            $day->leave_date->toDateString(),
            $day->day_type->value,
            $day->requested_amount,
        ])->all())->toBe($daySnapshot)
        ->and($record->record_state)->toBe(DailyAttendanceRecordState::Final)
        ->and($employee->exists)->toBeTrue();
});

test('explicit command skips future dates using the company timezone and reports them', function () {
    [, , $request] = approvedLeaveCommandFixture(LeaveRequestStatus::Approved, '2026-10-12');

    $this->artisan('attendance:materialize-leave', ['--request' => $request->id])
        ->expectsOutputToContain('Processed: 0; Materialized: 0; Unchanged: 0; Skipped future: 1; Failed: 0.')
        ->expectsOutputToContain('Skipped future dates: 2026-10-12.')
        ->assertExitCode(0);

    expect(DailyAttendanceRecord::query()->count())->toBe(0);
});

test('command reports a per-date failure and returns a failure exit code while leaving approved leave untouched', function () {
    [, , $request] = approvedLeaveCommandFixture();
    WorkScheduleDay::query()->where('work_schedule_id', EmployeeWorkScheduleAssignment::query()
        ->where('employee_id', $request->employee_id)->value('work_schedule_id'))
        ->where('day_of_week', 1)->update(['shift_id' => null]);
    $ledgerCount = $request->balanceAccount->ledgerEntries()->count();

    $this->artisan('attendance:materialize-leave', ['--request' => $request->id])
        ->expectsOutputToContain('Eligible dates: 1; Failed: 1 synchronization; no attendance changes were committed.')
        ->assertExitCode(1);

    expect(DailyAttendanceRecord::query()->count())->toBe(0)
        ->and($request->fresh()->status)->toBe(LeaveRequestStatus::Approved)
        ->and($request->balanceAccount->ledgerEntries()->count())->toBe($ledgerCount);
});
