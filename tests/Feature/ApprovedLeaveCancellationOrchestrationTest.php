<?php

use App\Actions\LeaveRequests\ApproveLeaveRequest;
use App\Actions\LeaveRequests\CancelApprovedLeaveAndSynchronizeAttendance;
use App\Actions\SeedCompanyRoles;
use App\Enums\AttendancePolicyStatus;
use App\Enums\CalculatedAttendanceStatus;
use App\Enums\LeaveBalanceAccountStatus;
use App\Enums\LeaveLedgerTransactionType;
use App\Enums\LeaveRequestDayType;
use App\Enums\LeaveRequestStatus;
use App\Enums\WorkScheduleDayType;
use App\Models\AttendancePolicy;
use App\Models\Company;
use App\Models\CompanyUser;
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
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleDay;
use App\Services\Attendance\DailyAttendanceMaterializer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

/** @return array{User, Company, Employee, LeaveRequest, EmployeeLeaveBalanceAccount, Shift, User} */
function cancellationOrchestrationFixture(string $role = 'hr_admin', bool $approve = true, bool $halfDay = false): array
{
    $company = Company::factory()->create(['timezone' => 'Asia/Kolkata']);
    $actor = User::factory()->create();
    CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($company);
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($company->id);
    $actor->assignRole($role);
    $registrar->setPermissionsTeamId(null);

    $employee = Employee::factory()->create(['company_id' => $company->id]);
    $requester = User::factory()->create();
    $type = LeaveType::factory()->create(['company_id' => $company->id]);
    $policy = LeavePolicy::factory()->create([
        'company_id' => $company->id,
        'leave_type_id' => $type->id,
        'max_balance_days' => '10.00',
        'minimum_request_days' => '0.50',
    ]);
    $assignment = EmployeeLeavePolicyAssignment::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'leave_policy_id' => $policy->id,
        'effective_from' => '2026-01-01',
        'effective_to' => '2026-12-31',
    ]);
    $account = EmployeeLeaveBalanceAccount::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'leave_policy_id' => $policy->id,
        'employee_leave_policy_assignment_id' => $assignment->id,
        'period_start' => '2026-01-01',
        'period_end' => '2026-12-31',
        'status' => LeaveBalanceAccountStatus::Open,
    ]);
    $account->ledgerEntries()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'leave_policy_id' => $policy->id,
        'employee_leave_policy_assignment_id' => $assignment->id,
        'transaction_type' => LeaveLedgerTransactionType::OpeningBalance,
        'amount' => '10.00',
        'effective_date' => '2026-01-01',
        'description' => 'Orchestration test opening balance',
        'created_by' => $actor->id,
        'created_at' => now(),
    ]);

    $schedule = WorkSchedule::factory()->create(['company_id' => $company->id]);
    $shift = $company->shifts()->create([
        'name' => 'Cancellation orchestration shift',
        'code' => 'CO-'.$company->id,
        'start_time' => '09:00',
        'end_time' => '18:00',
        'break_duration_minutes' => 60,
        'grace_period_minutes' => 0,
        'is_overnight' => false,
        'status' => 'active',
    ]);
    WorkScheduleDay::query()->where('work_schedule_id', $schedule->id)->where('day_of_week', 1)
        ->update(['day_type' => WorkScheduleDayType::Working->value, 'shift_id' => $shift->id]);
    EmployeeWorkScheduleAssignment::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'work_schedule_id' => $schedule->id,
        'effective_from' => '2026-01-01',
    ]);
    $attendancePolicy = AttendancePolicy::factory()->create([
        'company_id' => $company->id,
        'status' => AttendancePolicyStatus::Active,
    ]);
    EmployeeAttendancePolicyAssignment::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_policy_id' => $attendancePolicy->id,
        'effective_from' => '2026-01-01',
    ]);

    $request = LeaveRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'leave_policy_id' => $policy->id,
        'employee_leave_policy_assignment_id' => $assignment->id,
        'leave_balance_account_id' => $account->id,
        'start_date' => '2026-10-05',
        'end_date' => '2026-10-05',
        'requested_days' => $halfDay ? '0.50' : '1.00',
        'duration' => $halfDay ? 'half_day' : 'full_day',
        'status' => LeaveRequestStatus::Pending,
        'reason' => 'Personal leave request',
        'requested_by' => $requester->id,
        'requested_at' => now(),
    ]);
    $request->days()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_date' => '2026-10-05',
        'day_type' => $halfDay ? LeaveRequestDayType::HalfDay : LeaveRequestDayType::FullDay,
        'requested_amount' => $halfDay ? '0.50' : '1.00',
    ]);

    if ($approve) {
        $approver = $actor;
        if (! in_array($role, ['company_owner', 'hr_admin', 'hr_manager'], true)) {
            $approver = User::factory()->create();
            CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $approver->id, 'status' => 'active']);
            $registrar->setPermissionsTeamId($company->id);
            $approver->assignRole('hr_admin');
            $registrar->setPermissionsTeamId(null);
        }

        app(ApproveLeaveRequest::class)->handle($request, $approver, $company, 'Approved by HR');
    }

    return [$actor, $company, $employee, $request->fresh(), $account, $shift, $requester];
}

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 12:00:00', 'Asia/Kolkata'));
    config()->set('inertia.testing.page_paths', [resource_path('js/pages')]);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

test('authorized approved cancellation commits reversal then synchronizes attendance and preserves both audits', function () {
    [$actor, $company, $employee, $request, $account] = cancellationOrchestrationFixture();
    $day = $request->days()->firstOrFail()->getAttributes();
    $historyCount = DailyAttendanceRecordHistory::query()->count();

    $response = $this->actingAs($actor)->from(route('companies.leave-requests.show', [$company, $request]))
        ->post(route('companies.leave-requests.cancel-approved', [$company, $request]), [
            'cancellation_reason' => '  No longer required  ',
        ]);

    $response->assertRedirect(route('companies.leave-requests.show', [$company, $request]))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Approved leave cancelled successfully and attendance synchronized.');
    $request->refresh();
    $record = DailyAttendanceRecord::query()->where('employee_id', $employee->id)->whereDate('attendance_date', '2026-10-05')->firstOrFail();
    $this->assertDatabaseCount('leave_ledger_entries', 3);
    expect($request->status)->toBe(LeaveRequestStatus::Cancelled)
        ->and($request->cancellation_reason)->toBe('No longer required')
        ->and($request->review_note)->toBe('Approved by HR')
        ->and($request->reviewed_by)->toBe($actor->id)
        ->and($request->reviewed_at)->not->toBeNull()
        ->and($request->cancelled_by)->toBe($actor->id)
        ->and($request->cancelled_at)->not->toBeNull()
        ->and($record->status)->toBe(CalculatedAttendanceStatus::Absent)
        ->and($record->leave_request_id)->toBeNull()
        ->and($record->histories()->count())->toBe($historyCount + 1)
        ->and($request->days()->firstOrFail()->getAttributes())->toBe($day)
        ->and($account->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::LeaveReversed->value)->count())->toBe(1);

    $historyAfterCancellation = $record->histories()->count();
    $this->actingAs($actor)->from(route('companies.leave-requests.show', [$company, $request]))
        ->post(route('companies.leave-requests.cancel-approved', [$company, $request]), [
            'cancellation_reason' => 'Retry after a successful response was lost',
        ])->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success');
    expect($account->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::LeaveReversed->value)->count())->toBe(1)
        ->and($record->fresh()->histories()->count())->toBe($historyAfterCancellation)
        ->and($request->fresh()->cancellation_reason)->toBe('No longer required');

    $this->actingAs($actor)->get(route('companies.leave-requests.show', [$company, $request]))
        ->assertInertia(fn (Assert $page) => $page->component('leave-requests/show')
            ->where('leaveRequest.status', 'cancelled')
            ->where('canCancelApproved', false)
            ->where('leaveRequest.cancellationReason', 'No longer required')
            ->where('leaveRequest.reviewNote', 'Approved by HR')
            ->where('flash.success', 'Approved leave cancelled successfully and attendance synchronized.'));
});

test('approval rematerializes an existing attendance record from the approved leave snapshot', function () {
    [$actor, $company, $employee, $request] = cancellationOrchestrationFixture('hr_admin', false);
    $date = CarbonImmutable::parse('2026-10-05', 'Asia/Kolkata');

    $beforeApproval = app(DailyAttendanceMaterializer::class)->materialize($employee, $date);
    expect($beforeApproval->status)->toBe(CalculatedAttendanceStatus::Absent)
        ->and($beforeApproval->leave_request_id)->toBeNull();

    app(ApproveLeaveRequest::class)->handle($request, $actor, $company);

    $afterApproval = DailyAttendanceRecord::query()->where('company_id', $company->id)
        ->where('employee_id', $employee->id)->whereDate('attendance_date', '2026-10-05')->firstOrFail();

    expect($afterApproval->status)->toBe(CalculatedAttendanceStatus::OnLeave)
        ->and($afterApproval->leave_request_id)->toBe($request->id)
        ->and($afterApproval->leave_request_day_id)->toBe($request->days()->firstOrFail()->id)
        ->and($afterApproval->histories()->count())->toBe(2);
});

test('approval synchronizes the saved half-day amount without recalculating its leave snapshot', function () {
    [$actor, $company, $employee, $request] = cancellationOrchestrationFixture('hr_admin', false, true);
    $day = $request->days()->firstOrFail();
    $snapshot = [$day->leave_date->toDateString(), $day->day_type, $day->requested_amount];

    app(ApproveLeaveRequest::class)->handle($request, $actor, $company);

    $record = DailyAttendanceRecord::query()->where('company_id', $company->id)
        ->where('employee_id', $employee->id)->whereDate('attendance_date', $day->leave_date)->firstOrFail();
    $freshDay = $day->fresh();

    expect($record->leave_amount)->toBe('0.50')
        ->and($record->leave_request_id)->toBe($request->id)
        ->and($record->leave_request_day_id)->toBe($day->id)
        ->and($freshDay->leave_date->toDateString())->toBe($snapshot[0])
        ->and($freshDay->day_type)->toBe($snapshot[1])
        ->and($freshDay->requested_amount)->toBe($snapshot[2]);
});

test('approved cancellation validation rejects missing whitespace and overlong reasons', function (array $payload, string $error) {
    [$actor, $company, , $request] = cancellationOrchestrationFixture();

    $this->actingAs($actor)->post(route('companies.leave-requests.cancel-approved', [$company, $request]), $payload)
        ->assertSessionHasErrors($error);

    expect($request->fresh()->status)->toBe(LeaveRequestStatus::Approved)
        ->and($request->balanceAccount->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::LeaveReversed->value)->count())->toBe(0);
})->with([
    'missing' => [[], 'cancellation_reason'],
    'whitespace' => [['cancellation_reason' => '     '], 'cancellation_reason'],
    'overlong' => [['cancellation_reason' => str_repeat('x', 1001)], 'cancellation_reason'],
]);

test('cancellation capability is only exposed for authorized approved requests', function (string $status, bool $canCancel) {
    [$actor, $company, , $request] = cancellationOrchestrationFixture();
    DB::table('leave_requests')->where('id', $request->id)->update(['status' => $status]);

    $this->actingAs($actor)->get(route('companies.leave-requests.show', [$company, $request]))
        ->assertInertia(fn (Assert $page) => $page->where('canCancelApproved', $canCancel));
})->with([
    'approved' => [LeaveRequestStatus::Approved->value, true],
    'pending' => [LeaveRequestStatus::Pending->value, false],
    'rejected' => [LeaveRequestStatus::Rejected->value, false],
    'cancelled' => [LeaveRequestStatus::Cancelled->value, false],
]);

test('owner hr admin and hr manager can use the production cancellation workflow', function (string $role) {
    [$actor, $company, , $request] = cancellationOrchestrationFixture($role);

    $this->actingAs($actor)->post(route('companies.leave-requests.cancel-approved', [$company, $request]), [
        'cancellation_reason' => 'Approved cancellation requested by HR',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($request->fresh()->status)->toBe(LeaveRequestStatus::Cancelled);
})->with(['company_owner', 'hr_admin', 'hr_manager']);

test('unauthorized actors cannot cancel approved leave or reach the financial action', function () {
    [$actor, $company, , $request] = cancellationOrchestrationFixture('manager');

    $this->actingAs($actor)->post(route('companies.leave-requests.cancel-approved', [$company, $request]), [
        'cancellation_reason' => 'Unauthorized cancellation attempt',
    ])->assertForbidden();

    expect($request->fresh()->status)->toBe(LeaveRequestStatus::Approved)
        ->and($request->balanceAccount->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::LeaveReversed->value)->count())->toBe(0);
});

test('unauthorized actor does not receive approved cancellation capability on the detail page', function () {
    [$actor, $company, $employee, $request] = cancellationOrchestrationFixture('manager');
    $managerEmployee = Employee::factory()->create(['company_id' => $company->id, 'user_id' => $actor->id]);
    $employee->update(['manager_id' => $managerEmployee->id]);

    $this->actingAs($actor)->get(route('companies.leave-requests.show', [$company, $request]))
        ->assertInertia(fn (Assert $page) => $page->where('canCancelApproved', false));
});

test('requester cannot cancel their own approved request even with the hr permission', function () {
    [$actor, $company, $employee, $request, , , $requester] = cancellationOrchestrationFixture();
    $employee->update(['user_id' => $requester->id]);
    CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $requester->id, 'status' => 'active']);
    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $requester->assignRole('hr_admin');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $this->actingAs($requester)->post(route('companies.leave-requests.cancel-approved', [$company, $request]), [
        'cancellation_reason' => 'Self cancellation attempt',
    ])->assertForbidden();

    expect($request->fresh()->status)->toBe(LeaveRequestStatus::Approved)
        ->and($request->balanceAccount->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::LeaveReversed->value)->count())->toBe(0);
});

test('cross-company cancellation route is hidden and pending requests cannot use approved cancellation', function () {
    [$actorA, $companyA] = cancellationOrchestrationFixture();
    [$actorB, $companyB, , $requestB] = cancellationOrchestrationFixture('hr_admin', false);
    $pending = $requestB;

    $this->actingAs($actorA)->post(route('companies.leave-requests.cancel-approved', [$companyA, $requestB]), [
        'cancellation_reason' => 'Cross-company attempt',
    ])->assertNotFound();

    $this->actingAs($actorB)->post(route('companies.leave-requests.cancel-approved', [$companyB, $pending]), [
        'cancellation_reason' => 'Pending request attempt',
    ])->assertForbidden();

    expect($requestB->fresh()->status)->toBe(LeaveRequestStatus::Pending)
        ->and($requestB->balanceAccount->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::LeaveReversed->value)->count())->toBe(0);
});

test('attendance failure warns after committed cancellation and an HTTP retry safely completes synchronization', function () {
    [$actor, $company, $employee, $request, $account, $shift] = cancellationOrchestrationFixture();
    $recordBefore = DailyAttendanceRecord::query()->where('employee_id', $employee->id)->firstOrFail()->getAttributes();
    $showUrl = route('companies.leave-requests.show', [$company, $request]);
    $shift->update(['break_duration_minutes' => 540]);
    $daysBefore = $request->days()->firstOrFail()->getAttributes();

    $this->actingAs($actor)->from($showUrl)->post(route('companies.leave-requests.cancel-approved', [$company, $request]), [
        'cancellation_reason' => 'Approved leave cancelled after schedule issue',
    ])->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('warning');

    expect($request->fresh()->status)->toBe(LeaveRequestStatus::Cancelled)
        ->and($account->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::LeaveReversed->value)->count())->toBe(1)
        ->and(DailyAttendanceRecord::query()->where('employee_id', $employee->id)->firstOrFail()->getAttributes())->toBe($recordBefore)
        ->and($request->days()->firstOrFail()->getAttributes())->toBe($daysBefore);

    $shift->update(['break_duration_minutes' => 60]);
    $this->actingAs($actor)->from($showUrl)->post(route('companies.leave-requests.cancel-approved', [$company, $request]), [
        'cancellation_reason' => 'Retry after attendance schedule repair',
    ])->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success');

    $record = DailyAttendanceRecord::query()->where('employee_id', $employee->id)->whereDate('attendance_date', '2026-10-05')->firstOrFail();
    expect($request->fresh()->cancellation_reason)->toBe('Approved leave cancelled after schedule issue')
        ->and($account->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::LeaveReversed->value)->count())->toBe(1)
        ->and($record->status)->toBe(CalculatedAttendanceStatus::Absent)
        ->and($record->leave_request_id)->toBeNull()
        ->and($record->histories()->count())->toBe(2);
});

test('closed account and balance cap failures leave approval intact and do not synchronize attendance', function (string $failure) {
    [$actor, $company, $employee, $request, $account] = cancellationOrchestrationFixture();
    $recordBefore = DailyAttendanceRecord::query()->where('employee_id', $employee->id)->firstOrFail()->getAttributes();

    if ($failure === 'closed') {
        $account->update(['status' => LeaveBalanceAccountStatus::Closed]);
    } else {
        $account->ledgerEntries()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'leave_type_id' => $request->leave_type_id,
            'leave_policy_id' => $request->leave_policy_id,
            'employee_leave_policy_assignment_id' => $request->employee_leave_policy_assignment_id,
            'transaction_type' => LeaveLedgerTransactionType::Adjustment,
            'amount' => '1.00',
            'effective_date' => '2026-10-05',
            'description' => 'Fill cap before cancellation',
            'created_by' => $actor->id,
            'created_at' => now(),
        ]);
    }

    $this->actingAs($actor)->post(route('companies.leave-requests.cancel-approved', [$company, $request]), [
        'cancellation_reason' => 'Cannot proceed due to accounting restriction',
    ])->assertRedirect()->assertSessionHasErrors('leave_request');

    expect($request->fresh()->status)->toBe(LeaveRequestStatus::Approved)
        ->and($account->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::LeaveReversed->value)->count())->toBe(0)
        ->and(DailyAttendanceRecord::query()->where('employee_id', $employee->id)->firstOrFail()->getAttributes())->toBe($recordBefore);
})->with(['closed', 'balance cap']);

test('orchestrator distinguishes financial failure from attendance partial success', function () {
    [$actor, , , $request] = cancellationOrchestrationFixture();
    $result = app(CancelApprovedLeaveAndSynchronizeAttendance::class)->execute($request, $actor, 'Valid orchestration request');

    expect($result->financialCancellationSucceeded)->toBeTrue()
        ->and($result->attendanceSynchronizationAttempted)->toBeTrue()
        ->and($result->attendanceSynchronizationSucceeded)->toBeTrue()
        ->and($result->attendanceSynchronizationPartiallyFailed)->toBeFalse()
        ->and($result->attendanceFailedDates)->toBe([]);
});
