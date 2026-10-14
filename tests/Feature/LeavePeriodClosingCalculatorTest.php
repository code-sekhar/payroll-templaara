<?php

use App\Enums\LeaveAccrualFrequency;
use App\Enums\LeaveAccrualMethod;
use App\Enums\LeaveBalanceAccountStatus;
use App\Enums\LeaveLedgerTransactionType;
use App\Enums\LeavePeriodClosingBlocker;
use App\Enums\LeavePolicyStatus;
use App\Enums\LeaveRequestStatus;
use App\Enums\LeaveTypeStatus;
use App\Exceptions\Leave\InvalidLeavePeriodClosingConfigurationException;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeLeaveBalanceAccount;
use App\Models\EmployeeLeavePolicyAssignment;
use App\Models\LeaveLedgerEntry;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Leave\LeavePeriodClosingCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** @return array{Company, Employee, LeaveType, LeavePolicy, EmployeeLeavePolicyAssignment, EmployeeLeaveBalanceAccount} */
function leavePeriodClosingFixture(array $policyAttributes = [], array $accountAttributes = [], string $periodStart = '2026-01-01', string $periodEnd = '2026-12-31'): array
{
    $company = Company::factory()->create(['timezone' => 'Asia/Kolkata']);
    $employee = Employee::factory()->create(['company_id' => $company->id]);
    $leaveType = LeaveType::factory()->create(['company_id' => $company->id]);
    $policy = LeavePolicy::factory()->create(array_merge([
        'company_id' => $company->id,
        'leave_type_id' => $leaveType->id,
        'entitlement_days' => '12.00',
        'accrual_method' => LeaveAccrualMethod::None,
        'accrual_frequency' => LeaveAccrualFrequency::None,
    ], $policyAttributes));
    $assignment = EmployeeLeavePolicyAssignment::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'leave_policy_id' => $policy->id,
        'effective_from' => $periodStart,
        'effective_to' => $periodEnd,
    ]);
    $account = $company->leaveBalanceAccounts()->create(array_merge([
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'leave_policy_id' => $policy->id,
        'employee_leave_policy_assignment_id' => $assignment->id,
        'period_start' => $periodStart,
        'period_end' => $periodEnd,
        'status' => LeaveBalanceAccountStatus::Open,
    ], $accountAttributes));

    return [$company, $employee, $leaveType, $policy, $assignment, $account];
}

function leavePeriodClosingEntry(EmployeeLeaveBalanceAccount $account, LeaveLedgerTransactionType $type, string $amount, string $date = '2026-12-31'): LeaveLedgerEntry
{
    return $account->ledgerEntries()->create([
        'company_id' => $account->company_id,
        'employee_id' => $account->employee_id,
        'leave_type_id' => $account->leave_type_id,
        'leave_policy_id' => $account->leave_policy_id,
        'employee_leave_policy_assignment_id' => $account->employee_leave_policy_assignment_id,
        'transaction_type' => $type,
        'amount' => $amount,
        'effective_date' => $date,
        'description' => 'Closing preview fixture',
        'created_at' => now(),
    ]);
}

function calculateLeavePeriodClosing(EmployeeLeaveBalanceAccount $account, string $asOfDate = '2026-12-31')
{
    return app(LeavePeriodClosingCalculator::class)->calculate($account, CarbonImmutable::parse($asOfDate, 'UTC'));
}

test('closing preview uses the ledger balance and applies disabled and limited carry forward exactly', function () {
    [, , , , , $disabled] = leavePeriodClosingFixture();
    leavePeriodClosingEntry($disabled, LeaveLedgerTransactionType::OpeningBalance, '8.25');
    $disabledResult = calculateLeavePeriodClosing($disabled);

    [, , , $policy, , $limited] = leavePeriodClosingFixture([
        'carry_forward_enabled' => true,
        'max_carry_forward_days' => '5.50',
    ]);
    leavePeriodClosingEntry($limited, LeaveLedgerTransactionType::OpeningBalance, '8.25');
    $limitedResult = calculateLeavePeriodClosing($limited);

    expect($disabledResult->eligibleToClose)->toBeTrue()
        ->and($disabledResult->closingBalance)->toBe('8.25')
        ->and($disabledResult->carryForwardAmount)->toBe('0.00')
        ->and($disabledResult->expireAmount)->toBe('8.25')
        ->and($limitedResult->carryForwardLimit)->toBe('5.50')
        ->and($limitedResult->carryForwardAmount)->toBe('5.50')
        ->and($limitedResult->expireAmount)->toBe('2.75')
        ->and($policy->carry_forward_enabled)->toBeTrue();
});

test('balance at or below finite carry limit carries only the balance and zero balance remains zero', function () {
    [, , , , , $account] = leavePeriodClosingFixture(['carry_forward_enabled' => true, 'max_carry_forward_days' => '5.00']);
    leavePeriodClosingEntry($account, LeaveLedgerTransactionType::OpeningBalance, '3.00');
    $belowLimit = calculateLeavePeriodClosing($account);

    [, , , , , $zeroAccount] = leavePeriodClosingFixture(['carry_forward_enabled' => true, 'max_carry_forward_days' => '5.00']);
    $zero = calculateLeavePeriodClosing($zeroAccount);

    expect($belowLimit->carryForwardAmount)->toBe('3.00')
        ->and($belowLimit->expireAmount)->toBe('0.00')
        ->and($zero->carryForwardAmount)->toBe('0.00')
        ->and($zero->expireAmount)->toBe('0.00');
});

test('negative closing balance is exposed and blocks closing without carry or expiry', function () {
    [, , , , , $account] = leavePeriodClosingFixture(['allow_negative_balance' => true]);
    leavePeriodClosingEntry($account, LeaveLedgerTransactionType::OpeningBalance, '1.00');
    leavePeriodClosingEntry($account, LeaveLedgerTransactionType::Adjustment, '-3.00');

    $result = calculateLeavePeriodClosing($account);

    expect($result->closingBalance)->toBe('-2.00')
        ->and($result->carryForwardAmount)->toBe('0.00')
        ->and($result->expireAmount)->toBe('0.00')
        ->and($result->eligibleToClose)->toBeFalse()
        ->and($result->blockers)->toContain(LeavePeriodClosingBlocker::NegativeClosingBalance);
});

test('closing eligibility begins on the inclusive period end and closed accounts remain previewable', function () {
    [, , , , , $account] = leavePeriodClosingFixture();
    $before = calculateLeavePeriodClosing($account, '2026-12-30');
    $onEnd = calculateLeavePeriodClosing($account, '2026-12-31');
    $after = calculateLeavePeriodClosing($account, '2027-01-10');

    $account->status = LeaveBalanceAccountStatus::Closed;
    $account->save();
    $closed = calculateLeavePeriodClosing($account);

    expect($before->eligibleToClose)->toBeFalse()
        ->and($before->blockers)->toContain(LeavePeriodClosingBlocker::PeriodNotEnded)
        ->and($onEnd->eligibleToClose)->toBeTrue()
        ->and($after->eligibleToClose)->toBeTrue()
        ->and($closed->alreadyClosed)->toBeTrue()
        ->and($closed->eligibleToClose)->toBeFalse()
        ->and($closed->blockers)->toContain(LeavePeriodClosingBlocker::AccountAlreadyClosed);
});

test('final accrual target is reused and missing or excess accrual blocks closing explicitly', function () {
    [, , , , , $missingAccount] = leavePeriodClosingFixture([
        'accrual_method' => LeaveAccrualMethod::Accrual,
        'accrual_frequency' => LeaveAccrualFrequency::Monthly,
    ]);
    leavePeriodClosingEntry($missingAccount, LeaveLedgerTransactionType::Accrual, '10.00');
    $missing = calculateLeavePeriodClosing($missingAccount);

    [, , , , , $excessAccount] = leavePeriodClosingFixture([
        'accrual_method' => LeaveAccrualMethod::Accrual,
        'accrual_frequency' => LeaveAccrualFrequency::Monthly,
    ]);
    leavePeriodClosingEntry($excessAccount, LeaveLedgerTransactionType::Accrual, '13.00');
    $excess = calculateLeavePeriodClosing($excessAccount);

    expect($missing->finalAccrualTarget)->toBe('12.00')
        ->and($missing->postedAccrualAmount)->toBe('10.00')
        ->and($missing->missingAccrualAmount)->toBe('2.00')
        ->and($missing->blockers)->toContain(LeavePeriodClosingBlocker::AccrualNotFullyReconciled)
        ->and($excess->missingAccrualAmount)->toBe('0.00')
        ->and($excess->blockers)->toContain(LeavePeriodClosingBlocker::PostedAccrualExceedsCalculatedTarget);
});

test('upfront and monthly policies use their existing final accrual calculation', function () {
    [, , , , , $upfrontAccount] = leavePeriodClosingFixture([
        'accrual_method' => LeaveAccrualMethod::Upfront,
        'accrual_frequency' => LeaveAccrualFrequency::None,
        'entitlement_days' => '7.50',
    ]);
    leavePeriodClosingEntry($upfrontAccount, LeaveLedgerTransactionType::Accrual, '7.50');
    $upfront = calculateLeavePeriodClosing($upfrontAccount);

    [, , , , , $monthlyAccount] = leavePeriodClosingFixture([
        'accrual_method' => LeaveAccrualMethod::Accrual,
        'accrual_frequency' => LeaveAccrualFrequency::Monthly,
        'entitlement_days' => '10.00',
    ]);
    leavePeriodClosingEntry($monthlyAccount, LeaveLedgerTransactionType::Accrual, '10.00');
    $monthly = calculateLeavePeriodClosing($monthlyAccount);

    expect($upfront->finalAccrualTarget)->toBe('7.50')
        ->and($upfront->missingAccrualAmount)->toBe('0.00')
        ->and($monthly->finalAccrualTarget)->toBe('10.00')
        ->and($monthly->missingAccrualAmount)->toBe('0.00')
        ->and($monthly->eligibleToClose)->toBeTrue();
});

test('none accrual does not require an accrual ledger entry', function () {
    [, , , , , $account] = leavePeriodClosingFixture(['entitlement_days' => '10.00']);
    leavePeriodClosingEntry($account, LeaveLedgerTransactionType::OpeningBalance, '4.00');

    $result = calculateLeavePeriodClosing($account);

    expect($result->finalAccrualTarget)->toBe('0.00')
        ->and($result->missingAccrualAmount)->toBe('0.00')
        ->and($result->eligibleToClose)->toBeTrue()
        ->and($result->closingBalance)->toBe('4.00');
});

test('unsupported accrual frequency is a blocker while other closing preview values remain available', function () {
    [, , , , , $account] = leavePeriodClosingFixture([
        'accrual_method' => LeaveAccrualMethod::Accrual,
        'accrual_frequency' => LeaveAccrualFrequency::Quarterly,
    ]);
    leavePeriodClosingEntry($account, LeaveLedgerTransactionType::OpeningBalance, '2.00');

    $result = calculateLeavePeriodClosing($account);

    expect($result->finalAccrualTarget)->toBeNull()
        ->and($result->closingBalance)->toBe('2.00')
        ->and($result->blockers)->toContain(LeavePeriodClosingBlocker::UnsupportedAccrualConfiguration)
        ->and($result->eligibleToClose)->toBeFalse();

    $account->leavePolicy->update(['accrual_frequency' => LeaveAccrualFrequency::Yearly]);
    $yearly = calculateLeavePeriodClosing($account);

    expect($yearly->blockers)->toContain(LeavePeriodClosingBlocker::UnsupportedAccrualConfiguration)
        ->and($yearly->finalAccrualTarget)->toBeNull();
});

test('pending reservation amount blocks closing while terminal requests do not', function () {
    [, $employee, $type, $policy, $assignment, $account] = leavePeriodClosingFixture();
    $requester = User::factory()->create();
    LeaveRequest::factory()->create([
        'company_id' => $account->company_id,
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'leave_policy_id' => $policy->id,
        'employee_leave_policy_assignment_id' => $assignment->id,
        'leave_balance_account_id' => $account->id,
        'requested_by' => $requester->id,
        'requested_days' => '1.25',
        'status' => LeaveRequestStatus::Pending,
    ]);

    $result = calculateLeavePeriodClosing($account);

    expect($result->pendingReservationAmount)->toBe('1.25')
        ->and($result->eligibleToClose)->toBeFalse()
        ->and($result->blockers)->toContain(LeavePeriodClosingBlocker::PendingLeaveReservations);
});

test('approved rejected and cancelled requests do not block closing by themselves', function () {
    [, $employee, $type, $policy, $assignment, $account] = leavePeriodClosingFixture([
        'allow_negative_balance' => true,
    ]);
    leavePeriodClosingEntry($account, LeaveLedgerTransactionType::OpeningBalance, '5.00');
    $reviewer = User::factory()->create();
    $attributes = [
        'company_id' => $account->company_id,
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'leave_policy_id' => $policy->id,
        'employee_leave_policy_assignment_id' => $assignment->id,
        'leave_balance_account_id' => $account->id,
    ];
    LeaveRequest::factory()->approved($reviewer)->create($attributes);
    LeaveRequest::factory()->rejected($reviewer)->create($attributes);
    LeaveRequest::factory()->cancelled()->create($attributes);

    $result = calculateLeavePeriodClosing($account);

    expect($result->pendingReservationAmount)->toBe('0.00')
        ->and($result->eligibleToClose)->toBeTrue()
        ->and($result->blockers)->toBe([]);
});

test('next period derives from actual calendar span and detects an exact next account', function () {
    [, , , , , $account] = leavePeriodClosingFixture([], [], '2026-04-01', '2027-03-31');
    $beforeNext = calculateLeavePeriodClosing($account, '2027-03-31');

    $account->company->leaveBalanceAccounts()->create([
        'employee_id' => $account->employee_id,
        'leave_type_id' => $account->leave_type_id,
        'leave_policy_id' => $account->leave_policy_id,
        'employee_leave_policy_assignment_id' => $account->employee_leave_policy_assignment_id,
        'period_start' => '2027-04-01',
        'period_end' => '2028-03-31',
        'status' => LeaveBalanceAccountStatus::Open,
    ]);
    $withNext = calculateLeavePeriodClosing($account, '2027-03-31');

    expect($beforeNext->nextPeriodStart->toDateString())->toBe('2027-04-01')
        ->and($beforeNext->nextPeriodEnd->toDateString())->toBe('2028-03-31')
        ->and($beforeNext->nextAccountExists)->toBeFalse()
        ->and($withNext->nextAccountExists)->toBeTrue()
        ->and($withNext->nextAccount?->period_start->toDateString())->toBe('2027-04-01')
        ->and($withNext->eligibleToClose)->toBeTrue();
});

test('leap-day period anniversary normalizes by preserving its actual calendar span', function () {
    [, , , , , $account] = leavePeriodClosingFixture([], [], '2028-02-29', '2029-02-28');

    $result = calculateLeavePeriodClosing($account, '2029-02-28');

    expect($result->nextPeriodStart->toDateString())->toBe('2029-03-01')
        ->and($result->nextPeriodEnd->toDateString())->toBe('2030-02-28');
});

test('as-of date is preserved as a company-local calendar date', function () {
    [, , , , , $account] = leavePeriodClosingFixture();
    $asOfDate = CarbonImmutable::parse('2026-12-31 23:30:00', 'America/Los_Angeles');

    $result = app(LeavePeriodClosingCalculator::class)->calculate($account, $asOfDate);

    expect($result->asOfDate->timezone->getName())->toBe('Asia/Kolkata')
        ->and($result->asOfDate->toDateString())->toBe('2026-12-31')
        ->and($result->eligibleToClose)->toBeTrue();
});

test('multiple operational blockers are returned together without mutating state', function () {
    [, $employee, $type, $policy, $assignment, $account] = leavePeriodClosingFixture([
        'accrual_method' => LeaveAccrualMethod::Accrual,
        'accrual_frequency' => LeaveAccrualFrequency::Monthly,
        'carry_forward_enabled' => true,
        'max_carry_forward_days' => '3.00',
    ]);
    leavePeriodClosingEntry($account, LeaveLedgerTransactionType::Accrual, '1.00');
    $requester = User::factory()->create();
    LeaveRequest::factory()->create([
        'company_id' => $account->company_id,
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'leave_policy_id' => $policy->id,
        'employee_leave_policy_assignment_id' => $assignment->id,
        'leave_balance_account_id' => $account->id,
        'requested_by' => $requester->id,
        'requested_days' => '0.50',
    ]);
    $accountState = [$account->status->value, $account->updated_by];
    $ledgerCount = $account->ledgerEntries()->count();
    $requestState = LeaveRequest::query()->first()->only(['status', 'requested_days']);

    $result = calculateLeavePeriodClosing($account, '2026-12-30');

    expect($result->blockers)->toContain(LeavePeriodClosingBlocker::PeriodNotEnded)
        ->and($result->blockers)->toContain(LeavePeriodClosingBlocker::AccrualNotFullyReconciled)
        ->and($result->blockers)->toContain(LeavePeriodClosingBlocker::PendingLeaveReservations)
        ->and($result->eligibleToClose)->toBeFalse()
        ->and([$account->fresh()->status->value, $account->fresh()->updated_by])->toBe($accountState)
        ->and($account->ledgerEntries()->count())->toBe($ledgerCount)
        ->and(LeaveRequest::query()->first()->only(['status', 'requested_days']))->toBe($requestState);
});

test('historical inactive policy and leave type remain usable for preview', function () {
    [, , , $policy, , $account] = leavePeriodClosingFixture();
    $policy->status = LeavePolicyStatus::Inactive;
    $policy->save();
    $type = $account->leaveType;
    $type->status = LeaveTypeStatus::Inactive;
    $type->save();
    leavePeriodClosingEntry($account, LeaveLedgerTransactionType::OpeningBalance, '1.50');

    $result = calculateLeavePeriodClosing($account);

    expect($result->eligibleToClose)->toBeTrue()
        ->and($result->closingBalance)->toBe('1.50');
});

test('preview does not mutate ledger, balance account, requests or create the next account', function () {
    [, , , , , $account] = leavePeriodClosingFixture();
    leavePeriodClosingEntry($account, LeaveLedgerTransactionType::OpeningBalance, '2.75');
    $accountBefore = $account->fresh()->getAttributes();
    $ledgerBefore = LeaveLedgerEntry::query()->count();
    $accountsBefore = EmployeeLeaveBalanceAccount::query()->count();
    $requestsBefore = LeaveRequest::query()->count();

    $result = calculateLeavePeriodClosing($account);

    expect($result->closingBalance)->toBe('2.75')
        ->and($account->fresh()->getAttributes())->toBe($accountBefore)
        ->and(LeaveLedgerEntry::query()->count())->toBe($ledgerBefore)
        ->and(EmployeeLeaveBalanceAccount::query()->count())->toBe($accountsBefore)
        ->and(LeaveRequest::query()->count())->toBe($requestsBefore);
});

test('overlapping next-period accounts create a conflict blocker', function () {
    [, , , , , $account] = leavePeriodClosingFixture();
    $account->company->leaveBalanceAccounts()->create([
        'employee_id' => $account->employee_id,
        'leave_type_id' => $account->leave_type_id,
        'leave_policy_id' => $account->leave_policy_id,
        'employee_leave_policy_assignment_id' => $account->employee_leave_policy_assignment_id,
        'period_start' => '2027-06-01',
        'period_end' => '2028-05-31',
        'status' => LeaveBalanceAccountStatus::Open,
    ]);

    $result = calculateLeavePeriodClosing($account);

    expect($result->nextAccountExists)->toBeFalse()
        ->and($result->blockers)->toContain(LeavePeriodClosingBlocker::NextPeriodAccountConflict)
        ->and($result->eligibleToClose)->toBeFalse();
});

test('invalid carry-forward configuration raises an explicit domain exception', function () {
    [, , , $policy, , $account] = leavePeriodClosingFixture();
    DB::table('leave_policies')->where('id', $policy->id)->update(['carry_forward_enabled' => true, 'max_carry_forward_days' => null]);
    $policy->refresh();

    expect(fn () => calculateLeavePeriodClosing($account))
        ->toThrow(InvalidLeavePeriodClosingConfigurationException::class);
});
