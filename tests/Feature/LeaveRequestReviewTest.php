<?php

use App\Actions\LeaveBalances\PostLeaveLedgerEntry;
use App\Actions\LeaveRequests\ApproveLeaveRequest;
use App\Actions\SeedCompanyRoles;
use App\Enums\CalculatedAttendanceStatus;
use App\Enums\LeaveBalanceAccountStatus;
use App\Enums\LeaveLedgerTransactionType;
use App\Enums\LeavePolicyStatus;
use App\Enums\LeaveRequestDayType;
use App\Enums\LeaveRequestStatus;
use App\Enums\LeaveTypeStatus;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\DailyAttendanceRecord;
use App\Models\Employee;
use App\Models\EmployeeLeaveBalanceAccount;
use App\Models\EmployeeLeavePolicyAssignment;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Attendance\DailyAttendanceMaterializer;
use App\Services\Leave\ApprovedLeaveResolver;
use App\Services\Leave\LeaveAmount;
use App\Services\Leave\LeaveBalanceCalculator;
use App\Services\Leave\LeaveRequestReservationCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

/** @return array{User, Company, Employee} */
function leaveReviewActor(string $role = 'hr_admin'): array
{
    $user = User::factory()->create();
    $company = Company::factory()->create();
    CompanyUser::create(['company_id' => $company->id, 'user_id' => $user->id, 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($company);
    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $employee = Employee::factory()->create(['company_id' => $company->id]);

    return [$user, $company, $employee];
}

/** @return array{LeaveType, LeavePolicy, EmployeeLeavePolicyAssignment, EmployeeLeaveBalanceAccount} */
function leaveReviewConfiguration(Company $company, Employee $employee, User $actor, string $balance = '10.00', array $policyChanges = []): array
{
    $type = LeaveType::factory()->create(['company_id' => $company->id]);
    $policy = LeavePolicy::factory()->create(array_merge([
        'company_id' => $company->id,
        'leave_type_id' => $type->id,
        'allow_negative_balance' => false,
        'minimum_request_days' => '0.50',
    ], $policyChanges));
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
        'amount' => $balance,
        'effective_date' => '2026-01-01',
        'description' => 'Review test opening balance',
        'created_by' => $actor->id,
        'created_at' => now(),
    ]);

    return [$type, $policy, $assignment, $account];
}

/** @param list<string>|null $dates
 */
function leaveReviewPendingRequest(
    Company $company,
    Employee $employee,
    LeaveType $type,
    LeavePolicy $policy,
    EmployeeLeavePolicyAssignment $assignment,
    EmployeeLeaveBalanceAccount $account,
    User $requester,
    string $amount = '1.00',
    ?array $dates = null,
    ?array $dayAmounts = null,
): LeaveRequest {
    $dates ??= ['2026-10-05'];
    $request = LeaveRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'leave_policy_id' => $policy->id,
        'employee_leave_policy_assignment_id' => $assignment->id,
        'leave_balance_account_id' => $account->id,
        'start_date' => min($dates),
        'end_date' => max($dates),
        'requested_days' => $amount,
        'duration' => $amount === '0.50' ? 'half_day' : 'full_day',
        'status' => LeaveRequestStatus::Pending,
        'reason' => 'Personal leave request',
        'requested_by' => $requester->id,
        'requested_at' => now(),
    ]);

    $dayAmountCents = intdiv(LeaveAmount::toCents($amount), count($dates));
    foreach ($dates as $index => $date) {
        $thisCents = $dayAmounts[$index] ?? $dayAmountCents;
        $thisAmount = LeaveAmount::fromCents($thisCents);
        $request->days()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'leave_date' => $date,
            'day_type' => match ($thisCents) {
                0 => LeaveRequestDayType::Holiday,
                50 => LeaveRequestDayType::HalfDay,
                default => LeaveRequestDayType::FullDay,
            },
            'requested_amount' => $thisAmount,
        ]);
    }

    return $request->refresh();
}

function leaveReviewLinkEmployeePortal(Employee $employee, Company $company): User
{
    $user = User::factory()->create();
    CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $user->id, 'status' => 'active']);
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($company->id);
    $user->assignRole('employee');
    $registrar->setPermissionsTeamId(null);
    $employee->forceFill(['user_id' => $user->id])->save();

    return $user;
}

function leaveReviewFailDatabaseInsert(string $table): void
{
    if (! in_array($table, ['daily_attendance_records', 'daily_attendance_record_histories', 'notifications', 'leave_ledger_entries'], true)) {
        throw new InvalidArgumentException('Unsupported test failure table.');
    }

    DB::unprepared("CREATE TRIGGER leave_review_test_fail_insert BEFORE INSERT ON {$table} BEGIN SELECT RAISE(ABORT, 'forced test insert failure'); END");
}

beforeEach(function (): void {
    config()->set('inertia.testing.page_paths', [resource_path('js/pages')]);
});

test('authorized independent reviewer approves once and atomically appends exact leave usage with request reference', function () {
    [$reviewer, $company, $employee] = leaveReviewActor();
    $requester = User::factory()->create();
    [$type, $policy, $assignment, $account] = leaveReviewConfiguration($company, $employee, $reviewer, '10.00');
    $request = leaveReviewPendingRequest($company, $employee, $type, $policy, $assignment, $account, $requester, '1.50', ['2026-10-05', '2026-10-06'], [100, 50]);
    $daySnapshot = $request->days()->orderBy('leave_date')->pluck('requested_amount', 'leave_date')->all();
    $attendanceCount = DailyAttendanceRecord::query()->count();

    $this->actingAs($reviewer)->post(route('companies.leave-requests.approve', [$company, $request]), ['review_note' => 'Reviewed and approved'])->assertRedirect()->assertSessionHasNoErrors();

    $request->refresh();
    $entry = $account->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::LeaveUsed->value)->firstOrFail();
    expect($request->status)->toBe(LeaveRequestStatus::Approved)
        ->and($request->reviewed_by)->toBe($reviewer->id)
        ->and($request->reviewed_at)->not->toBeNull()
        ->and($request->review_note)->toBe('Reviewed and approved')
        ->and($entry->amount)->toBe('-1.50')
        ->and($entry->effective_date->toDateString())->toBe('2026-10-05')
        ->and($entry->reference_type)->toBe(LeaveRequest::class)
        ->and($entry->reference_id)->toBe($request->id)
        ->and($entry->created_by)->toBe($reviewer->id)
        ->and($entry->employee_id)->toBe($employee->id)
        ->and($entry->leave_type_id)->toBe($type->id)
        ->and($entry->leave_policy_id)->toBe($policy->id)
        ->and($entry->employee_leave_policy_assignment_id)->toBe($assignment->id)
        ->and($entry->account->is($account))->toBeTrue()
        ->and($account->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::LeaveUsed->value)->count())->toBe(1)
        ->and(app(LeaveBalanceCalculator::class)->calculate($account)->balance)->toBe('8.50')
        ->and(app(LeaveRequestReservationCalculator::class)->pendingCents($account))->toBe(0)
        ->and($request->days()->orderBy('leave_date')->pluck('requested_amount', 'leave_date')->all())->toBe($daySnapshot)
        ->and(DailyAttendanceRecord::query()->count())->toBe($attendanceCount + 2)
        ->and(DailyAttendanceRecord::query()->where('company_id', $company->id)->where('employee_id', $employee->id)
            ->orderBy('attendance_date')->pluck('status')->map(fn (CalculatedAttendanceStatus $status): string => $status->value)->all())
        ->toBe([CalculatedAttendanceStatus::NoSchedule->value, CalculatedAttendanceStatus::NoSchedule->value]);
});

test('rejection requires a review note releases reservation and leaves ledger and day snapshots untouched', function () {
    [$reviewer, $company, $employee] = leaveReviewActor();
    $requester = User::factory()->create();
    [$type, $policy, $assignment, $account] = leaveReviewConfiguration($company, $employee, $reviewer, '5.00');
    $request = leaveReviewPendingRequest($company, $employee, $type, $policy, $assignment, $account, $requester);
    $dayCount = $request->days()->count();
    $ledgerCount = $account->ledgerEntries()->count();
    $this->actingAs($reviewer)->post(route('companies.leave-requests.reject', [$company, $request]), [])->assertSessionHasErrors('review_note');

    $this->post(route('companies.leave-requests.reject', [$company, $request]), ['review_note' => 'Insufficient supporting details'])->assertRedirect();

    expect($request->fresh()->status)->toBe(LeaveRequestStatus::Rejected)
        ->and($request->fresh()->reviewed_by)->toBe($reviewer->id)
        ->and($request->fresh()->reviewed_at)->not->toBeNull()
        ->and($request->fresh()->review_note)->toBe('Insufficient supporting details')
        ->and($request->days()->count())->toBe($dayCount)
        ->and($account->ledgerEntries()->count())->toBe($ledgerCount)
        ->and(app(LeaveRequestReservationCalculator::class)->pendingCents($account))->toBe(0)
        ->and(app(LeaveBalanceCalculator::class)->calculate($account)->balance)->toBe('5.00');
});

test('attendance materialization failure rolls back approval and leave usage', function () {
    [$reviewer, $company, $employee] = leaveReviewActor();
    $requester = User::factory()->create();
    [$type, $policy, $assignment, $account] = leaveReviewConfiguration($company, $employee, $reviewer);
    $request = leaveReviewPendingRequest($company, $employee, $type, $policy, $assignment, $account, $requester);

    leaveReviewFailDatabaseInsert('daily_attendance_records');

    expect(fn () => app(ApproveLeaveRequest::class)->handle($request, $reviewer, $company))
        ->toThrow(QueryException::class);

    expect($request->fresh()->status)->toBe(LeaveRequestStatus::Pending)
        ->and($account->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::LeaveUsed->value)->count())->toBe(0)
        ->and(DailyAttendanceRecord::query()->count())->toBe(0);
});

test('attendance history insert failure rolls back its record and approval accounting', function () {
    [$reviewer, $company, $employee] = leaveReviewActor();
    $requester = User::factory()->create();
    [$type, $policy, $assignment, $account] = leaveReviewConfiguration($company, $employee, $reviewer);
    $request = leaveReviewPendingRequest($company, $employee, $type, $policy, $assignment, $account, $requester, '2.00', ['2026-10-05', '2026-10-06'], [100, 100]);
    DB::unprepared("CREATE TRIGGER leave_review_test_fail_history BEFORE INSERT ON daily_attendance_record_histories WHEN NEW.attendance_date LIKE '2026-10-06%' BEGIN SELECT RAISE(ABORT, 'forced test history failure'); END");

    expect(fn () => app(ApproveLeaveRequest::class)->handle($request, $reviewer, $company))
        ->toThrow(QueryException::class);

    expect($request->fresh()->status)->toBe(LeaveRequestStatus::Pending)
        ->and($account->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::LeaveUsed->value)->count())->toBe(0)
        ->and(DailyAttendanceRecord::query()->count())->toBe(0);
});

test('approval notification insert failure rolls back leave usage and attendance synchronization', function () {
    [$reviewer, $company, $employee] = leaveReviewActor();
    $requester = leaveReviewLinkEmployeePortal($employee, $company);
    [$type, $policy, $assignment, $account] = leaveReviewConfiguration($company, $employee, $reviewer);
    $request = leaveReviewPendingRequest($company, $employee, $type, $policy, $assignment, $account, $requester);
    leaveReviewFailDatabaseInsert('notifications');

    expect(fn () => app(ApproveLeaveRequest::class)->handle($request, $reviewer, $company))
        ->toThrow(QueryException::class);

    expect($request->fresh()->status)->toBe(LeaveRequestStatus::Pending)
        ->and($account->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::LeaveUsed->value)->count())->toBe(0)
        ->and(DailyAttendanceRecord::query()->count())->toBe(0)
        ->and($requester->notifications()->count())->toBe(0);
});

test('leave usage ledger failure prevents attendance writes and leaves request pending', function () {
    [$reviewer, $company, $employee] = leaveReviewActor();
    $requester = User::factory()->create();
    [$type, $policy, $assignment, $account] = leaveReviewConfiguration($company, $employee, $reviewer);
    $request = leaveReviewPendingRequest($company, $employee, $type, $policy, $assignment, $account, $requester);
    leaveReviewFailDatabaseInsert('leave_ledger_entries');

    expect(fn () => app(ApproveLeaveRequest::class)->handle($request, $reviewer, $company))
        ->toThrow(QueryException::class);

    expect($request->fresh()->status)->toBe(LeaveRequestStatus::Pending)
        ->and($account->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::LeaveUsed->value)->count())->toBe(0)
        ->and(DailyAttendanceRecord::query()->count())->toBe(0);
});

test('approved future leave is skipped until its company-local date becomes eligible', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-11 18:29:00', 'UTC'));
    [$reviewer, $company, $employee] = leaveReviewActor();
    $company->forceFill(['timezone' => 'Asia/Kolkata'])->save();
    $requester = User::factory()->create();
    [$type, $policy, $assignment, $account] = leaveReviewConfiguration($company, $employee, $reviewer);
    $request = leaveReviewPendingRequest($company, $employee, $type, $policy, $assignment, $account, $requester, '1.00', ['2026-10-12']);

    app(ApproveLeaveRequest::class)->handle($request, $reviewer, $company);

    expect(DailyAttendanceRecord::query()->count())->toBe(0);

    $this->travelTo(CarbonImmutable::parse('2026-10-12 12:00:00', 'Asia/Kolkata'));
    $resolvedLeave = app(ApprovedLeaveResolver::class)->resolve(
        $employee,
        CarbonImmutable::parse('2026-10-12', 'Asia/Kolkata'),
    );
    app(DailyAttendanceMaterializer::class)->materialize($employee, CarbonImmutable::parse('2026-10-12', 'Asia/Kolkata'));

    expect($resolvedLeave->hasApprovedLeave())->toBeTrue()
        ->and(DailyAttendanceRecord::query()->whereDate('attendance_date', '2026-10-12')->count())->toBe(1)
        ->and(DailyAttendanceRecord::query()->whereDate('attendance_date', '2026-10-12')->value('leave_request_id'))->toBe($request->id);
});

test('requester cannot approve or reject own request even with review permissions', function () {
    [$requester, $company, $employee] = leaveReviewActor('hr_admin');
    [$type, $policy, $assignment, $account] = leaveReviewConfiguration($company, $employee, $requester);
    $request = leaveReviewPendingRequest($company, $employee, $type, $policy, $assignment, $account, $requester);

    $this->actingAs($requester)->post(route('companies.leave-requests.approve', [$company, $request]), [])->assertForbidden();
    $this->post(route('companies.leave-requests.reject', [$company, $request]), ['review_note' => 'Rejected after review'])->assertForbidden();
    expect($request->fresh()->status)->toBe(LeaveRequestStatus::Pending)
        ->and($account->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::LeaveUsed->value)->exists())->toBeFalse();
});

test('review routes cannot cross the active company boundary', function () {
    [$reviewerA, $companyA] = leaveReviewActor('hr_admin');
    [$requesterB, $companyB, $employeeB] = leaveReviewActor('employee');
    [$type, $policy, $assignment, $account] = leaveReviewConfiguration($companyB, $employeeB, $reviewerA);
    $request = leaveReviewPendingRequest($companyB, $employeeB, $type, $policy, $assignment, $account, $requesterB);

    $this->actingAs($reviewerA)->post(route('companies.leave-requests.approve', [$companyA, $request]), [])->assertNotFound();
    expect($request->fresh()->status)->toBe(LeaveRequestStatus::Pending)
        ->and($account->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::LeaveUsed->value)->exists())->toBeFalse();
});

test('company roles have the specified review access and manager employee and accountant cannot review', function () {
    foreach (['company_owner', 'hr_admin', 'hr_manager'] as $role) {
        [$reviewer, $company, $employee] = leaveReviewActor($role);
        $requester = User::factory()->create();
        [$type, $policy, $assignment, $account] = leaveReviewConfiguration($company, $employee, $reviewer);
        $request = leaveReviewPendingRequest($company, $employee, $type, $policy, $assignment, $account, $requester);
        $this->actingAs($reviewer)->post(route('companies.leave-requests.reject', [$company, $request]), ['review_note' => 'Not approved by HR'])->assertRedirect();
    }

    foreach (['manager', 'employee', 'accountant'] as $role) {
        [$reviewer, $company, $employee] = leaveReviewActor($role);
        $requester = User::factory()->create();
        [$type, $policy, $assignment, $account] = leaveReviewConfiguration($company, $employee, $reviewer);
        $request = leaveReviewPendingRequest($company, $employee, $type, $policy, $assignment, $account, $requester);
        $this->actingAs($reviewer)->post(route('companies.leave-requests.approve', [$company, $request]), [])->assertForbidden();
        expect($request->fresh()->status)->toBe(LeaveRequestStatus::Pending);
    }
});

test('approved request cannot transition again and review fields remain immutable', function () {
    [$reviewer, $company, $employee] = leaveReviewActor();
    $requester = User::factory()->create();
    [$type, $policy, $assignment, $account] = leaveReviewConfiguration($company, $employee, $reviewer);
    $request = leaveReviewPendingRequest($company, $employee, $type, $policy, $assignment, $account, $requester);
    $this->actingAs($reviewer)->post(route('companies.leave-requests.approve', [$company, $request]), [])->assertRedirect();

    $this->post(route('companies.leave-requests.approve', [$company, $request]), [])->assertForbidden();
    $this->post(route('companies.leave-requests.reject', [$company, $request]), ['review_note' => 'Second review attempt'])->assertForbidden();
    $this->post(route('companies.leave-requests.cancel', [$company, $request]), ['cancellation_reason' => 'Cannot cancel approved leave'])->assertForbidden();
    expect($account->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::LeaveUsed->value)->count())->toBe(1);
    expect(fn () => $request->fresh()->forceFill(['review_note' => 'Altered'])->save())->toThrow(LogicException::class);
});

test('approval preserves other pending reservations against the account balance floor', function () {
    [$reviewer, $company, $employee] = leaveReviewActor();
    $requester = User::factory()->create();
    [$type, $policy, $assignment, $account] = leaveReviewConfiguration($company, $employee, $reviewer, '10.00');
    $requestA = leaveReviewPendingRequest($company, $employee, $type, $policy, $assignment, $account, $requester, '6.00', ['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09', '2026-10-10']);
    $requestB = leaveReviewPendingRequest($company, $employee, $type, $policy, $assignment, $account, $requester, '4.00', ['2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15']);

    app(ApproveLeaveRequest::class)->handle($requestA, $reviewer, $company);

    expect($requestA->fresh()->status)->toBe(LeaveRequestStatus::Approved)
        ->and($requestB->fresh()->status)->toBe(LeaveRequestStatus::Pending)
        ->and(app(LeaveBalanceCalculator::class)->calculate($account)->balance)->toBe('4.00')
        ->and(app(LeaveRequestReservationCalculator::class)->pendingCents($account))->toBe(400)
        ->and(LeaveAmount::fromCents(LeaveAmount::toCents(app(LeaveBalanceCalculator::class)->calculate($account)->balance) - app(LeaveRequestReservationCalculator::class)->pendingCents($account)))->toBe('0.00');
});

test('approval fails and stays pending when balance changed or another request reservation would breach floor', function () {
    [$reviewer, $company, $employee] = leaveReviewActor();
    $requester = User::factory()->create();
    [$type, $policy, $assignment, $account] = leaveReviewConfiguration($company, $employee, $reviewer, '7.00');
    $request = leaveReviewPendingRequest($company, $employee, $type, $policy, $assignment, $account, $requester, '6.00', ['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09', '2026-10-10']);
    $account->ledgerEntries()->create([
        'company_id' => $company->id, 'employee_id' => $employee->id, 'leave_type_id' => $type->id, 'leave_policy_id' => $policy->id,
        'employee_leave_policy_assignment_id' => $assignment->id, 'transaction_type' => LeaveLedgerTransactionType::Adjustment,
        'amount' => '-2.00', 'effective_date' => '2026-10-01', 'description' => 'Reduced after request', 'created_by' => $reviewer->id, 'created_at' => now(),
    ]);

    expect(fn () => app(ApproveLeaveRequest::class)->handle($request, $reviewer, $company))->toThrow(ValidationException::class);
    expect($request->fresh()->status)->toBe(LeaveRequestStatus::Pending)
        ->and($request->fresh()->reviewed_by)->toBeNull()
        ->and($account->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::LeaveUsed->value)->count())->toBe(0);
});

test('approval allows configured negative balance only within its limit', function () {
    [$reviewer, $company, $employee] = leaveReviewActor();
    $requester = User::factory()->create();
    [$type, $policy, $assignment, $account] = leaveReviewConfiguration($company, $employee, $reviewer, '1.00', [
        'allow_negative_balance' => true, 'max_negative_balance_days' => '2.00',
    ]);
    $request = leaveReviewPendingRequest($company, $employee, $type, $policy, $assignment, $account, $requester, '3.50', ['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08'], [100, 100, 100, 50]);
    expect(fn () => app(ApproveLeaveRequest::class)->handle($request, $reviewer, $company))->toThrow(ValidationException::class);
    expect($request->fresh()->status)->toBe(LeaveRequestStatus::Pending);

    $policy->update(['max_negative_balance_days' => '3.00']);
    app(ApproveLeaveRequest::class)->handle($request, $reviewer, $company);
    expect($request->fresh()->status)->toBe(LeaveRequestStatus::Approved)
        ->and(app(LeaveBalanceCalculator::class)->calculate($account)->balance)->toBe('-2.50');
});

test('closed account blocks approval and deactivated policy and leave type preserve historical validity', function () {
    [$reviewer, $company, $employee] = leaveReviewActor();
    $requester = User::factory()->create();
    [$type, $policy, $assignment, $account] = leaveReviewConfiguration($company, $employee, $reviewer);
    $closedRequest = leaveReviewPendingRequest($company, $employee, $type, $policy, $assignment, $account, $requester);
    $account->update(['status' => LeaveBalanceAccountStatus::Closed]);
    expect(fn () => app(ApproveLeaveRequest::class)->handle($closedRequest, $reviewer, $company))->toThrow(ValidationException::class);
    expect($closedRequest->fresh()->status)->toBe(LeaveRequestStatus::Pending);

    [$historicalType, $historicalPolicy, $historicalAssignment, $historicalAccount] = leaveReviewConfiguration($company, $employee, $reviewer);
    $historicalRequest = leaveReviewPendingRequest($company, $employee, $historicalType, $historicalPolicy, $historicalAssignment, $historicalAccount, $requester);
    $historicalPolicy->update(['status' => LeavePolicyStatus::Inactive]);
    $historicalType->update(['status' => LeaveTypeStatus::Inactive]);
    app(ApproveLeaveRequest::class)->handle($historicalRequest, $reviewer, $company);
    expect($historicalRequest->fresh()->status)->toBe(LeaveRequestStatus::Approved);
});

test('an approved chargeable date blocks a second approval across leave types while zero-charge days do not conflict', function () {
    [$reviewer, $company, $employee] = leaveReviewActor();
    $requester = User::factory()->create();
    [$typeA, $policyA, $assignmentA, $accountA] = leaveReviewConfiguration($company, $employee, $reviewer, '5.00');
    [$typeB, $policyB, $assignmentB, $accountB] = leaveReviewConfiguration($company, $employee, $reviewer, '5.00');
    $requestA = leaveReviewPendingRequest($company, $employee, $typeA, $policyA, $assignmentA, $accountA, $requester);
    $requestB = leaveReviewPendingRequest($company, $employee, $typeB, $policyB, $assignmentB, $accountB, $requester);
    app(ApproveLeaveRequest::class)->handle($requestA, $reviewer, $company);
    expect(fn () => app(ApproveLeaveRequest::class)->handle($requestB, $reviewer, $company))->toThrow(ValidationException::class);
    expect($requestB->fresh()->status)->toBe(LeaveRequestStatus::Pending);

    $anotherEmployee = Employee::factory()->create(['company_id' => $company->id]);
    [$typeC, $policyC, $assignmentC, $accountC] = leaveReviewConfiguration($company, $anotherEmployee, $reviewer, '5.00');
    $otherEmployeeRequest = leaveReviewPendingRequest($company, $anotherEmployee, $typeC, $policyC, $assignmentC, $accountC, $requester);
    app(ApproveLeaveRequest::class)->handle($otherEmployeeRequest, $reviewer, $company);
    expect($otherEmployeeRequest->fresh()->status)->toBe(LeaveRequestStatus::Approved);
});

test('zero-charge holiday snapshots do not conflict with a chargeable approved leave date', function () {
    [$reviewer, $company, $employee] = leaveReviewActor();
    $requester = User::factory()->create();
    [$typeA, $policyA, $assignmentA, $accountA] = leaveReviewConfiguration($company, $employee, $reviewer, '5.00');
    [$typeB, $policyB, $assignmentB, $accountB] = leaveReviewConfiguration($company, $employee, $reviewer, '5.00');
    $holidayRequest = leaveReviewPendingRequest(
        $company, $employee, $typeA, $policyA, $assignmentA, $accountA, $requester, '1.00',
        ['2026-10-05', '2026-10-06'], [0, 100],
    );
    $workingRequest = leaveReviewPendingRequest($company, $employee, $typeB, $policyB, $assignmentB, $accountB, $requester);

    app(ApproveLeaveRequest::class)->handle($holidayRequest, $reviewer, $company);
    app(ApproveLeaveRequest::class)->handle($workingRequest, $reviewer, $company);

    expect($holidayRequest->fresh()->status)->toBe(LeaveRequestStatus::Approved)
        ->and($workingRequest->fresh()->status)->toBe(LeaveRequestStatus::Approved);
});

test('approval posting failure rolls back request audit and ledger changes', function () {
    [$reviewer, $company, $employee] = leaveReviewActor();
    $requester = User::factory()->create();
    [$type, $policy, $assignment, $account] = leaveReviewConfiguration($company, $employee, $reviewer);
    $request = leaveReviewPendingRequest($company, $employee, $type, $policy, $assignment, $account, $requester);
    $poster = Mockery::mock(PostLeaveLedgerEntry::class);
    $poster->shouldReceive('handleApprovedLeaveUsage')->once()->andThrow(new RuntimeException('Simulated posting failure.'));
    $this->app->instance(PostLeaveLedgerEntry::class, $poster);

    expect(fn () => app(ApproveLeaveRequest::class)->handle($request, $reviewer, $company))->toThrow(RuntimeException::class);
    expect($request->fresh()->status)->toBe(LeaveRequestStatus::Pending)
        ->and($request->fresh()->reviewed_by)->toBeNull()
        ->and($account->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::LeaveUsed->value)->count())->toBe(0);
});

test('approval rejects a corrupted snapshot total and preserves the pending request', function () {
    [$reviewer, $company, $employee] = leaveReviewActor();
    $requester = User::factory()->create();
    [$type, $policy, $assignment, $account] = leaveReviewConfiguration($company, $employee, $reviewer);
    $request = leaveReviewPendingRequest($company, $employee, $type, $policy, $assignment, $account, $requester, '1.50', ['2026-10-05'], [100]);

    expect(fn () => app(ApproveLeaveRequest::class)->handle($request, $reviewer, $company))->toThrow(ValidationException::class);
    expect($request->fresh()->status)->toBe(LeaveRequestStatus::Pending)
        ->and($request->fresh()->reviewed_by)->toBeNull()
        ->and($account->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::LeaveUsed->value)->exists())->toBeFalse();
});

test('generic manual ledger posting still rejects leave usage', function () {
    [$reviewer, $company, $employee] = leaveReviewActor();
    [, , , $account] = leaveReviewConfiguration($company, $employee, $reviewer);

    expect(fn () => app(PostLeaveLedgerEntry::class)->handle(
        $account,
        $reviewer,
        LeaveLedgerTransactionType::LeaveUsed,
        '-1.00',
        '2026-10-05',
    ))->toThrow(ValidationException::class);
});

test('leave request ledger consumption has a database uniqueness constraint', function () {
    [$reviewer, $company, $employee] = leaveReviewActor();
    $requester = User::factory()->create();
    [$type, $policy, $assignment, $account] = leaveReviewConfiguration($company, $employee, $reviewer);
    $request = leaveReviewPendingRequest($company, $employee, $type, $policy, $assignment, $account, $requester);
    app(ApproveLeaveRequest::class)->handle($request, $reviewer, $company);
    $original = $account->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::LeaveUsed->value)->firstOrFail();

    expect(fn () => $account->ledgerEntries()->create([
        'company_id' => $company->id, 'employee_id' => $employee->id, 'leave_type_id' => $type->id,
        'leave_policy_id' => $policy->id, 'employee_leave_policy_assignment_id' => $assignment->id,
        'transaction_type' => LeaveLedgerTransactionType::LeaveUsed, 'amount' => '-1.00', 'effective_date' => '2026-10-05',
        'reference_type' => LeaveRequest::class, 'reference_id' => $request->id,
        'created_by' => $reviewer->id, 'created_at' => now(),
    ]))->toThrow(QueryException::class);
    expect($original->fresh())->not->toBeNull();
});

test('review detail exposes authorized review actions and historical audit to HR and employees', function () {
    [$reviewer, $company, $employee] = leaveReviewActor();
    $requester = User::factory()->create();
    [$type, $policy, $assignment, $account] = leaveReviewConfiguration($company, $employee, $reviewer);
    $request = leaveReviewPendingRequest($company, $employee, $type, $policy, $assignment, $account, $requester);

    $showResponse = $this->actingAs($reviewer)->get(route('companies.leave-requests.show', [$company, $request]));
    $showResponse->assertInertia(fn (Assert $page) => $page
        ->component('leave-requests/show')->where('canApprove', true)->where('canReject', true)
        ->where('balance.thisRequest', '1.00')->where('balance.otherPendingReserved', '0.00'));

    $this->post(route('companies.leave-requests.reject', [$company, $request]), ['review_note' => 'Rejected with a clear explanation'])->assertRedirect();
    $this->assertDatabaseHas('leave_requests', ['id' => $request->id, 'status' => 'rejected', 'reviewed_by' => $reviewer->id]);
});

test('rejected request factory includes review audit without creating leave-used entries', function () {
    $request = LeaveRequest::factory()->rejected()->create();

    expect($request->status)->toBe(LeaveRequestStatus::Rejected)
        ->and($request->reviewed_by)->not->toBeNull()
        ->and($request->review_note)->not->toBeEmpty()
        ->and($request->balanceAccount->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::LeaveUsed->value)->exists())->toBeFalse();
});

test('explicit approved request factory state creates matching snapshot and leave-used reference', function () {
    $request = LeaveRequest::factory()->approved()->create();
    $entry = $request->balanceAccount->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::LeaveUsed->value)->firstOrFail();

    expect($request->status)->toBe(LeaveRequestStatus::Approved)
        ->and($request->days()->firstOrFail()->requested_amount)->toBe('1.00')
        ->and($entry->amount)->toBe('-1.00')
        ->and($entry->reference_type)->toBe(LeaveRequest::class)
        ->and($entry->reference_id)->toBe($request->id)
        ->and($entry->created_by)->toBe($request->reviewed_by);
});
