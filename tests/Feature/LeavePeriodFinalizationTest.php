<?php

use App\Actions\LeaveBalances\ApplyLeavePeriodClosingAccounting;
use App\Actions\LeaveBalances\CloseLeaveBalanceAccount;
use App\Actions\LeaveBalances\FinalizeLeavePeriod;
use App\Enums\LeaveAccrualFrequency;
use App\Enums\LeaveAccrualMethod;
use App\Enums\LeaveBalanceAccountStatus;
use App\Enums\LeaveLedgerTransactionType;
use App\Enums\LeaveRequestStatus;
use App\Exceptions\Leave\LeavePeriodFinalizationException;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeLeaveBalanceAccount;
use App\Models\EmployeeLeavePolicyAssignment;
use App\Models\LeaveLedgerEntry;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Leave\LeaveBalanceCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** @return array{Company, Employee, LeaveType, LeavePolicy, EmployeeLeavePolicyAssignment, EmployeeLeaveBalanceAccount} */
function periodFinalizationFixture(array $policyOverrides = []): array
{
    $company = Company::factory()->create(['timezone' => 'Asia/Kolkata']);
    $employee = Employee::factory()->create(['company_id' => $company->id]);
    $type = LeaveType::factory()->create(['company_id' => $company->id]);
    $policy = LeavePolicy::factory()->create(array_merge([
        'company_id' => $company->id,
        'leave_type_id' => $type->id,
        'accrual_method' => LeaveAccrualMethod::None,
        'accrual_frequency' => LeaveAccrualFrequency::None,
    ], $policyOverrides));
    $assignment = EmployeeLeavePolicyAssignment::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'leave_policy_id' => $policy->id,
        'effective_from' => '2026-01-01',
        'effective_to' => '2026-12-31',
    ]);
    $account = $company->leaveBalanceAccounts()->create([
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'leave_policy_id' => $policy->id,
        'employee_leave_policy_assignment_id' => $assignment->id,
        'period_start' => '2026-01-01',
        'period_end' => '2026-12-31',
        'status' => LeaveBalanceAccountStatus::Open,
    ]);

    return [$company, $employee, $type, $policy, $assignment, $account];
}

function periodFinalizationNextAccount(
    Company $company,
    Employee $employee,
    LeaveType $type,
    array $policyOverrides = [],
    LeaveBalanceAccountStatus $status = LeaveBalanceAccountStatus::Open,
): EmployeeLeaveBalanceAccount {
    $policy = LeavePolicy::factory()->create(array_merge([
        'company_id' => $company->id,
        'leave_type_id' => $type->id,
        'accrual_method' => LeaveAccrualMethod::None,
        'accrual_frequency' => LeaveAccrualFrequency::None,
    ], $policyOverrides));
    $assignment = EmployeeLeavePolicyAssignment::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'leave_policy_id' => $policy->id,
        'effective_from' => '2027-01-01',
        'effective_to' => '2027-12-31',
    ]);

    return $company->leaveBalanceAccounts()->create([
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'leave_policy_id' => $policy->id,
        'employee_leave_policy_assignment_id' => $assignment->id,
        'period_start' => '2027-01-01',
        'period_end' => '2027-12-31',
        'status' => $status,
    ]);
}

function periodFinalizationOpening(EmployeeLeaveBalanceAccount $account, string $amount): LeaveLedgerEntry
{
    return $account->ledgerEntries()->create([
        'company_id' => $account->company_id,
        'employee_id' => $account->employee_id,
        'leave_type_id' => $account->leave_type_id,
        'leave_policy_id' => $account->leave_policy_id,
        'employee_leave_policy_assignment_id' => $account->employee_leave_policy_assignment_id,
        'transaction_type' => LeaveLedgerTransactionType::OpeningBalance,
        'amount' => $amount,
        'effective_date' => $account->period_start->toDateString(),
        'description' => 'Opening balance fixture',
    ]);
}

test('an eligible zero balance account closes without creating closing entries and retries idempotently', function () {
    [, , , , , $account] = periodFinalizationFixture();
    $actor = User::factory()->create();

    $result = app(FinalizeLeavePeriod::class)->execute($account, CarbonImmutable::parse('2026-12-31'), $actor);
    $closedAt = $result->sourceAccount->updated_at;
    $retry = app(FinalizeLeavePeriod::class)->execute($result->sourceAccount, CarbonImmutable::parse('2026-12-31'));

    expect(fn () => $result->sourceAccount->update(['status' => LeaveBalanceAccountStatus::Open]))
        ->toThrow(LogicException::class);
    $result->sourceAccount->refresh();

    expect($result->wasClosedNow)->toBeTrue()
        ->and($result->sourceAccount->status)->toBe(LeaveBalanceAccountStatus::Closed)
        ->and($result->sourceAccount->updated_by)->toBe($actor->id)
        ->and($result->sourceFinalLedgerBalance)->toBe('0.00')
        ->and($account->ledgerEntries()->count())->toBe(0)
        ->and($retry->alreadyFinalized)->toBeTrue()
        ->and($retry->wasClosedNow)->toBeFalse()
        ->and($retry->sourceAccount->updated_at->equalTo($closedAt))->toBeTrue();
});

test('expiry is posted before closure and the source retains its carried remainder', function () {
    [, , , , , $account] = periodFinalizationFixture(['carry_forward_enabled' => false]);
    periodFinalizationOpening($account, '8.00');

    $result = app(FinalizeLeavePeriod::class)->execute($account, CarbonImmutable::parse('2026-12-31'));

    expect($result->sourceAccount->status)->toBe(LeaveBalanceAccountStatus::Closed)
        ->and($result->expiryLedgerEntry?->amount)->toBe('-8.00')
        ->and($result->expiryLedgerEntry?->effective_date->toDateString())->toBe('2026-12-31')
        ->and($result->sourceFinalLedgerBalance)->toBe('0.00')
        ->and($result->destinationAccount)->toBeNull();
});

test('carry and expiry are atomically applied then source closes with exact ledger balances', function () {
    [$company, $employee, $type, , , $source] = periodFinalizationFixture([
        'carry_forward_enabled' => true,
        'max_carry_forward_days' => '5.00',
    ]);
    $destination = periodFinalizationNextAccount($company, $employee, $type);
    periodFinalizationOpening($source, '8.00');

    $result = app(FinalizeLeavePeriod::class)->execute($source, CarbonImmutable::parse('2026-12-31'));

    expect($result->carryForwardAmount)->toBe('5.00')
        ->and($result->expiryAmount)->toBe('3.00')
        ->and($result->carryForwardLedgerEntry?->amount)->toBe('5.00')
        ->and($result->expiryLedgerEntry?->amount)->toBe('-3.00')
        ->and($result->sourceFinalLedgerBalance)->toBe('5.00')
        ->and($result->destinationResultingBalance)->toBe('5.00')
        ->and(app(LeaveBalanceCalculator::class)->calculate($destination)->balance)->toBe('5.00')
        ->and($result->sourceAccount->status)->toBe(LeaveBalanceAccountStatus::Closed);
});

test('a carry-forward and expiry already posted while open are verified and then closed without duplicates', function () {
    [$company, $employee, $type, , , $source] = periodFinalizationFixture([
        'carry_forward_enabled' => true,
        'max_carry_forward_days' => '5.00',
    ]);
    $destination = periodFinalizationNextAccount($company, $employee, $type);
    periodFinalizationOpening($source, '8.00');
    app(ApplyLeavePeriodClosingAccounting::class)->execute($source, CarbonImmutable::parse('2026-12-31'));
    $countBefore = LeaveLedgerEntry::query()->count();

    $result = app(FinalizeLeavePeriod::class)->execute($source, CarbonImmutable::parse('2026-12-31'));

    expect($result->sourceAccount->status)->toBe(LeaveBalanceAccountStatus::Closed)
        ->and(LeaveLedgerEntry::query()->count())->toBe($countBefore)
        ->and($destination->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::CarryForward->value)->count())->toBe(1);
});

test('missing required destination fails without changing source state or ledger', function () {
    [, , , , , $source] = periodFinalizationFixture([
        'carry_forward_enabled' => true,
        'max_carry_forward_days' => '5.00',
    ]);
    periodFinalizationOpening($source, '8.00');
    $countBefore = LeaveLedgerEntry::query()->count();

    try {
        app(FinalizeLeavePeriod::class)->execute($source, CarbonImmutable::parse('2026-12-31'));
        test()->fail('Expected finalization to require the exact destination account.');
    } catch (LeavePeriodFinalizationException $exception) {
        expect($exception->reason)->toBe('NEXT_PERIOD_ACCOUNT_REQUIRED');
    }

    expect($source->fresh()->status)->toBe(LeaveBalanceAccountStatus::Open)
        ->and(LeaveLedgerEntry::query()->count())->toBe($countBefore);
});

test('a destination balance cap failure rolls back closing entries and leaves source open', function () {
    [$company, $employee, $type, , , $source] = periodFinalizationFixture([
        'carry_forward_enabled' => true,
        'max_carry_forward_days' => '5.00',
    ]);
    $destination = periodFinalizationNextAccount($company, $employee, $type, ['max_balance_days' => '4.00']);
    periodFinalizationOpening($source, '8.00');
    $countBefore = LeaveLedgerEntry::query()->count();

    expect(fn () => app(FinalizeLeavePeriod::class)->execute($source, CarbonImmutable::parse('2026-12-31')))
        ->toThrow(LeavePeriodFinalizationException::class);

    expect($source->fresh()->status)->toBe(LeaveBalanceAccountStatus::Open)
        ->and(LeaveLedgerEntry::query()->count())->toBe($countBefore)
        ->and($destination->ledgerEntries()->count())->toBe(0);
});

test('a closed destination prevents carry finalization without closing the source', function () {
    [$company, $employee, $type, , , $source] = periodFinalizationFixture([
        'carry_forward_enabled' => true,
        'max_carry_forward_days' => '5.00',
    ]);
    $destination = periodFinalizationNextAccount($company, $employee, $type, [], LeaveBalanceAccountStatus::Closed);
    periodFinalizationOpening($source, '8.00');
    $count = LeaveLedgerEntry::query()->count();

    expect(fn () => app(FinalizeLeavePeriod::class)->execute($source, CarbonImmutable::parse('2026-12-31')))
        ->toThrow(LeavePeriodFinalizationException::class, 'NEXT_PERIOD_ACCOUNT_CLOSED');

    expect($source->fresh()->status)->toBe(LeaveBalanceAccountStatus::Open)
        ->and($destination->ledgerEntries()->count())->toBe(0)
        ->and(LeaveLedgerEntry::query()->count())->toBe($count);
});

test('a failed lifecycle transition rolls back new carry and expiry accounting', function () {
    [$company, $employee, $type, , , $source] = periodFinalizationFixture([
        'carry_forward_enabled' => true,
        'max_carry_forward_days' => '5.00',
    ]);
    $destination = periodFinalizationNextAccount($company, $employee, $type);
    periodFinalizationOpening($source, '8.00');
    $countBefore = LeaveLedgerEntry::query()->count();
    $destinationBalanceBefore = app(LeaveBalanceCalculator::class)->calculate($destination)->balance;
    $closer = Mockery::mock(CloseLeaveBalanceAccount::class)->makePartial();
    $closer->shouldReceive('closeLockedAccount')->once()->andThrow(new RuntimeException('Transition rejected.'));
    app()->instance(CloseLeaveBalanceAccount::class, $closer);

    expect(fn () => app(FinalizeLeavePeriod::class)->execute($source, CarbonImmutable::parse('2026-12-31')))
        ->toThrow(RuntimeException::class, 'Transition rejected.');

    expect($source->fresh()->status)->toBe(LeaveBalanceAccountStatus::Open)
        ->and(LeaveLedgerEntry::query()->count())->toBe($countBefore)
        ->and(app(LeaveBalanceCalculator::class)->calculate($destination)->balance)->toBe($destinationBalanceBefore);
});

test('carry-only and decimal carry-plus-expiry finalization preserve exact amounts', function () {
    [$company, $employee, $type, , , $carryOnlySource] = periodFinalizationFixture([
        'carry_forward_enabled' => true,
        'max_carry_forward_days' => '5.00',
    ]);
    $carryDestination = periodFinalizationNextAccount($company, $employee, $type);
    periodFinalizationOpening($carryOnlySource, '5.00');
    $carryOnly = app(FinalizeLeavePeriod::class)->execute($carryOnlySource, CarbonImmutable::parse('2026-12-31'));

    [$decimalCompany, $decimalEmployee, $decimalType, , , $decimalSource] = periodFinalizationFixture([
        'carry_forward_enabled' => true,
        'max_carry_forward_days' => '5.50',
    ]);
    $decimalDestination = periodFinalizationNextAccount($decimalCompany, $decimalEmployee, $decimalType);
    periodFinalizationOpening($decimalSource, '8.25');
    $decimal = app(FinalizeLeavePeriod::class)->execute($decimalSource, CarbonImmutable::parse('2026-12-31'));

    expect($carryOnly->carryForwardAmount)->toBe('5.00')
        ->and($carryOnly->expiryAmount)->toBe('0.00')
        ->and($carryOnly->expiryLedgerEntry)->toBeNull()
        ->and($carryOnly->destinationAccount?->is($carryDestination))->toBeTrue()
        ->and($decimal->carryForwardAmount)->toBe('5.50')
        ->and($decimal->expiryAmount)->toBe('2.75')
        ->and($decimal->carryForwardLedgerEntry?->amount)->toBe('5.50')
        ->and($decimal->expiryLedgerEntry?->amount)->toBe('-2.75')
        ->and($decimal->sourceFinalLedgerBalance)->toBe('5.50')
        ->and($decimal->destinationResultingBalance)->toBe('5.50')
        ->and($decimalDestination->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::CarryForward->value)->count())->toBe(1);
});

test('matching partial closing accounting is completed before the account is closed', function () {
    [$company, $employee, $type, , , $carryFirstSource] = periodFinalizationFixture([
        'carry_forward_enabled' => true,
        'max_carry_forward_days' => '5.00',
    ]);
    $carryDestination = periodFinalizationNextAccount($company, $employee, $type);
    periodFinalizationOpening($carryFirstSource, '8.00');
    $carryDestination->ledgerEntries()->create([
        'company_id' => $carryDestination->company_id,
        'employee_id' => $carryDestination->employee_id,
        'leave_type_id' => $carryDestination->leave_type_id,
        'leave_policy_id' => $carryDestination->leave_policy_id,
        'employee_leave_policy_assignment_id' => $carryDestination->employee_leave_policy_assignment_id,
        'transaction_type' => LeaveLedgerTransactionType::CarryForward,
        'amount' => '5.00',
        'effective_date' => '2027-01-01',
        'description' => 'Leave carry forward from period ending 2026-12-31',
        'reference_type' => 'leave_period_closing',
        'reference_id' => $carryFirstSource->id,
    ]);

    $completedCarry = app(FinalizeLeavePeriod::class)->execute($carryFirstSource, CarbonImmutable::parse('2026-12-31'));

    [$expiryCompany, $expiryEmployee, $expiryType, , , $expiryFirstSource] = periodFinalizationFixture([
        'carry_forward_enabled' => true,
        'max_carry_forward_days' => '5.00',
    ]);
    $expiryDestination = periodFinalizationNextAccount($expiryCompany, $expiryEmployee, $expiryType);
    periodFinalizationOpening($expiryFirstSource, '8.00');
    $expiryFirstSource->ledgerEntries()->create([
        'company_id' => $expiryFirstSource->company_id,
        'employee_id' => $expiryFirstSource->employee_id,
        'leave_type_id' => $expiryFirstSource->leave_type_id,
        'leave_policy_id' => $expiryFirstSource->leave_policy_id,
        'employee_leave_policy_assignment_id' => $expiryFirstSource->employee_leave_policy_assignment_id,
        'transaction_type' => LeaveLedgerTransactionType::Expiry,
        'amount' => '-3.00',
        'effective_date' => '2026-12-31',
        'description' => 'Leave balance expiry for period ending 2026-12-31',
        'reference_type' => 'leave_period_closing',
        'reference_id' => $expiryFirstSource->id,
    ]);

    $completedExpiry = app(FinalizeLeavePeriod::class)->execute($expiryFirstSource, CarbonImmutable::parse('2026-12-31'));

    expect($completedCarry->sourceAccount->status)->toBe(LeaveBalanceAccountStatus::Closed)
        ->and($completedCarry->carryForwardLedgerEntry)->not->toBeNull()
        ->and($completedCarry->expiryLedgerEntry?->amount)->toBe('-3.00')
        ->and($carryDestination->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::CarryForward->value)->count())->toBe(1)
        ->and($completedExpiry->sourceAccount->status)->toBe(LeaveBalanceAccountStatus::Closed)
        ->and($completedExpiry->carryForwardLedgerEntry?->amount)->toBe('5.00')
        ->and($completedExpiry->expiryLedgerEntry)->not->toBeNull()
        ->and($expiryDestination->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::CarryForward->value)->count())->toBe(1);
});

test('mismatched carry or expiry accounting fails without closing or appending corrections', function () {
    [$company, $employee, $type, , , $carrySource] = periodFinalizationFixture([
        'carry_forward_enabled' => true,
        'max_carry_forward_days' => '5.00',
    ]);
    $carryDestination = periodFinalizationNextAccount($company, $employee, $type);
    periodFinalizationOpening($carrySource, '8.00');
    $carryDestination->ledgerEntries()->create([
        'company_id' => $carryDestination->company_id,
        'employee_id' => $carryDestination->employee_id,
        'leave_type_id' => $carryDestination->leave_type_id,
        'leave_policy_id' => $carryDestination->leave_policy_id,
        'employee_leave_policy_assignment_id' => $carryDestination->employee_leave_policy_assignment_id,
        'transaction_type' => LeaveLedgerTransactionType::CarryForward,
        'amount' => '4.00',
        'effective_date' => '2027-01-01',
        'description' => 'Mismatched closing fixture',
        'reference_type' => 'leave_period_closing',
        'reference_id' => $carrySource->id,
    ]);
    $carryCount = LeaveLedgerEntry::query()->count();

    expect(fn () => app(FinalizeLeavePeriod::class)->execute($carrySource, CarbonImmutable::parse('2026-12-31')))
        ->toThrow(LeavePeriodFinalizationException::class, 'CARRY_FORWARD_ALREADY_MISMATCHED');

    expect($carrySource->fresh()->status)->toBe(LeaveBalanceAccountStatus::Open)
        ->and(LeaveLedgerEntry::query()->count())->toBe($carryCount);

    [, , , , , $expirySource] = periodFinalizationFixture(['carry_forward_enabled' => false]);
    periodFinalizationOpening($expirySource, '8.00');
    $expirySource->ledgerEntries()->create([
        'company_id' => $expirySource->company_id,
        'employee_id' => $expirySource->employee_id,
        'leave_type_id' => $expirySource->leave_type_id,
        'leave_policy_id' => $expirySource->leave_policy_id,
        'employee_leave_policy_assignment_id' => $expirySource->employee_leave_policy_assignment_id,
        'transaction_type' => LeaveLedgerTransactionType::Expiry,
        'amount' => '-7.00',
        'effective_date' => '2026-12-31',
        'description' => 'Mismatched closing fixture',
        'reference_type' => 'leave_period_closing',
        'reference_id' => $expirySource->id,
    ]);
    $expiryCount = LeaveLedgerEntry::query()->count();

    expect(fn () => app(FinalizeLeavePeriod::class)->execute($expirySource, CarbonImmutable::parse('2026-12-31')))
        ->toThrow(LeavePeriodFinalizationException::class, 'EXPIRY_ALREADY_MISMATCHED');

    expect($expirySource->fresh()->status)->toBe(LeaveBalanceAccountStatus::Open)
        ->and(LeaveLedgerEntry::query()->count())->toBe($expiryCount);
});

test('a closed account with consistent historical closing entries returns idempotent success', function () {
    [$company, $employee, $type, , , $source] = periodFinalizationFixture([
        'carry_forward_enabled' => true,
        'max_carry_forward_days' => '5.00',
    ]);
    periodFinalizationNextAccount($company, $employee, $type);
    periodFinalizationOpening($source, '8.00');
    $first = app(FinalizeLeavePeriod::class)->execute($source, CarbonImmutable::parse('2026-12-31'));
    $count = LeaveLedgerEntry::query()->count();

    $retry = app(FinalizeLeavePeriod::class)->execute($first->sourceAccount, CarbonImmutable::parse('2026-12-31'));

    expect($retry->alreadyFinalized)->toBeTrue()
        ->and($retry->isUpToDate)->toBeTrue()
        ->and(LeaveLedgerEntry::query()->count())->toBe($count);
});

test('closed account with mismatched closing history fails without repairing the ledger', function () {
    [, , , , , $source] = periodFinalizationFixture(['carry_forward_enabled' => false]);
    periodFinalizationOpening($source, '8.00');
    DB::table('employee_leave_balance_accounts')->where('id', $source->id)->update(['status' => LeaveBalanceAccountStatus::Closed->value]);
    $entry = $source->ledgerEntries()->create([
        'company_id' => $source->company_id,
        'employee_id' => $source->employee_id,
        'leave_type_id' => $source->leave_type_id,
        'leave_policy_id' => $source->leave_policy_id,
        'employee_leave_policy_assignment_id' => $source->employee_leave_policy_assignment_id,
        'transaction_type' => LeaveLedgerTransactionType::Expiry,
        'amount' => '-7.00',
        'effective_date' => '2026-12-31',
        'description' => 'Closing fixture',
        'reference_type' => 'leave_period_closing',
        'reference_id' => $source->id,
    ]);
    $count = LeaveLedgerEntry::query()->count();

    expect(fn () => app(FinalizeLeavePeriod::class)->execute($source, CarbonImmutable::parse('2026-12-31')))
        ->toThrow(LeavePeriodFinalizationException::class, 'CLOSED_ACCOUNT_ACCOUNTING_INCONSISTENT');

    expect(LeaveLedgerEntry::query()->count())->toBe($count)
        ->and((string) DB::table('leave_ledger_entries')->where('id', $entry->id)->value('amount'))->toBe('-7');
});

test('finalization rejects a period before its final date and leaves account open', function () {
    [, , , , , $source] = periodFinalizationFixture();

    expect(fn () => app(FinalizeLeavePeriod::class)->execute($source, CarbonImmutable::parse('2026-12-30')))
        ->toThrow(LeavePeriodFinalizationException::class, 'CLOSING_NOT_ELIGIBLE');

    expect($source->fresh()->status)->toBe(LeaveBalanceAccountStatus::Open);
});

test('the legacy close action cannot bypass period eligibility or closing accounting', function () {
    [, , , , , $source] = periodFinalizationFixture();
    $actor = User::factory()->create();

    expect(fn () => app(CloseLeaveBalanceAccount::class)->handle($source, $actor))
        ->toThrow(LeavePeriodFinalizationException::class, 'CLOSING_NOT_ELIGIBLE');

    expect($source->fresh()->status)->toBe(LeaveBalanceAccountStatus::Open)
        ->and($source->ledgerEntries()->count())->toBe(0);
});

test('pending reservations prevent finalization without ledger or status changes', function () {
    [, $employee, $type, $policy, $assignment, $source] = periodFinalizationFixture(['carry_forward_enabled' => false]);
    periodFinalizationOpening($source, '4.00');
    LeaveRequest::factory()->create([
        'company_id' => $source->company_id,
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'leave_policy_id' => $policy->id,
        'employee_leave_policy_assignment_id' => $assignment->id,
        'leave_balance_account_id' => $source->id,
        'requested_by' => User::factory()->create()->id,
        'requested_days' => '1.00',
        'status' => LeaveRequestStatus::Pending,
    ]);
    $count = LeaveLedgerEntry::query()->count();

    expect(fn () => app(FinalizeLeavePeriod::class)->execute($source, CarbonImmutable::parse('2026-12-31')))
        ->toThrow(LeavePeriodFinalizationException::class, 'CLOSING_NOT_ELIGIBLE');

    expect($source->fresh()->status)->toBe(LeaveBalanceAccountStatus::Open)
        ->and(LeaveLedgerEntry::query()->count())->toBe($count);
});

test('missing accrual, over-accrual, unsupported frequency and negative balance prevent finalization', function () {
    [, , , , , $missingAccrual] = periodFinalizationFixture([
        'accrual_method' => LeaveAccrualMethod::Accrual,
        'accrual_frequency' => LeaveAccrualFrequency::Monthly,
    ]);
    $missingAccrual->ledgerEntries()->create([
        'company_id' => $missingAccrual->company_id,
        'employee_id' => $missingAccrual->employee_id,
        'leave_type_id' => $missingAccrual->leave_type_id,
        'leave_policy_id' => $missingAccrual->leave_policy_id,
        'employee_leave_policy_assignment_id' => $missingAccrual->employee_leave_policy_assignment_id,
        'transaction_type' => LeaveLedgerTransactionType::OpeningBalance,
        'amount' => '2.00',
        'effective_date' => '2026-01-01',
        'description' => 'Opening balance fixture',
    ]);

    [, , , , , $overAccrued] = periodFinalizationFixture([
        'accrual_method' => LeaveAccrualMethod::Upfront,
        'entitlement_days' => '12.00',
    ]);
    $overAccrued->ledgerEntries()->create([
        'company_id' => $overAccrued->company_id,
        'employee_id' => $overAccrued->employee_id,
        'leave_type_id' => $overAccrued->leave_type_id,
        'leave_policy_id' => $overAccrued->leave_policy_id,
        'employee_leave_policy_assignment_id' => $overAccrued->employee_leave_policy_assignment_id,
        'transaction_type' => LeaveLedgerTransactionType::Accrual,
        'amount' => '13.00',
        'effective_date' => '2026-01-01',
        'description' => 'Corrupt over-accrual fixture',
    ]);

    [, , , , , $unsupported] = periodFinalizationFixture([
        'accrual_method' => LeaveAccrualMethod::Accrual,
        'accrual_frequency' => LeaveAccrualFrequency::Quarterly,
    ]);

    [, , , , , $negative] = periodFinalizationFixture([
        'carry_forward_enabled' => false,
        'allow_negative_balance' => true,
        'max_negative_balance_days' => '2.00',
    ]);
    periodFinalizationOpening($negative, '-1.00');

    foreach ([$missingAccrual, $overAccrued, $unsupported, $negative] as $account) {
        expect(fn () => app(FinalizeLeavePeriod::class)->execute($account, CarbonImmutable::parse('2026-12-31')))
            ->toThrow(LeavePeriodFinalizationException::class, 'CLOSING_NOT_ELIGIBLE');
        expect($account->fresh()->status)->toBe(LeaveBalanceAccountStatus::Open);
    }
});

test('an overlapping next-period account blocks finalization and inactive historical references remain valid', function () {
    [$company, $employee, $type, $policy, $assignment, $source] = periodFinalizationFixture([
        'carry_forward_enabled' => true,
        'max_carry_forward_days' => '5.00',
    ]);
    periodFinalizationOpening($source, '8.00');
    periodFinalizationNextAccount($company, $employee, $type);
    $company->leaveBalanceAccounts()->create([
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'leave_policy_id' => $policy->id,
        'employee_leave_policy_assignment_id' => $assignment->id,
        'period_start' => '2027-02-01',
        'period_end' => '2027-12-31',
        'status' => LeaveBalanceAccountStatus::Open,
    ]);

    expect(fn () => app(FinalizeLeavePeriod::class)->execute($source, CarbonImmutable::parse('2026-12-31')))
        ->toThrow(LeavePeriodFinalizationException::class, 'CLOSING_NOT_ELIGIBLE');
    expect($source->fresh()->status)->toBe(LeaveBalanceAccountStatus::Open);

    [, , $inactiveType, $inactivePolicy, , $inactiveAccount] = periodFinalizationFixture(['carry_forward_enabled' => false]);
    $inactivePolicy->update(['status' => 'inactive']);
    $inactiveType->update(['status' => 'inactive']);
    periodFinalizationOpening($inactiveAccount, '1.00');
    $result = app(FinalizeLeavePeriod::class)->execute($inactiveAccount, CarbonImmutable::parse('2026-12-31'));

    expect($result->sourceAccount->status)->toBe(LeaveBalanceAccountStatus::Closed)
        ->and($result->expiryAmount)->toBe('1.00');
});
