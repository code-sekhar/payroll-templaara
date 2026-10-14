<?php

use App\Actions\LeaveBalances\ApplyLeavePeriodClosingAccounting;
use App\Actions\LeaveBalances\PostLeaveLedgerEntry;
use App\Enums\LeaveAccrualFrequency;
use App\Enums\LeaveAccrualMethod;
use App\Enums\LeaveBalanceAccountStatus;
use App\Enums\LeaveLedgerTransactionType;
use App\Enums\LeavePeriodClosingBlocker;
use App\Enums\LeavePolicyStatus;
use App\Enums\LeaveTypeStatus;
use App\Exceptions\Leave\LeavePeriodClosingAccountingException;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeLeaveBalanceAccount;
use App\Models\EmployeeLeavePolicyAssignment;
use App\Models\LeaveLedgerEntry;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Leave\LeaveAmount;
use App\Services\Leave\LeaveBalanceCalculator;
use App\Services\Leave\LeavePeriodClosingCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/** @return array{Company, Employee, LeaveType, LeavePolicy, EmployeeLeavePolicyAssignment, EmployeeLeaveBalanceAccount} */
function closingAccountingFixture(array $policyAttributes = []): array
{
    $company = Company::factory()->create(['timezone' => 'Asia/Kolkata']);
    $employee = Employee::factory()->create(['company_id' => $company->id]);
    $type = LeaveType::factory()->create(['company_id' => $company->id]);
    $policy = LeavePolicy::factory()->create(array_merge([
        'company_id' => $company->id,
        'leave_type_id' => $type->id,
        'accrual_method' => LeaveAccrualMethod::None,
        'accrual_frequency' => LeaveAccrualFrequency::None,
    ], $policyAttributes));
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

function closingLedgerEntry(EmployeeLeaveBalanceAccount $account, LeaveLedgerTransactionType $type, string $amount, ?int $referenceId = null, ?string $date = null): LeaveLedgerEntry
{
    return $account->ledgerEntries()->create([
        'company_id' => $account->company_id,
        'employee_id' => $account->employee_id,
        'leave_type_id' => $account->leave_type_id,
        'leave_policy_id' => $account->leave_policy_id,
        'employee_leave_policy_assignment_id' => $account->employee_leave_policy_assignment_id,
        'transaction_type' => $type,
        'amount' => $amount,
        'effective_date' => $date ?? $account->period_end->toDateString(),
        'description' => 'Closing fixture',
        'reference_type' => 'leave_period_closing',
        'reference_id' => $referenceId ?? $account->id,
        'created_at' => now(),
    ]);
}

function createClosingNextAccount(Company $company, Employee $employee, LeaveType $type, array $policyAttributes = [], LeaveBalanceAccountStatus $status = LeaveBalanceAccountStatus::Open): EmployeeLeaveBalanceAccount
{
    $policy = LeavePolicy::factory()->create(array_merge([
        'company_id' => $company->id,
        'leave_type_id' => $type->id,
        'accrual_method' => LeaveAccrualMethod::None,
        'accrual_frequency' => LeaveAccrualFrequency::None,
    ], $policyAttributes));
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

function seedClosingBalance(EmployeeLeaveBalanceAccount $account, string $amount): LeaveLedgerEntry
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
        'created_at' => now(),
    ]);
}

function executeClosingAccounting(EmployeeLeaveBalanceAccount $account, ?User $actor = null)
{
    return app(ApplyLeavePeriodClosingAccounting::class)->execute($account, CarbonImmutable::parse('2026-12-31'), $actor);
}

test('the Part 1 enum path is correct and the existing read-only preview remains usable', function () {
    [, , , , , $account] = closingAccountingFixture();
    $preview = app(LeavePeriodClosingCalculator::class)->calculate($account, CarbonImmutable::parse('2026-12-31'));

    expect((new ReflectionClass(LeavePeriodClosingBlocker::class))->getFileName())
        ->toEndWith('/app/Enums/LeavePeriodClosingBlocker.php')
        ->and($preview->eligibleToClose)->toBeTrue();
});

test('zero closing amounts create no ledger entries on either run', function () {
    [, , , , , $account] = closingAccountingFixture();

    $first = executeClosingAccounting($account);
    $again = executeClosingAccounting($account);

    expect($first->wasApplied)->toBeFalse()
        ->and($first->isUpToDate)->toBeTrue()
        ->and($again->wasApplied)->toBeFalse()
        ->and($account->ledgerEntries()->count())->toBe(0);
});

test('expiry-only writes a negative source entry once and preview retains the pre-expiry balance', function () {
    [, , , , , $account] = closingAccountingFixture();
    seedClosingBalance($account, '8.00');

    $first = executeClosingAccounting($account);
    $again = executeClosingAccounting($account);
    $preview = app(LeavePeriodClosingCalculator::class)->calculate($account, CarbonImmutable::parse('2026-12-31'));

    expect($first->expiryAmount)->toBe('8.00')
        ->and($first->expiryLedgerEntry?->amount)->toBe('-8.00')
        ->and($first->expiryLedgerEntry?->effective_date->toDateString())->toBe('2026-12-31')
        ->and($first->expiryLedgerEntry?->created_by)->toBeNull()
        ->and($again->wasApplied)->toBeFalse()
        ->and($preview->closingBalance)->toBe('8.00')
        ->and($account->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::Expiry->value)->count())->toBe(1);
});

test('carry-only posts positive carry to the exact open next account', function () {
    [$company, $employee, $type, , , $source] = closingAccountingFixture([
        'carry_forward_enabled' => true,
        'max_carry_forward_days' => '5.00',
    ]);
    seedClosingBalance($source, '5.00');
    $destination = createClosingNextAccount($company, $employee, $type);

    $result = executeClosingAccounting($source);

    expect($result->carryForwardLedgerEntry?->amount)->toBe('5.00')
        ->and($result->carryForwardLedgerEntry?->transaction_type)->toBe(LeaveLedgerTransactionType::CarryForward)
        ->and($result->carryForwardLedgerEntry?->effective_date->toDateString())->toBe('2027-01-01')
        ->and($result->carryForwardLedgerEntry?->reference_type)->toBe('leave_period_closing')
        ->and((int) $result->carryForwardLedgerEntry?->reference_id)->toBe((int) $source->id)
        ->and($result->nextAccount?->is($destination))->toBeTrue()
        ->and($destination->ledgerEntries()->count())->toBe(1);
});

test('carry plus expiry posts exact 5.00 and 3.00 amounts idempotently', function () {
    [$company, $employee, $type, , , $source] = closingAccountingFixture([
        'carry_forward_enabled' => true,
        'max_carry_forward_days' => '5.00',
    ]);
    seedClosingBalance($source, '8.00');
    $destination = createClosingNextAccount($company, $employee, $type);

    $first = executeClosingAccounting($source);
    $second = executeClosingAccounting($source);

    expect($first->closingPreview->closingBalance)->toBe('8.00')
        ->and($first->carryForwardLedgerEntry?->amount)->toBe('5.00')
        ->and($first->expiryLedgerEntry?->amount)->toBe('-3.00')
        ->and($first->wasApplied)->toBeTrue()
        ->and($second->wasApplied)->toBeFalse()
        ->and($second->closingPreview->closingBalance)->toBe('8.00')
        ->and($source->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::Expiry->value)->count())->toBe(1)
        ->and($destination->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::CarryForward->value)->count())->toBe(1)
        ->and(app(LeaveBalanceCalculator::class)->calculate($source)->balance)->toBe('5.00')
        ->and(app(LeaveBalanceCalculator::class)->calculate($destination)->balance)->toBe('5.00');
});

test('decimal carry and expiry preserve exact hundredths', function () {
    [$company, $employee, $type, , , $source] = closingAccountingFixture([
        'carry_forward_enabled' => true,
        'max_carry_forward_days' => '5.50',
    ]);
    seedClosingBalance($source, '8.25');
    $destination = createClosingNextAccount($company, $employee, $type);

    $result = executeClosingAccounting($source);

    expect($result->carryForwardAmount)->toBe('5.50')
        ->and($result->expiryAmount)->toBe('2.75')
        ->and($result->carryForwardLedgerEntry?->amount)->toBe('5.50')
        ->and($result->expiryLedgerEntry?->amount)->toBe('-2.75')
        ->and(LeaveAmount::toCents((string) $result->carryForwardLedgerEntry?->amount)
            + abs(LeaveAmount::toCents((string) $result->expiryLedgerEntry?->amount)))->toBe(825)
        ->and($destination->ledgerEntries()->count())->toBe(1);
});

test('matching carry is retained and only missing expiry is posted', function () {
    [$company, $employee, $type, , , $source] = closingAccountingFixture([
        'carry_forward_enabled' => true,
        'max_carry_forward_days' => '5.00',
    ]);
    seedClosingBalance($source, '8.00');
    $destination = createClosingNextAccount($company, $employee, $type);
    $existingCarry = closingLedgerEntry($destination, LeaveLedgerTransactionType::CarryForward, '5.00', $source->id, '2027-01-01');

    $result = executeClosingAccounting($source);

    expect($result->carryForwardLedgerEntry?->is($existingCarry))->toBeTrue()
        ->and($result->expiryLedgerEntry?->amount)->toBe('-3.00')
        ->and($result->wasApplied)->toBeTrue();
});

test('matching expiry is retained and only missing carry is posted', function () {
    [$company, $employee, $type, , , $source] = closingAccountingFixture([
        'carry_forward_enabled' => true,
        'max_carry_forward_days' => '5.00',
    ]);
    seedClosingBalance($source, '8.00');
    $destination = createClosingNextAccount($company, $employee, $type);
    $existingExpiry = closingLedgerEntry($source, LeaveLedgerTransactionType::Expiry, '-3.00');

    $result = executeClosingAccounting($source);

    expect($result->expiryLedgerEntry?->is($existingExpiry))->toBeTrue()
        ->and($result->carryForwardLedgerEntry?->amount)->toBe('5.00')
        ->and($result->wasApplied)->toBeTrue()
        ->and($destination->ledgerEntries()->count())->toBe(1);
});

test('mismatched existing closing entries fail without appending corrective rows', function (string $kind) {
    [$company, $employee, $type, , , $source] = closingAccountingFixture([
        'carry_forward_enabled' => true,
        'max_carry_forward_days' => '5.00',
    ]);
    seedClosingBalance($source, '8.00');
    $destination = createClosingNextAccount($company, $employee, $type);
    if ($kind === 'carry') {
        $existing = closingLedgerEntry($destination, LeaveLedgerTransactionType::CarryForward, '4.00', $source->id, '2027-01-01');
        $reason = 'CARRY_FORWARD_ALREADY_MISMATCHED';
    } else {
        $existing = closingLedgerEntry($source, LeaveLedgerTransactionType::Expiry, '-2.00');
        $reason = 'EXPIRY_ALREADY_MISMATCHED';
    }

    try {
        executeClosingAccounting($source);
        test()->fail('A mismatched existing closing entry must fail.');
    } catch (LeavePeriodClosingAccountingException $exception) {
        expect($exception->reason)->toBe($reason);
    }

    expect($existing->fresh()->amount)->toBe($kind === 'carry' ? '4.00' : '-2.00')
        ->and($source->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::Expiry->value)->count())->toBe($kind === 'carry' ? 0 : 1)
        ->and($destination->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::CarryForward->value)->count())->toBe($kind === 'carry' ? 1 : 0);
})->with(['carry', 'expiry']);

test('carry requires an existing next account and refuses a closed destination', function () {
    [, , , , , $missingDestinationSource] = closingAccountingFixture([
        'carry_forward_enabled' => true,
        'max_carry_forward_days' => '5.00',
    ]);
    seedClosingBalance($missingDestinationSource, '5.00');
    try {
        executeClosingAccounting($missingDestinationSource);
        test()->fail('A missing destination must fail.');
    } catch (LeavePeriodClosingAccountingException $exception) {
        expect($exception->reason)->toBe('NEXT_PERIOD_ACCOUNT_REQUIRED');
    }

    [$company, $employee, $type, , , $closedSource] = closingAccountingFixture([
        'carry_forward_enabled' => true,
        'max_carry_forward_days' => '5.00',
    ]);
    seedClosingBalance($closedSource, '5.00');
    createClosingNextAccount($company, $employee, $type, status: LeaveBalanceAccountStatus::Closed);
    try {
        executeClosingAccounting($closedSource);
        test()->fail('A closed destination must fail.');
    } catch (LeavePeriodClosingAccountingException $exception) {
        expect($exception->reason)->toBe('NEXT_PERIOD_ACCOUNT_CLOSED');
    }

    expect($closedSource->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::Expiry->value)->count())->toBe(0);
});

test('destination policy balance cap is enforced atomically', function () {
    [$company, $employee, $type, , , $source] = closingAccountingFixture([
        'carry_forward_enabled' => true,
        'max_carry_forward_days' => '5.00',
    ]);
    seedClosingBalance($source, '8.00');
    $destination = createClosingNextAccount($company, $employee, $type, ['max_balance_days' => '4.99']);

    expect(fn () => executeClosingAccounting($source))
        ->toThrow(LeavePeriodClosingAccountingException::class, 'BALANCE_CAP_EXCEEDED');

    expect($source->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::Expiry->value)->count())->toBe(0)
        ->and($destination->ledgerEntries()->count())->toBe(0);
});

test('closing blockers prevent posting and report their stable codes', function () {
    [, , , , , $account] = closingAccountingFixture([
        'accrual_method' => LeaveAccrualMethod::Accrual,
        'accrual_frequency' => LeaveAccrualFrequency::Monthly,
    ]);
    seedClosingBalance($account, '8.00');

    try {
        executeClosingAccounting($account);
        test()->fail('Unreconciled accrual must block closing accounting.');
    } catch (LeavePeriodClosingAccountingException $exception) {
        expect($exception->reason)->toBe('CLOSING_NOT_ELIGIBLE')
            ->and($exception->blockers)->toContain(LeavePeriodClosingBlocker::AccrualNotFullyReconciled->value);
    }

    expect($account->ledgerEntries()->whereIn('transaction_type', [LeaveLedgerTransactionType::CarryForward->value, LeaveLedgerTransactionType::Expiry->value])->count())->toBe(0);
});

test('inactive historical source policy and leave type remain valid for accounting', function () {
    [$company, $employee, $type, $policy, , $source] = closingAccountingFixture([
        'carry_forward_enabled' => true,
        'max_carry_forward_days' => '5.00',
    ]);
    seedClosingBalance($source, '5.00');
    $policy->status = LeavePolicyStatus::Inactive;
    $policy->save();
    $type->status = LeaveTypeStatus::Inactive;
    $type->save();
    $destination = createClosingNextAccount($company, $employee, $type);

    $result = executeClosingAccounting($source);

    expect($result->carryForwardLedgerEntry?->amount)->toBe('5.00')
        ->and($destination->ledgerEntries()->count())->toBe(1);
});

test('new entries retain actor audit and do not close or mutate source account', function () {
    [, , , , , $account] = closingAccountingFixture();
    seedClosingBalance($account, '1.00');
    $actor = User::factory()->create();
    $sourceAttributes = $account->fresh()->getAttributes();

    $result = executeClosingAccounting($account, $actor);

    expect($result->expiryLedgerEntry?->created_by)->toBe($actor->id)
        ->and($account->fresh()->status)->toBe(LeaveBalanceAccountStatus::Open)
        ->and($account->fresh()->getAttributes())->toBe($sourceAttributes)
        ->and(EmployeeLeaveBalanceAccount::query()->count())->toBe(1);
});

test('manual ledger posting still rejects accrual carry-forward and expiry types', function (LeaveLedgerTransactionType $type) {
    [, , , , , $account] = closingAccountingFixture();
    $actor = User::factory()->create();

    expect(fn () => app(PostLeaveLedgerEntry::class)->handle($account, $actor, $type, '1.00', '2026-01-01', 'A manual posting'))
        ->toThrow(ValidationException::class);
})->with([
    LeaveLedgerTransactionType::Accrual,
    LeaveLedgerTransactionType::CarryForward,
    LeaveLedgerTransactionType::Expiry,
]);

test('a failure in the second posting rolls back the first posting', function () {
    [$company, $employee, $type, , , $source] = closingAccountingFixture([
        'carry_forward_enabled' => true,
        'max_carry_forward_days' => '5.00',
    ]);
    seedClosingBalance($source, '8.00');
    $destination = createClosingNextAccount($company, $employee, $type);
    $poster = Mockery::mock(PostLeaveLedgerEntry::class)->makePartial();
    $poster->shouldReceive('postPeriodClosingCarryForwardOnLockedAccount')->once()->passthru();
    $poster->shouldReceive('postPeriodClosingExpiryOnLockedAccount')->once()->andThrow(new RuntimeException('Simulated failure'));
    $action = new ApplyLeavePeriodClosingAccounting(app(LeavePeriodClosingCalculator::class), $poster);

    expect(fn () => $action->execute($source, CarbonImmutable::parse('2026-12-31')))
        ->toThrow(RuntimeException::class, 'Simulated failure');

    expect($destination->ledgerEntries()->count())->toBe(0)
        ->and($source->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::Expiry->value)->count())->toBe(0);
});
