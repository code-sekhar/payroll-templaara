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
use App\Models\LeaveType;
use App\Services\Leave\LeaveBalanceCalculator;
use App\Services\Leave\LeavePeriodClosingCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;

/** @return array{Company, Employee, LeaveType, LeavePolicy, EmployeeLeavePolicyAssignment, EmployeeLeaveBalanceAccount} */
function closePeriodsFixture(
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
        'accrual_method' => LeaveAccrualMethod::None,
        'accrual_frequency' => LeaveAccrualFrequency::None,
        'carry_forward_enabled' => false,
    ], $policyAttributes));
    $assignment = EmployeeLeavePolicyAssignment::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'leave_policy_id' => $policy->id,
        'effective_from' => '2026-01-01',
        'effective_to' => '2026-12-31',
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

function closePeriodsNextAccount(
    Company $company,
    Employee $employee,
    LeaveType $leaveType,
    array $policyAttributes = [],
    LeaveBalanceAccountStatus $status = LeaveBalanceAccountStatus::Open,
): EmployeeLeaveBalanceAccount {
    $policy = LeavePolicy::factory()->create(array_merge([
        'company_id' => $company->id,
        'leave_type_id' => $leaveType->id,
        'accrual_method' => LeaveAccrualMethod::None,
        'accrual_frequency' => LeaveAccrualFrequency::None,
    ], $policyAttributes));
    $assignment = EmployeeLeavePolicyAssignment::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'leave_policy_id' => $policy->id,
        'effective_from' => '2027-01-01',
        'effective_to' => '2027-12-31',
    ]);

    return $company->leaveBalanceAccounts()->create([
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'leave_policy_id' => $policy->id,
        'employee_leave_policy_assignment_id' => $assignment->id,
        'period_start' => '2027-01-01',
        'period_end' => '2027-12-31',
        'status' => $status,
    ]);
}

function closePeriodsOpening(EmployeeLeaveBalanceAccount $account, string $amount): LeaveLedgerEntry
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
        'description' => 'Closing command test opening balance',
    ]);
}

function runClosePeriodsCommand(?Company $company = null, array $options = [])
{
    $arguments = $options;
    if ($company) {
        $arguments['--company'] = $company->id;
    }

    return test()->artisan('leave:close-periods', $arguments);
}

test('company is required and unknown companies fail with validation exit code', function () {
    runClosePeriodsCommand()->assertExitCode(2);
    runClosePeriodsCommand(options: ['--company' => '999999'])->assertExitCode(2);
    runClosePeriodsCommand(options: ['--company' => 'not-an-id'])->assertExitCode(2);
});

test('as-of date is strict and rejects impossible calendar dates', function (string $date) {
    $company = Company::factory()->create();

    runClosePeriodsCommand($company, ['--as-of' => $date])->assertExitCode(2);
})->with([
    'non-ISO date' => '31-12-2026',
    'slash date' => '2026/12/31',
    'impossible date' => '2026-02-30',
    'arbitrary text' => 'abc',
]);

test('omitted as-of date uses company local calendar date', function () {
    [$company, , , , , $account] = closePeriodsFixture(accountAttributes: [
        'period_start' => '2026-01-01',
        'period_end' => '2026-06-01',
    ]);
    $this->travelTo(CarbonImmutable::parse('2026-05-31 18:30:00', 'UTC'));

    runClosePeriodsCommand($company)
        ->expectsOutputToContain('As of: 2026-06-01')
        ->expectsOutputToContain('Finalized: 1')
        ->assertExitCode(0);

    expect($account->fresh()->status)->toBe(LeaveBalanceAccountStatus::Closed);
    $this->travelBack();
});

test('explicit as-of date is used and candidates are company scoped open and ended accounts', function () {
    [$companyA, , , , , $ended] = closePeriodsFixture(accountAttributes: ['period_end' => '2026-03-31']);
    [$companyB, , , , , $foreign] = closePeriodsFixture(accountAttributes: ['period_end' => '2026-03-31']);
    [, , , , , $future] = closePeriodsFixture(accountAttributes: [
        'period_start' => '2027-01-01',
        'period_end' => '2027-12-31',
    ], company: $companyA);
    [, , , , , $closed] = closePeriodsFixture(accountAttributes: [
        'status' => LeaveBalanceAccountStatus::Closed,
    ], company: $companyA);

    runClosePeriodsCommand($companyA, ['--as-of' => '2026-12-31'])
        ->expectsOutputToContain('As of: 2026-12-31')
        ->expectsOutputToContain('Scanned: 1')
        ->expectsOutputToContain('Finalized: 1')
        ->assertExitCode(0);

    expect($ended->fresh()->status)->toBe(LeaveBalanceAccountStatus::Closed)
        ->and($future->fresh()->status)->toBe(LeaveBalanceAccountStatus::Open)
        ->and($closed->fresh()->status)->toBe(LeaveBalanceAccountStatus::Closed)
        ->and($foreign->fresh()->status)->toBe(LeaveBalanceAccountStatus::Open)
        ->and($companyB->leaveBalanceAccounts()->count())->toBe(1);
});

test('explicit account scope rejects unknown and cross-company accounts before processing', function () {
    [$companyA] = closePeriodsFixture();
    [, , , , , $foreign] = closePeriodsFixture();

    runClosePeriodsCommand($companyA, ['--account' => '999999'])->assertExitCode(2);
    runClosePeriodsCommand($companyA, ['--account' => $foreign->id])->assertExitCode(2);

    expect($foreign->fresh()->status)->toBe(LeaveBalanceAccountStatus::Open);
});

test('explicit future account is skipped successfully and explicit closed account retries idempotently', function () {
    [$company, , , , , $future] = closePeriodsFixture(accountAttributes: [
        'period_start' => '2027-01-01',
        'period_end' => '2027-12-31',
    ]);
    [, , , , , $closed] = closePeriodsFixture(accountAttributes: [
        'status' => LeaveBalanceAccountStatus::Closed,
    ], company: $company);

    runClosePeriodsCommand($company, ['--as-of' => '2026-12-31', '--account' => $future->id])
        ->expectsOutputToContain('reason=PERIOD_NOT_ENDED')
        ->expectsOutputToContain('Skipped: 1')
        ->assertExitCode(0);
    runClosePeriodsCommand($company, ['--as-of' => '2026-12-31', '--account' => $closed->id])
        ->expectsOutputToContain('ALREADY FINALIZED')
        ->expectsOutputToContain('Already finalized: 1')
        ->assertExitCode(0);
});

test('normal batch finalizes zero balance and expiry-only accounts without creating accounts', function () {
    [$company, , , , , $zero] = closePeriodsFixture();
    [, , , , , $expiry] = closePeriodsFixture([
        'carry_forward_enabled' => false,
    ], ['period_end' => '2026-11-30'], $company);
    closePeriodsOpening($expiry, '2.50');

    $accountCount = $company->leaveBalanceAccounts()->count();
    runClosePeriodsCommand($company, ['--as-of' => '2026-12-31'])
        ->expectsOutputToContain('Scanned: 2')
        ->expectsOutputToContain('Processed: 2')
        ->expectsOutputToContain('Finalized: 2')
        ->assertExitCode(0);

    expect($zero->fresh()->status)->toBe(LeaveBalanceAccountStatus::Closed)
        ->and($zero->ledgerEntries()->count())->toBe(0)
        ->and($expiry->fresh()->status)->toBe(LeaveBalanceAccountStatus::Closed)
        ->and($expiry->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::Expiry->value)->value('amount'))->toBe('-2.50')
        ->and($company->leaveBalanceAccounts()->count())->toBe($accountCount);
});

test('carry and expiry use finalization domain flow and explicit reruns do not duplicate ledger rows', function () {
    [$company, $employee, $leaveType, $policy, $assignment, $source] = closePeriodsFixture([
        'carry_forward_enabled' => true,
        'max_carry_forward_days' => '5.00',
    ]);
    $destination = closePeriodsNextAccount($company, $employee, $leaveType, [
        'max_balance_days' => '10.00',
    ]);
    closePeriodsOpening($source, '8.00');
    $ledgerBefore = LeaveLedgerEntry::query()->count();

    runClosePeriodsCommand($company, ['--as-of' => '2026-12-31', '--account' => $source->id])
        ->expectsOutputToContain('FINALIZED')
        ->assertExitCode(0);

    expect($source->fresh()->status)->toBe(LeaveBalanceAccountStatus::Closed)
        ->and(app(LeaveBalanceCalculator::class)->calculate($source, '2026-12-31')->balance)->toBe('5.00')
        ->and(app(LeaveBalanceCalculator::class)->calculate($destination, '2027-01-01')->balance)->toBe('5.00')
        ->and($destination->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::CarryForward->value)->value('amount'))->toBe('5.00')
        ->and($source->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::Expiry->value)->value('amount'))->toBe('-3.00')
        ->and($policy->fresh()->id)->toBe($source->leave_policy_id)
        ->and($assignment->fresh()->id)->toBe($source->employee_leave_policy_assignment_id)
        ->and(LeaveLedgerEntry::query()->count())->toBe($ledgerBefore + 2);

    $ledgerCount = LeaveLedgerEntry::query()->count();
    runClosePeriodsCommand($company, ['--as-of' => '2026-12-31', '--account' => $source->id])
        ->expectsOutputToContain('ALREADY FINALIZED')
        ->assertExitCode(0);
    expect(LeaveLedgerEntry::query()->count())->toBe($ledgerCount);
});

test('period blockers are skipped while processing continues to later eligible accounts', function () {
    [$company, , , $monthlyPolicy, , $missingAccrual] = closePeriodsFixture([
        'accrual_method' => LeaveAccrualMethod::Accrual,
        'accrual_frequency' => LeaveAccrualFrequency::Monthly,
    ]);
    [, , , , , $eligible] = closePeriodsFixture(company: $company);

    runClosePeriodsCommand($company, ['--as-of' => '2026-12-31'])
        ->expectsOutputToContain('reason=ACCRUAL_NOT_FULLY_RECONCILED')
        ->expectsOutputToContain('Finalized: 1')
        ->expectsOutputToContain('Skipped: 1')
        ->expectsOutputToContain('Failed: 0')
        ->assertExitCode(0);

    expect($missingAccrual->fresh()->status)->toBe(LeaveBalanceAccountStatus::Open)
        ->and($eligible->fresh()->status)->toBe(LeaveBalanceAccountStatus::Closed)
        ->and($monthlyPolicy->fresh()->accrual_method)->toBe(LeaveAccrualMethod::Accrual);
});

test('unsupported accrual configuration is skipped without stopping the batch', function () {
    [$company, , , , , $unsupported] = closePeriodsFixture([
        'accrual_method' => LeaveAccrualMethod::Accrual,
        'accrual_frequency' => LeaveAccrualFrequency::Quarterly,
    ]);
    [, , , , , $eligible] = closePeriodsFixture(company: $company);

    runClosePeriodsCommand($company, ['--as-of' => '2026-12-31'])
        ->expectsOutputToContain('reason=UNSUPPORTED_ACCRUAL_CONFIGURATION')
        ->expectsOutputToContain('Skipped: 1')
        ->expectsOutputToContain('Finalized: 1')
        ->expectsOutputToContain('Failed: 0')
        ->assertExitCode(0);

    expect($unsupported->fresh()->status)->toBe(LeaveBalanceAccountStatus::Open)
        ->and($eligible->fresh()->status)->toBe(LeaveBalanceAccountStatus::Closed);
});

test('missing next accounts and destination balance caps are skipped without creating accounts', function () {
    [$company, , , , , $missingDestination] = closePeriodsFixture([
        'carry_forward_enabled' => true,
        'max_carry_forward_days' => '5.00',
    ]);
    closePeriodsOpening($missingDestination, '8.00');

    [$company, $employee, $leaveType, , , $cappedSource] = closePeriodsFixture([
        'carry_forward_enabled' => true,
        'max_carry_forward_days' => '5.00',
    ], company: $company);
    $cappedDestination = closePeriodsNextAccount($company, $employee, $leaveType, [
        'max_balance_days' => '4.00',
    ]);
    closePeriodsOpening($cappedSource, '8.00');
    $accountCount = $company->leaveBalanceAccounts()->count();

    runClosePeriodsCommand($company, ['--as-of' => '2026-12-31'])
        ->expectsOutputToContain('reason=NEXT_PERIOD_ACCOUNT_REQUIRED')
        ->expectsOutputToContain('reason=BALANCE_CAP_EXCEEDED')
        ->expectsOutputToContain('Skipped: 2')
        ->expectsOutputToContain('Failed: 0')
        ->assertExitCode(0);

    expect($missingDestination->fresh()->status)->toBe(LeaveBalanceAccountStatus::Open)
        ->and($cappedSource->fresh()->status)->toBe(LeaveBalanceAccountStatus::Open)
        ->and($cappedDestination->ledgerEntries()->count())->toBe(0)
        ->and($company->leaveBalanceAccounts()->count())->toBe($accountCount);
});

test('accounting mismatch is failed and does not stop later account finalization', function () {
    [$company, , , $policy, , $mismatched] = closePeriodsFixture([
        'carry_forward_enabled' => true,
        'max_carry_forward_days' => '5.00',
    ]);
    [, , , , , $later] = closePeriodsFixture(company: $company);
    closePeriodsOpening($mismatched, '8.00');
    $destination = closePeriodsNextAccount($company, $mismatched->employee, $mismatched->leaveType);
    $destination->ledgerEntries()->create([
        'company_id' => $company->id,
        'employee_id' => $mismatched->employee_id,
        'leave_type_id' => $mismatched->leave_type_id,
        'leave_policy_id' => $destination->leave_policy_id,
        'employee_leave_policy_assignment_id' => $destination->employee_leave_policy_assignment_id,
        'transaction_type' => LeaveLedgerTransactionType::CarryForward,
        'amount' => '4.00',
        'effective_date' => '2027-01-01',
        'description' => 'Mismatched carry fixture',
        'reference_type' => LeavePeriodClosingCalculator::CLOSING_REFERENCE_TYPE,
        'reference_id' => $mismatched->id,
    ]);

    runClosePeriodsCommand($company, ['--as-of' => '2026-12-31'])
        ->expectsOutputToContain('reason=CARRY_FORWARD_ALREADY_MISMATCHED')
        ->expectsOutputToContain('Failed: 1')
        ->expectsOutputToContain('Finalized: 1')
        ->assertExitCode(1);

    expect($mismatched->fresh()->status)->toBe(LeaveBalanceAccountStatus::Open)
        ->and($later->fresh()->status)->toBe(LeaveBalanceAccountStatus::Closed)
        ->and($policy->fresh()->max_carry_forward_days)->toBe('5.00')
        ->and($destination->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::CarryForward->value)->value('amount'))->toBe('4.00');
});

test('inactive historical policy and leave type remain eligible for explicit finalization', function () {
    [$company, , $leaveType, $policy, , $account] = closePeriodsFixture();
    $policy->update(['status' => LeavePolicyStatus::Inactive]);
    $leaveType->update(['status' => LeaveTypeStatus::Inactive]);

    runClosePeriodsCommand($company, ['--as-of' => '2026-12-31', '--account' => $account->id])
        ->expectsOutputToContain('FINALIZED')
        ->assertExitCode(0);

    expect($account->fresh()->status)->toBe(LeaveBalanceAccountStatus::Closed)
        ->and($policy->fresh()->status)->toBe(LeavePolicyStatus::Inactive)
        ->and($leaveType->fresh()->status)->toBe(LeaveTypeStatus::Inactive);
});

test('batch iteration processes accounts in bounded id-ordered chunks', function () {
    [$company, , , , , $first] = closePeriodsFixture();
    $expectedIds = [$first->id];

    for ($index = 1; $index < 101; $index++) {
        [, , , , , $account] = closePeriodsFixture(company: $company);
        $expectedIds[] = $account->id;
    }

    $output = Artisan::call('leave:close-periods', [
        '--company' => $company->id,
        '--as-of' => '2026-12-31',
    ]);

    expect($output)->toBe(0)
        ->and(Artisan::output())->toContain('Scanned: 101')
        ->and(EmployeeLeaveBalanceAccount::query()->whereIn('id', $expectedIds)->where('status', LeaveBalanceAccountStatus::Closed->value)->count())->toBe(101);
});
