<?php

use App\Actions\LeaveBalances\PostLeaveLedgerEntry;
use App\Actions\LeaveRequests\CancelApprovedLeaveRequest;
use App\Actions\LeaveRequests\CancelLeaveRequest;
use App\Actions\SeedCompanyRoles;
use App\Enums\LeaveBalanceAccountStatus;
use App\Enums\LeaveLedgerTransactionType;
use App\Enums\LeavePolicyStatus;
use App\Enums\LeaveRequestDayType;
use App\Enums\LeaveRequestStatus;
use App\Enums\LeaveTypeStatus;
use App\Exceptions\Leave\ApprovedLeaveCancellationException;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\DailyAttendanceRecord;
use App\Models\DailyAttendanceRecordHistory;
use App\Models\Employee;
use App\Models\EmployeeLeaveBalanceAccount;
use App\Models\EmployeeLeavePolicyAssignment;
use App\Models\LeaveLedgerEntry;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Leave\ApprovedLeaveCancellationResult;
use App\Services\Leave\ApprovedLeaveResolver;
use App\Services\Leave\LeaveBalanceCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/** @return array{User, Company, Employee, User, LeaveType, LeavePolicy, EmployeeLeavePolicyAssignment, EmployeeLeaveBalanceAccount, LeaveRequest} */
function approvedCancellationFixture(
    string $amount = '1.00',
    array $policyChanges = [],
    string $role = 'hr_admin',
    ?string $usageAmount = null,
    bool $includeUsage = true,
): array {
    $company = Company::factory()->create();
    $actor = User::factory()->create();
    CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($company);
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($company->id);
    $actor->assignRole($role);
    $registrar->setPermissionsTeamId(null);

    $requester = User::factory()->create();
    $employee = Employee::factory()->create(['company_id' => $company->id]);
    $type = LeaveType::factory()->create(['company_id' => $company->id]);
    $policy = LeavePolicy::factory()->create(array_merge([
        'company_id' => $company->id,
        'leave_type_id' => $type->id,
        'minimum_request_days' => '0.50',
        'allows_half_day' => true,
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
        'description' => 'Approved cancellation test opening',
        'created_by' => $actor->id,
        'created_at' => now(),
    ]);

    $reviewer = User::factory()->create();
    $request = LeaveRequest::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'leave_policy_id' => $policy->id,
        'employee_leave_policy_assignment_id' => $assignment->id,
        'leave_balance_account_id' => $account->id,
        'start_date' => '2026-06-15',
        'end_date' => '2026-06-15',
        'requested_days' => $amount,
        'duration' => $amount === '0.50' ? 'half_day' : 'full_day',
        'requested_by' => $requester->id,
        'status' => LeaveRequestStatus::Approved,
        'reviewed_by' => $reviewer->id,
        'reviewed_at' => now(),
    ]);

    $request->days()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_date' => '2026-06-15',
        'day_type' => $amount === '0.50' ? LeaveRequestDayType::HalfDay : LeaveRequestDayType::FullDay,
        'requested_amount' => $amount,
    ]);

    if ($includeUsage) {
        $account->ledgerEntries()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'leave_policy_id' => $policy->id,
            'employee_leave_policy_assignment_id' => $assignment->id,
            'transaction_type' => LeaveLedgerTransactionType::LeaveUsed,
            'amount' => $usageAmount ?? '-'.$amount,
            'effective_date' => '2026-06-15',
            'description' => "Approved leave request LR-{$request->id}",
            'reference_type' => LeaveRequest::class,
            'reference_id' => $request->id,
            'created_by' => $reviewer->id,
            'created_at' => now(),
        ]);
    }

    return [$actor, $company, $employee, $requester, $type, $policy, $assignment, $account, $request];
}

function approvedCancellationRun(LeaveRequest $request, User $actor, string $reason = 'Approved leave no longer needed'): ApprovedLeaveCancellationResult
{
    return app(CancelApprovedLeaveRequest::class)->execute($request, $actor, $reason);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-16 12:00:00 UTC');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

test('approved cancellation appends exact positive reversal and preserves approval and request day audit', function () {
    [$actor, , , , , , , $account, $request] = approvedCancellationFixture('1.50');
    $usage = $account->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::LeaveUsed->value)->firstOrFail();
    $usageBefore = $usage->getAttributes();
    $daysBefore = $request->days()->firstOrFail()->getAttributes();
    $attendanceCount = DailyAttendanceRecord::query()->count();
    $historyCount = DailyAttendanceRecordHistory::query()->count();

    $result = approvedCancellationRun($request, $actor);

    expect($result->wasCancelledNow)->toBeTrue()
        ->and($result->isUpToDate)->toBeTrue()
        ->and($result->reversedAmount)->toBe('1.50')
        ->and($result->reversalEntry->amount)->toBe('1.50')
        ->and($result->reversalEntry->transaction_type)->toBe(LeaveLedgerTransactionType::LeaveReversed)
        ->and($result->reversalEntry->reference_type)->toBe(LeaveLedgerEntry::class)
        ->and($result->reversalEntry->reference_id)->toBe($usage->id)
        ->and($result->reversalEntry->created_by)->toBe($actor->id)
        ->and($request->fresh()->status)->toBe(LeaveRequestStatus::Cancelled)
        ->and($request->fresh()->reviewed_by)->not->toBeNull()
        ->and($request->fresh()->reviewed_at)->not->toBeNull()
        ->and($request->fresh()->cancelled_by)->toBe($actor->id)
        ->and($request->fresh()->cancellation_reason)->toBe('Approved leave no longer needed')
        ->and($usage->fresh()->getAttributes())->toBe($usageBefore)
        ->and($request->days()->firstOrFail()->getAttributes())->toBe($daysBefore)
        ->and(app(LeaveBalanceCalculator::class)->calculate($account)->balance)->toBe('10.00')
        ->and(DailyAttendanceRecord::query()->count())->toBe($attendanceCount)
        ->and(DailyAttendanceRecordHistory::query()->count())->toBe($historyCount);
});

test('approved cancellation is idempotent and does not append a second reversal', function () {
    [$actor, , , , , , , $account, $request] = approvedCancellationFixture('0.50');
    $first = approvedCancellationRun($request, $actor);
    $ledgerCount = $account->ledgerEntries()->count();
    $cancelledAt = $request->fresh()->cancelled_at->toDateTimeString();

    $second = approvedCancellationRun($request->fresh(), $actor, 'A retry with another note');

    expect($second->alreadyCancelled)->toBeTrue()
        ->and($second->wasCancelledNow)->toBeFalse()
        ->and($account->ledgerEntries()->count())->toBe($ledgerCount)
        ->and($request->fresh()->cancelled_at->toDateTimeString())->toBe($cancelledAt)
        ->and($request->fresh()->cancellation_reason)->toBe('Approved leave no longer needed')
        ->and($first->reversalEntry->amount)->toBe('0.50');
});

test('pending cancellation remains reservation-only and does not post a reversal', function () {
    [$actor, , , , , , , $account, $approved] = approvedCancellationFixture();
    $pending = LeaveRequest::factory()->create([
        'company_id' => $approved->company_id,
        'employee_id' => $approved->employee_id,
        'leave_type_id' => $approved->leave_type_id,
        'leave_policy_id' => $approved->leave_policy_id,
        'employee_leave_policy_assignment_id' => $approved->employee_leave_policy_assignment_id,
        'leave_balance_account_id' => $account->id,
    ]);
    $ledgerCount = $account->ledgerEntries()->count();

    app(CancelLeaveRequest::class)->handle($pending, $actor, 'Plans changed');

    expect($pending->fresh()->status)->toBe(LeaveRequestStatus::Cancelled)
        ->and($account->ledgerEntries()->count())->toBe($ledgerCount)
        ->and($account->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::LeaveReversed->value)->exists())->toBeFalse();
});

test('approved cancellation rejects employee self cancellation and unauthorized users', function () {
    [, , , $requester, , , , , $request] = approvedCancellationFixture();
    $this->expectException(ApprovedLeaveCancellationException::class);
    $this->expectExceptionMessage('CANCELLATION_NOT_AUTHORIZED');
    approvedCancellationRun($request, $requester);
});

test('authorized employee cannot directly cancel their own approved request', function () {
    [$actor, , $employee, , , , , , $request] = approvedCancellationFixture();
    $employee->update(['user_id' => $actor->id]);

    try {
        approvedCancellationRun($request, $actor);
        test()->fail('Expected direct self-cancellation to be denied.');
    } catch (ApprovedLeaveCancellationException $exception) {
        expect($exception->reason)->toBe('REQUESTER_CANNOT_CANCEL_APPROVED_LEAVE');
    }

    expect($request->fresh()->status)->toBe(LeaveRequestStatus::Approved);
});

test('owner admin and hr manager can cancel an approved request', function (string $role) {
    [$actor, , , , , , , , $request] = approvedCancellationFixture('1.00', [], $role);
    expect(approvedCancellationRun($request, $actor)->wasCancelledNow)->toBeTrue();
})->with(['company_owner', 'hr_admin', 'hr_manager']);

test('cancellation fails when original approved usage is missing', function () {
    [$actor, , , , , , , $account, $request] = approvedCancellationFixture('1.00', [], 'hr_admin', null, false);

    try {
        approvedCancellationRun($request, $actor);
        test()->fail('Expected missing usage to fail.');
    } catch (ApprovedLeaveCancellationException $exception) {
        expect($exception->reason)->toBe('APPROVED_LEAVE_USAGE_NOT_FOUND');
    }

    expect($request->fresh()->status)->toBe(LeaveRequestStatus::Approved)
        ->and($account->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::LeaveReversed->value)->count())->toBe(0);
});

test('database uniqueness prevents multiple request-linked usage entries', function () {
    [, , , , , , , $account] = approvedCancellationFixture();
    $usage = $account->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::LeaveUsed->value)->firstOrFail();
    $duplicate = $usage->getAttributes();
    unset($duplicate['id']);

    expect(fn () => DB::table('leave_ledger_entries')->insert($duplicate))->toThrow(QueryException::class);
});

test('mismatched usage amount fails without changing status or ledger', function () {
    [$actor, , , , , , , $account, $request] = approvedCancellationFixture('1.00', [], 'hr_admin', '-0.50');
    $count = $account->ledgerEntries()->count();

    try {
        approvedCancellationRun($request, $actor);
        test()->fail('Expected amount mismatch to fail.');
    } catch (ApprovedLeaveCancellationException $exception) {
        expect($exception->reason)->toBe('APPROVED_LEAVE_USAGE_AMOUNT_MISMATCH');
    }

    expect($request->fresh()->status)->toBe(LeaveRequestStatus::Approved)
        ->and($account->ledgerEntries()->count())->toBe($count);
});

test('invalid positive usage sign fails without posting a reversal', function () {
    [$actor, , , , , , , $account, $request] = approvedCancellationFixture('1.00', [], 'hr_admin', '1.00');

    try {
        approvedCancellationRun($request, $actor);
        test()->fail('Expected invalid usage sign to fail.');
    } catch (ApprovedLeaveCancellationException $exception) {
        expect($exception->reason)->toBe('APPROVED_LEAVE_USAGE_SIGN_INVALID');
    }

    expect($request->fresh()->status)->toBe(LeaveRequestStatus::Approved)
        ->and($account->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::LeaveReversed->value)->exists())->toBeFalse();
});

test('existing exact reversal is reused while a mismatched reversal fails', function (string $case) {
    [$actor, , , , , , , $account, $request] = approvedCancellationFixture();
    $usage = $account->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::LeaveUsed->value)->firstOrFail();
    $reversal = $account->ledgerEntries()->create([
        'company_id' => $usage->company_id,
        'employee_id' => $usage->employee_id,
        'leave_type_id' => $usage->leave_type_id,
        'leave_policy_id' => $usage->leave_policy_id,
        'employee_leave_policy_assignment_id' => $usage->employee_leave_policy_assignment_id,
        'transaction_type' => LeaveLedgerTransactionType::LeaveReversed,
        'amount' => $case === 'mismatch' ? '0.50' : '1.00',
        'effective_date' => $usage->effective_date->toDateString(),
        'description' => 'Existing exact reversal',
        'reference_type' => LeaveLedgerEntry::class,
        'reference_id' => $usage->id,
        'created_at' => now(),
    ]);
    $ledgerCount = $account->ledgerEntries()->count();

    try {
        $result = approvedCancellationRun($request, $actor);
        if ($case === 'mismatch') {
            test()->fail('Expected corrupt existing reversal to fail.');
        }
        expect($result->reversalEntry->id)->toBe($reversal->id)
            ->and($account->ledgerEntries()->count())->toBe($ledgerCount);
    } catch (ApprovedLeaveCancellationException $exception) {
        expect($exception->reason)->toBe('LEAVE_REVERSAL_AMOUNT_MISMATCH')
            ->and($request->fresh()->status)->toBe(LeaveRequestStatus::Approved);
    }
})->with(['exact', 'mismatch']);

test('closed historical account blocks reversal without reopening or changing closing entries', function () {
    [$actor, , , , , , , $account, $request] = approvedCancellationFixture();
    $account->update(['status' => LeaveBalanceAccountStatus::Closed]);
    $ledgerCount = $account->ledgerEntries()->count();

    try {
        approvedCancellationRun($request, $actor);
        test()->fail('Expected a closed account to block cancellation.');
    } catch (ApprovedLeaveCancellationException $exception) {
        expect($exception->reason)->toBe('LEAVE_BALANCE_PERIOD_CLOSED');
    }

    expect($request->fresh()->status)->toBe(LeaveRequestStatus::Approved)
        ->and($account->fresh()->status)->toBe(LeaveBalanceAccountStatus::Closed)
        ->and($account->ledgerEntries()->count())->toBe($ledgerCount);
});

test('cancelled approved request without its matching reversal is reported as inconsistent', function () {
    [$actor, , , , , , , $account, $request] = approvedCancellationFixture();
    DB::table('leave_requests')->where('id', $request->id)->update(['status' => LeaveRequestStatus::Cancelled->value]);

    try {
        approvedCancellationRun($request, $actor);
        test()->fail('Expected missing reversal to fail.');
    } catch (ApprovedLeaveCancellationException $exception) {
        expect($exception->reason)->toBe('CANCELLED_LEAVE_REVERSAL_MISSING');
    }

    expect($account->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::LeaveReversed->value)->exists())->toBeFalse();
});

test('balance cap violation rolls back cancellation and leaves usage intact', function () {
    [$actor, , , , , $policy, , $account, $request] = approvedCancellationFixture('1.00', ['max_balance_days' => '9.00']);
    $ledgerCount = $account->ledgerEntries()->count();

    try {
        approvedCancellationRun($request, $actor);
        test()->fail('Expected balance cap to block reversal.');
    } catch (ApprovedLeaveCancellationException $exception) {
        expect($exception->reason)->toBe('BALANCE_CAP_EXCEEDED');
    }

    expect($request->fresh()->status)->toBe(LeaveRequestStatus::Approved)
        ->and($account->ledgerEntries()->count())->toBe($ledgerCount)
        ->and($policy->fresh()->max_balance_days)->toBe('9.00');
});

test('request transition failure rolls back the newly appended reversal', function () {
    [$actor, , , , , , , $account, $request] = approvedCancellationFixture();
    $ledgerCount = $account->ledgerEntries()->count();
    DB::statement("CREATE TRIGGER test_reject_approved_leave_cancellation BEFORE UPDATE OF status ON leave_requests WHEN NEW.status = 'cancelled' BEGIN SELECT RAISE(ABORT, 'forced request update failure'); END");

    try {
        expect(fn () => approvedCancellationRun($request, $actor))
            ->toThrow(QueryException::class);
    } finally {
        DB::statement('DROP TRIGGER IF EXISTS test_reject_approved_leave_cancellation');
    }

    expect($request->fresh()->status)->toBe(LeaveRequestStatus::Approved)
        ->and($account->ledgerEntries()->count())->toBe($ledgerCount)
        ->and($account->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::LeaveReversed->value)->exists())->toBeFalse()
        ->and(app(LeaveBalanceCalculator::class)->calculate($account)->balance)->toBe('9.00');
});

test('inactive historical policy and leave type remain usable for approved cancellation', function () {
    [$actor, , , , $type, $policy, , , $request] = approvedCancellationFixture();
    $policy->update(['status' => LeavePolicyStatus::Inactive]);
    $type->update(['status' => LeaveTypeStatus::Inactive]);

    expect(approvedCancellationRun($request, $actor)->wasCancelledNow)->toBeTrue();
});

test('approved cancellation status transition cannot bypass exact reversal posting', function () {
    [, , , , , , , , $request] = approvedCancellationFixture();

    expect(fn () => $request->forceFill([
        'status' => LeaveRequestStatus::Cancelled,
        'cancelled_by' => User::factory()->create()->id,
        'cancelled_at' => now(),
        'cancellation_reason' => 'Cancelled without reversal',
    ])->save())->toThrow(LogicException::class);
});

test('approved resolver stops resolving the request after successful cancellation', function () {
    [$actor, , $employee, , , , , , $request] = approvedCancellationFixture();
    $before = app(ApprovedLeaveResolver::class)->resolve($employee, CarbonImmutable::parse('2026-06-15'));
    expect($before->hasApprovedLeave())->toBeTrue();

    approvedCancellationRun($request, $actor);
    $after = app(ApprovedLeaveResolver::class)->resolve($employee, CarbonImmutable::parse('2026-06-15'));

    expect($after->hasApprovedLeave())->toBeFalse();
});

test('manual posting remains unable to post a reversal', function () {
    [$actor, , , , , , , $account] = approvedCancellationFixture();

    expect(fn () => app(PostLeaveLedgerEntry::class)->handle(
        $account,
        $actor,
        LeaveLedgerTransactionType::LeaveReversed,
        '1.00',
        '2026-06-15',
        'Arbitrary manual reversal',
    ))->toThrow(ValidationException::class);
});
