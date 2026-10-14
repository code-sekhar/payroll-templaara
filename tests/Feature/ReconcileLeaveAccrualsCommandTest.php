<?php

use App\Enums\LeaveAccrualFrequency;
use App\Enums\LeaveAccrualMethod;
use App\Enums\LeaveBalanceAccountStatus;
use App\Enums\LeaveLedgerTransactionType;
use App\Enums\LeavePolicyStatus;
use App\Enums\LeaveTypeStatus;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeLeaveBalanceAccount;
use App\Models\EmployeeLeavePolicyAssignment;
use App\Models\LeaveLedgerEntry;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Carbon\CarbonImmutable;

/** @return array{Company, Employee, LeaveType, LeavePolicy, EmployeeLeavePolicyAssignment, EmployeeLeaveBalanceAccount} */
function commandAccrualFixture(
    array $policyAttributes = [],
    array $accountAttributes = [],
    ?Company $company = null,
): array {
    $company ??= Company::factory()->create(['timezone' => 'Asia/Kolkata']);
    $employee = Employee::factory()->create(['company_id' => $company->id]);
    $leaveType = LeaveType::factory()->create(['company_id' => $company->id]);
    $policy = LeavePolicy::factory()->create(array_merge([
        'company_id' => $company->id,
        'leave_type_id' => $leaveType->id,
        'entitlement_days' => '12.00',
        'accrual_method' => LeaveAccrualMethod::Accrual,
        'accrual_frequency' => LeaveAccrualFrequency::Monthly,
    ], $policyAttributes));
    $assignment = EmployeeLeavePolicyAssignment::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'leave_policy_id' => $policy->id,
        'effective_from' => '2026-01-01',
        'effective_to' => null,
    ]);
    $account = $company->leaveBalanceAccounts()->create(array_merge([
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'leave_policy_id' => $policy->id,
        'employee_leave_policy_assignment_id' => $assignment->id,
        'period_start' => '2026-01-01',
        'period_end' => '2026-12-31',
        'status' => LeaveBalanceAccountStatus::Open,
    ], $accountAttributes));

    return [$company, $employee, $leaveType, $policy, $assignment, $account];
}

function commandAccrualEntry(EmployeeLeaveBalanceAccount $account, LeaveLedgerTransactionType $type, string $amount): LeaveLedgerEntry
{
    return $account->ledgerEntries()->create([
        'company_id' => $account->company_id,
        'employee_id' => $account->employee_id,
        'leave_type_id' => $account->leave_type_id,
        'leave_policy_id' => $account->leave_policy_id,
        'employee_leave_policy_assignment_id' => $account->employee_leave_policy_assignment_id,
        'transaction_type' => $type,
        'amount' => $amount,
        'effective_date' => '2026-01-01',
        'description' => 'Existing command test ledger entry',
        'created_at' => now(),
    ]);
}

function runAccrualCommand(Company $company, array $options = [])
{
    return test()->artisan('leave:reconcile-accruals', array_merge(['--company' => $company->id], $options));
}

test('company is required and unknown companies fail before processing', function () {
    $this->artisan('leave:reconcile-accruals')->assertExitCode(2);
    $this->artisan('leave:reconcile-accruals', ['--company' => '999999'])->assertExitCode(2);
});

test('explicit as-of date requires strict format and a real calendar date', function (string $date) {
    $company = Company::factory()->create();

    runAccrualCommand($company, ['--as-of' => $date])->assertExitCode(2);
})->with([
    'non-ISO format' => '05/31/2026',
    'impossible month' => '2026-13-01',
    'impossible day' => '2026-02-30',
    'arbitrary text' => 'hello',
]);

test('omitted as-of uses the company local date', function () {
    [$company, , , , , $account] = commandAccrualFixture(
        ['entitlement_days' => '12.00'],
        ['period_start' => '2026-06-01', 'period_end' => '2027-05-31'],
    );
    $this->travelTo(CarbonImmutable::parse('2026-05-31 18:30:00', 'UTC'));
    runAccrualCommand($company)
        ->expectsOutputToContain('As of: 2026-06-01')
        ->expectsOutputToContain('Scanned: 1')
        ->expectsOutputToContain('Processed: 1')
        ->assertExitCode(0);

    expect($account->ledgerEntries()->count())->toBe(0);
    $this->travelBack();
});

test('only selected company open accounts are processed and inactive historical configuration remains eligible', function () {
    $companyA = Company::factory()->create(['timezone' => 'Asia/Kolkata']);
    $companyB = Company::factory()->create(['timezone' => 'Asia/Kolkata']);
    [$companyA, , $typeA, $policyA, $assignmentA, $accountA] = commandAccrualFixture(company: $companyA);
    [, , , , , $accountB] = commandAccrualFixture(company: $companyB);
    $policyA->update(['status' => LeavePolicyStatus::Inactive]);
    $typeA->update(['status' => LeaveTypeStatus::Inactive]);

    runAccrualCommand($companyA, ['--as-of' => '2026-05-31'])
        ->expectsOutputToContain('Scanned: 1')
        ->expectsOutputToContain('Posted: 1')
        ->assertExitCode(0);

    expect($accountA->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::Accrual->value)->first()->amount)->toBe('5.00')
        ->and($accountB->ledgerEntries()->count())->toBe(0)
        ->and($policyA->fresh()->status)->toBe(LeavePolicyStatus::Inactive)
        ->and($typeA->fresh()->status)->toBe(LeaveTypeStatus::Inactive)
        ->and($assignmentA->fresh()->employee_id)->toBe($accountA->employee_id);
});

test('normal selection includes ended open accounts and excludes closed future and none accounts', function () {
    [$company, , , , , $ended] = commandAccrualFixture(accountAttributes: ['period_end' => '2026-03-31']);
    [, , , , , $closed] = commandAccrualFixture(accountAttributes: ['status' => LeaveBalanceAccountStatus::Closed], company: $company);
    [, , , , , $future] = commandAccrualFixture(accountAttributes: ['period_start' => '2026-06-01', 'period_end' => '2027-05-31'], company: $company);
    [, , , , , $none] = commandAccrualFixture([
        'accrual_method' => LeaveAccrualMethod::None,
        'accrual_frequency' => LeaveAccrualFrequency::None,
    ], company: $company);

    runAccrualCommand($company, ['--as-of' => '2026-05-31'])
        ->expectsOutputToContain('Scanned: 2')
        ->expectsOutputToContain('Processed: 1')
        ->expectsOutputToContain('Skipped: 1')
        ->assertExitCode(0);

    expect($ended->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::Accrual->value)->count())->toBe(1)
        ->and($closed->ledgerEntries()->count())->toBe(0)
        ->and($future->ledgerEntries()->count())->toBe(0)
        ->and($none->ledgerEntries()->count())->toBe(0);
});

test('explicit account filter processes only that company account and rejects foreign accounts', function () {
    [$company, , , , , $selected] = commandAccrualFixture();
    [, , , , , $other] = commandAccrualFixture(company: $company);
    [, , , , , $foreign] = commandAccrualFixture();

    runAccrualCommand($company, ['--as-of' => '2026-02-28', '--account' => $selected->id])
        ->expectsOutputToContain('Scanned: 1')
        ->expectsOutputToContain('Processed: 1')
        ->assertExitCode(0);

    expect($selected->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::Accrual->value)->first()->amount)->toBe('2.00')
        ->and($other->ledgerEntries()->count())->toBe(0)
        ->and($foreign->ledgerEntries()->count())->toBe(0);

    runAccrualCommand($company, ['--account' => $foreign->id])->assertExitCode(2);
    runAccrualCommand($company, ['--account' => '999999'])->assertExitCode(2);
});

test('explicit closed account fails while future and none accounts are skipped', function () {
    [$company, , , , , $closed] = commandAccrualFixture(
        accountAttributes: ['status' => LeaveBalanceAccountStatus::Closed],
    );
    [, , , , , $future] = commandAccrualFixture(
        accountAttributes: ['period_start' => '2027-01-01', 'period_end' => '2027-12-31'],
        company: $company,
    );
    [, , , , , $none] = commandAccrualFixture([
        'accrual_method' => LeaveAccrualMethod::None,
        'accrual_frequency' => LeaveAccrualFrequency::None,
    ], company: $company);

    runAccrualCommand($company, ['--as-of' => '2026-05-31', '--account' => $closed->id])
        ->expectsOutputToContain('BALANCE_ACCOUNT_CLOSED')
        ->assertExitCode(1);
    runAccrualCommand($company, ['--as-of' => '2026-05-31', '--account' => $future->id])
        ->expectsOutputToContain('FUTURE_ACCOUNT_PERIOD')
        ->expectsOutputToContain('Skipped: 1')
        ->assertExitCode(0);
    runAccrualCommand($company, ['--as-of' => '2026-05-31', '--account' => $none->id])
        ->expectsOutputToContain('NO_AUTOMATIC_ACCRUAL')
        ->assertExitCode(0);
});

test('monthly batches catch up incrementally and identical reruns post nothing', function () {
    [$company, , , , , $account] = commandAccrualFixture();

    runAccrualCommand($company, ['--as-of' => '2026-05-31'])
        ->expectsOutputToContain('Posted: 1')
        ->expectsOutputToContain('Accrued now: 5.00')
        ->assertExitCode(0);
    runAccrualCommand($company, ['--as-of' => '2026-05-31'])
        ->expectsOutputToContain('Posted: 0')
        ->expectsOutputToContain('Up to date: 1')
        ->expectsOutputToContain('Accrued now: 0.00')
        ->assertExitCode(0);
    runAccrualCommand($company, ['--as-of' => '2026-06-30'])
        ->expectsOutputToContain('Accrued now: 1.00')
        ->assertExitCode(0);

    $entries = $account->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::Accrual->value)->orderBy('id')->get();
    expect($entries)->toHaveCount(2)
        ->and($entries[0]->amount)->toBe('5.00')
        ->and($entries[0]->effective_date->toDateString())->toBe('2026-05-31')
        ->and($entries[0]->created_by)->toBeNull()
        ->and($entries[1]->amount)->toBe('1.00')
        ->and(User::query()->count())->toBe(0);
});

test('unsupported frequencies and over-accrual fail independently while later accounts continue', function () {
    [$company, , , , , $quarterly] = commandAccrualFixture(['accrual_frequency' => LeaveAccrualFrequency::Quarterly]);
    [, , , , , $yearly] = commandAccrualFixture(['accrual_frequency' => LeaveAccrualFrequency::Yearly], company: $company);
    [, , , , , $overAccrued] = commandAccrualFixture([
        'accrual_method' => LeaveAccrualMethod::Upfront,
        'accrual_frequency' => LeaveAccrualFrequency::None,
        'entitlement_days' => '2.00',
    ], company: $company);
    commandAccrualEntry($overAccrued, LeaveLedgerTransactionType::Accrual, '3.00');
    [, , , , , $valid] = commandAccrualFixture([
        'accrual_method' => LeaveAccrualMethod::Upfront,
        'accrual_frequency' => LeaveAccrualFrequency::None,
        'entitlement_days' => '1.00',
    ], company: $company);

    runAccrualCommand($company, ['--as-of' => '2026-05-31'])
        ->expectsOutputToContain('UNSUPPORTED_ACCRUAL_FREQUENCY')
        ->expectsOutputToContain('POSTED_ACCRUAL_EXCEEDS_CALCULATED_TARGET')
        ->expectsOutputToContain('Failed: 3')
        ->expectsOutputToContain('Accrued now: 1.00')
        ->assertExitCode(1);

    expect($quarterly->ledgerEntries()->count())->toBe(0)
        ->and($yearly->ledgerEntries()->count())->toBe(0)
        ->and($valid->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::Accrual->value)->count())->toBe(1);
});

test('batch accrual leaves account policy assignment requests and unrelated ledger records intact', function () {
    [$company, $employee, $leaveType, $policy, $assignment, $account] = commandAccrualFixture();
    $opening = commandAccrualEntry($account, LeaveLedgerTransactionType::OpeningBalance, '2.00');
    $request = LeaveRequest::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'leave_policy_id' => $policy->id,
        'employee_leave_policy_assignment_id' => $assignment->id,
        'leave_balance_account_id' => $account->id,
    ]);
    $accountBefore = $account->fresh()->getAttributes();
    $policyBefore = $policy->fresh()->getAttributes();
    $assignmentBefore = $assignment->fresh()->getAttributes();
    $requestBefore = $request->fresh()->getAttributes();

    runAccrualCommand($company, ['--as-of' => '2026-03-31'])->assertExitCode(0);

    expect($account->fresh()->getAttributes())->toEqual($accountBefore)
        ->and($policy->fresh()->getAttributes())->toEqual($policyBefore)
        ->and($assignment->fresh()->getAttributes())->toEqual($assignmentBefore)
        ->and($request->fresh()->getAttributes())->toEqual($requestBefore)
        ->and($account->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::OpeningBalance->value)->first()->id)->toBe($opening->id)
        ->and($account->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::LeaveUsed->value)->count())->toBe(0);
});

test('batch selection continues across chunk boundaries', function () {
    $company = Company::factory()->create(['timezone' => 'Asia/Kolkata']);
    $leaveType = LeaveType::factory()->create(['company_id' => $company->id]);
    $policy = LeavePolicy::factory()->upfront()->create([
        'company_id' => $company->id,
        'leave_type_id' => $leaveType->id,
        'entitlement_days' => '1.00',
    ]);

    for ($index = 0; $index < 101; $index++) {
        $employee = Employee::factory()->create(['company_id' => $company->id]);
        $assignment = EmployeeLeavePolicyAssignment::factory()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'leave_policy_id' => $policy->id,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);
        $company->leaveBalanceAccounts()->create([
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'leave_policy_id' => $policy->id,
            'employee_leave_policy_assignment_id' => $assignment->id,
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
            'status' => LeaveBalanceAccountStatus::Open,
        ]);
    }

    runAccrualCommand($company, ['--as-of' => '2026-01-01'])
        ->expectsOutputToContain('Scanned: 101')
        ->expectsOutputToContain('Posted: 101')
        ->expectsOutputToContain('Accrued now: 101.00')
        ->assertExitCode(0);
});
