<?php

use App\Enums\LeaveAccrualFrequency;
use App\Enums\LeaveAccrualMethod;
use App\Enums\LeaveBalanceAccountStatus;
use App\Enums\LeaveLedgerTransactionType;
use App\Enums\LeavePolicyStatus;
use App\Enums\LeaveTypeStatus;
use App\Exceptions\Leave\InvalidLeaveAccrualConfigurationException;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeLeaveBalanceAccount;
use App\Models\EmployeeLeavePolicyAssignment;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\Leave\CalculatedLeaveAccrual;
use App\Services\Leave\LeaveAccrualCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** @return array{Company, Employee, LeaveType, LeavePolicy, EmployeeLeavePolicyAssignment, EmployeeLeaveBalanceAccount} */
function leaveAccrualFixture(
    array $policyAttributes = [],
    string $periodStart = '2026-01-01',
    string $periodEnd = '2026-12-31',
    string $timezone = 'Asia/Kolkata',
): array {
    $company = Company::factory()->create(['timezone' => $timezone]);
    $employee = Employee::factory()->create(['company_id' => $company->id]);
    $type = LeaveType::factory()->create(['company_id' => $company->id]);
    $policy = LeavePolicy::factory()->create(array_merge([
        'company_id' => $company->id,
        'leave_type_id' => $type->id,
        'entitlement_days' => '12.00',
        'accrual_method' => LeaveAccrualMethod::Accrual,
        'accrual_frequency' => LeaveAccrualFrequency::Monthly,
    ], $policyAttributes));
    $assignment = EmployeeLeavePolicyAssignment::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'leave_policy_id' => $policy->id,
        'effective_from' => $periodStart,
        'effective_to' => $periodEnd,
    ]);
    $account = $company->leaveBalanceAccounts()->create([
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'leave_policy_id' => $policy->id,
        'employee_leave_policy_assignment_id' => $assignment->id,
        'period_start' => $periodStart,
        'period_end' => $periodEnd,
        'status' => LeaveBalanceAccountStatus::Open,
    ]);

    return [$company, $employee, $type, $policy, $assignment, $account];
}

function calculateLeaveAccrual(EmployeeLeaveBalanceAccount $account, string $date): CalculatedLeaveAccrual
{
    return app(LeaveAccrualCalculator::class)->calculate(
        $account,
        CarbonImmutable::parse($date, 'UTC'),
    );
}

test('upfront entitlement is unavailable before the period and fully available from its start', function () {
    [, , , , , $account] = leaveAccrualFixture([
        'accrual_method' => LeaveAccrualMethod::Upfront,
        'accrual_frequency' => LeaveAccrualFrequency::None,
        'entitlement_days' => '12.00',
    ]);

    $before = calculateLeaveAccrual($account, '2025-12-31');
    $start = calculateLeaveAccrual($account, '2026-01-01');
    $middle = calculateLeaveAccrual($account, '2026-06-01');
    $end = calculateLeaveAccrual($account, '2026-12-31');
    $after = calculateLeaveAccrual($account, '2027-01-01');

    expect($before->accruedAmount)->toBe('0.00')
        ->and($before->remainingUnaccruedAmount)->toBe('12.00')
        ->and($before->earnedAccrualPeriods)->toBe(0)
        ->and($before->nextAccrualDate?->toDateString())->toBe('2026-01-01')
        ->and($start->accruedAmount)->toBe('12.00')
        ->and($middle->accruedAmount)->toBe('12.00')
        ->and($end->accruedAmount)->toBe('12.00')
        ->and($after->accruedAmount)->toBe('12.00')
        ->and($after->effectiveAsOfDate->toDateString())->toBe('2026-12-31')
        ->and($after->isFullyAccrued)->toBeTrue()
        ->and($after->nextAccrualDate)->toBeNull();
});

test('monthly entitlement accrues only at completed calendar month ends', function () {
    [, , , , , $account] = leaveAccrualFixture();

    $checks = [
        ['2026-01-01', '0.00', 0, '2026-01-31'],
        ['2026-01-30', '0.00', 0, '2026-01-31'],
        ['2026-01-31', '1.00', 1, '2026-02-28'],
        ['2026-02-27', '1.00', 1, '2026-02-28'],
        ['2026-02-28', '2.00', 2, '2026-03-31'],
        ['2026-03-31', '3.00', 3, '2026-04-30'],
        ['2026-04-30', '4.00', 4, '2026-05-31'],
        ['2026-05-15', '4.00', 4, '2026-05-31'],
        ['2026-12-31', '12.00', 12, null],
        ['2027-02-01', '12.00', 12, null],
    ];

    foreach ($checks as [$date, $accrued, $periods, $nextDate]) {
        $result = calculateLeaveAccrual($account, $date);

        expect($result->accruedAmount)->toBe($accrued)
            ->and($result->earnedAccrualPeriods)->toBe($periods)
            ->and($result->nextAccrualDate?->toDateString())->toBe($nextDate);
    }
});

test('monthly schedules handle leap years and non-january account periods', function () {
    [, , , , , $leapAccount] = leaveAccrualFixture(periodStart: '2028-01-01', periodEnd: '2028-12-31');
    [, , , , , $nonJanuaryAccount] = leaveAccrualFixture(
        periodStart: '2026-04-01',
        periodEnd: '2027-03-31',
    );

    $february = calculateLeaveAccrual($leapAccount, '2028-02-28');
    $leapDay = calculateLeaveAccrual($leapAccount, '2028-02-29');
    $nonJanuary = calculateLeaveAccrual($nonJanuaryAccount, '2026-05-15');
    $final = calculateLeaveAccrual($nonJanuaryAccount, '2027-03-31');

    expect($february->accruedAmount)->toBe('1.00')
        ->and($february->nextAccrualDate?->toDateString())->toBe('2028-02-29')
        ->and($leapDay->accruedAmount)->toBe('2.00')
        ->and($nonJanuary->accruedAmount)->toBe('1.00')
        ->and($nonJanuary->nextAccrualDate?->toDateString())->toBe('2026-05-31')
        ->and($final->totalAccrualPeriods)->toBe(12)
        ->and($final->accruedAmount)->toBe('12.00')
        ->and($final->remainingUnaccruedAmount)->toBe('0.00');
});

test('partial account periods use their own month-end boundaries and finish with exact entitlement', function () {
    [, , , , , $account] = leaveAccrualFixture(
        ['entitlement_days' => '10.00'],
        '2026-05-15',
        '2026-10-10',
    );

    $middle = calculateLeaveAccrual($account, '2026-06-30');
    $final = calculateLeaveAccrual($account, '2026-10-10');
    $after = calculateLeaveAccrual($account, '2026-12-01');

    expect($middle->totalAccrualPeriods)->toBe(6)
        ->and($middle->earnedAccrualPeriods)->toBe(2)
        ->and($middle->accruedAmount)->toBe('3.34')
        ->and($middle->nextAccrualDate?->toDateString())->toBe('2026-07-31')
        ->and($final->accruedAmount)->toBe('10.00')
        ->and($final->remainingUnaccruedAmount)->toBe('0.00')
        ->and($final->isFullyAccrued)->toBeTrue()
        ->and($final->nextAccrualDate)->toBeNull()
        ->and($after->effectiveAsOfDate->toDateString())->toBe('2026-10-10')
        ->and($after->accruedAmount)->toBe('10.00');
});

test('monthly remainder hundredths are distributed to the earliest periods without drift', function () {
    [, , , , , $tenDayAccount] = leaveAccrualFixture(['entitlement_days' => '10.00']);
    [, , , , , $fifteenDayAccount] = leaveAccrualFixture(['entitlement_days' => '15.50']);

    $ten = calculateLeaveAccrual($tenDayAccount, '2026-12-31');
    $firstTenPeriod = calculateLeaveAccrual($tenDayAccount, '2026-04-30');
    $fifteenFirstPeriod = calculateLeaveAccrual($fifteenDayAccount, '2026-01-31');
    $fifteenSecondPeriod = calculateLeaveAccrual($fifteenDayAccount, '2026-02-28');
    $fifteenThirdPeriod = calculateLeaveAccrual($fifteenDayAccount, '2026-03-31');
    $fifteen = calculateLeaveAccrual($fifteenDayAccount, '2026-12-31');

    expect($firstTenPeriod->accruedAmount)->toBe('3.36')
        ->and($ten->accruedAmount)->toBe('10.00')
        ->and($ten->remainingUnaccruedAmount)->toBe('0.00')
        ->and($fifteenFirstPeriod->accruedAmount)->toBe('1.30')
        ->and($fifteenSecondPeriod->accruedAmount)->toBe('2.60')
        ->and($fifteenThirdPeriod->accruedAmount)->toBe('3.89')
        ->and($fifteen->accruedAmount)->toBe('15.50')
        ->and($fifteen->totalAccrualPeriods)->toBe(12);
});

test('no-accrual policies report zero scheduled accrual and preserve the full unaccrued entitlement', function () {
    [, , , , , $account] = leaveAccrualFixture([
        'accrual_method' => LeaveAccrualMethod::None,
        'accrual_frequency' => LeaveAccrualFrequency::None,
        'entitlement_days' => '8.50',
    ]);

    $result = calculateLeaveAccrual($account, '2026-12-31');

    expect($result->entitlement)->toBe('8.50')
        ->and($result->accruedAmount)->toBe('0.00')
        ->and($result->remainingUnaccruedAmount)->toBe('8.50')
        ->and($result->totalAccrualPeriods)->toBe(0)
        ->and($result->earnedAccrualPeriods)->toBe(0)
        ->and($result->nextAccrualDate)->toBeNull()
        ->and($result->isFullyAccrued)->toBeFalse();
});

test('company-local date determines the accrual cutoff and result dates use that timezone', function () {
    [, , , , , $account] = leaveAccrualFixture(timezone: 'Asia/Kolkata');
    $asOf = CarbonImmutable::parse('2026-01-30 20:00:00', 'UTC');

    $result = app(LeaveAccrualCalculator::class)->calculate($account, $asOf);

    expect($result->asOfDate->toDateString())->toBe('2026-01-31')
        ->and($result->asOfDate->timezoneName)->toBe('Asia/Kolkata')
        ->and($result->accruedAmount)->toBe('1.00')
        ->and($result->effectiveAsOfDate->timezoneName)->toBe('Asia/Kolkata');
});

test('historical inactive policy and leave type and closed accounts remain calculable', function () {
    [, , $type, $policy, , $account] = leaveAccrualFixture([
        'status' => LeavePolicyStatus::Inactive,
        'accrual_method' => LeaveAccrualMethod::Upfront,
        'accrual_frequency' => LeaveAccrualFrequency::None,
    ]);
    $type->update(['status' => LeaveTypeStatus::Inactive]);
    $account->update(['status' => LeaveBalanceAccountStatus::Closed]);

    $result = calculateLeaveAccrual($account, '2026-06-01');

    expect($result->policy->is($policy))->toBeTrue()
        ->and($result->balanceAccount->status)->toBe(LeaveBalanceAccountStatus::Closed)
        ->and($result->accruedAmount)->toBe('12.00');
});

test('invalid account policy references fail explicitly', function () {
    [, , , $policy, , $account] = leaveAccrualFixture();
    $foreignCompany = Company::factory()->create();
    $foreignType = LeaveType::factory()->create(['company_id' => $foreignCompany->id]);
    $foreignPolicy = LeavePolicy::factory()->create([
        'company_id' => $foreignCompany->id,
        'leave_type_id' => $foreignType->id,
    ]);
    $account->setRelation('leavePolicy', $foreignPolicy);

    expect(fn () => calculateLeaveAccrual($account, '2026-05-01'))
        ->toThrow(InvalidLeaveAccrualConfigurationException::class, 'POLICY_ASSIGNMENT_MISMATCH');
});

test('foreign employee and assignment references fail explicitly', function () {
    [, , , , , $employeeAccount] = leaveAccrualFixture();
    [, $foreignEmployee] = leaveAccrualFixture();
    $employeeAccount->setRelation('employee', $foreignEmployee);

    expect(fn () => calculateLeaveAccrual($employeeAccount, '2026-05-01'))
        ->toThrow(InvalidLeaveAccrualConfigurationException::class, 'POLICY_ASSIGNMENT_MISMATCH');

    [, , , , , $assignmentAccount] = leaveAccrualFixture();
    [, , , , $foreignAssignment] = leaveAccrualFixture();
    $assignmentAccount->setRelation('assignment', $foreignAssignment);

    expect(fn () => calculateLeaveAccrual($assignmentAccount, '2026-05-01'))
        ->toThrow(InvalidLeaveAccrualConfigurationException::class, 'POLICY_ASSIGNMENT_MISMATCH');
});

test('a soft-deleted policy is an invalid account configuration', function () {
    [, , , $policy, , $account] = leaveAccrualFixture();
    $policy->delete();
    $account->unsetRelation('leavePolicy');

    expect(fn () => calculateLeaveAccrual($account, '2026-05-01'))
        ->toThrow(InvalidLeaveAccrualConfigurationException::class, 'POLICY_DELETED');
});

test('invalid account periods and unsupported accrual frequencies fail explicitly', function () {
    [, , , $policy, , $account] = leaveAccrualFixture();
    $account->setAttribute('period_end', '2025-12-31');

    expect(fn () => calculateLeaveAccrual($account, '2026-05-01'))
        ->toThrow(InvalidLeaveAccrualConfigurationException::class, 'INVALID_ACCOUNT_PERIOD');

    [, , , $quarterlyPolicy, , $quarterlyAccount] = leaveAccrualFixture([
        'accrual_frequency' => LeaveAccrualFrequency::Quarterly,
    ]);

    expect(fn () => calculateLeaveAccrual($quarterlyAccount, '2026-05-01'))
        ->toThrow(InvalidLeaveAccrualConfigurationException::class, 'UNSUPPORTED_ACCRUAL_FREQUENCY');
});

test('negative entitlement is rejected when corrupted data can be represented', function () {
    [, , , $policy, , $account] = leaveAccrualFixture();

    DB::table('leave_policies')->where('id', $policy->id)->update(['entitlement_days' => '-1.00']);
    $policy->refresh();
    $account->unsetRelation('leavePolicy');

    expect(fn () => calculateLeaveAccrual($account, '2026-05-01'))
        ->toThrow(InvalidLeaveAccrualConfigurationException::class, 'INVALID_ENTITLEMENT');
})->skip(fn (): bool => DB::connection()->getDriverName() === 'pgsql', 'PostgreSQL correctly blocks negative entitlement at the database constraint.');

test('ledger entries, pending reservations, and approved usage do not affect accrual or mutate account data', function () {
    [, $employee, $type, $policy, $assignment, $account] = leaveAccrualFixture();
    $ledgerCount = $account->ledgerEntries()->count();
    $accountSnapshot = [$account->status->value, $account->period_start->toDateString(), $account->period_end->toDateString()];
    $policySnapshot = [$policy->entitlement_days, $policy->accrual_method->value, $policy->accrual_frequency->value, $policy->status->value];
    $assignmentSnapshot = [$assignment->employee_id, $assignment->leave_type_id, $assignment->leave_policy_id, $assignment->effective_from->toDateString(), $assignment->effective_to?->toDateString()];

    $beforeLedger = calculateLeaveAccrual($account, '2026-05-31');
    $account->ledgerEntries()->create([
        'company_id' => $account->company_id,
        'employee_id' => $account->employee_id,
        'leave_type_id' => $account->leave_type_id,
        'leave_policy_id' => $account->leave_policy_id,
        'employee_leave_policy_assignment_id' => $account->employee_leave_policy_assignment_id,
        'transaction_type' => LeaveLedgerTransactionType::OpeningBalance,
        'amount' => '5.00',
        'effective_date' => '2026-01-01',
        'description' => 'Accrual independence test',
    ]);
    $requestReferences = [
        'company_id' => $account->company_id,
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'leave_policy_id' => $policy->id,
        'employee_leave_policy_assignment_id' => $assignment->id,
        'leave_balance_account_id' => $account->id,
        'start_date' => '2026-05-01',
        'end_date' => '2026-05-01',
    ];
    $pendingRequest = LeaveRequest::factory()->pending()->create($requestReferences);
    $approvedRequest = LeaveRequest::factory()->approved()->create($requestReferences);
    $requestSnapshots = [$pendingRequest->status->value, $pendingRequest->requested_days, $approvedRequest->status->value, $approvedRequest->requested_days];
    $ledgerCountBeforeCalculation = $account->fresh()->ledgerEntries()->count();
    $afterLedger = calculateLeaveAccrual($account->fresh(), '2026-05-31');
    $freshRequests = [$pendingRequest->fresh(), $approvedRequest->fresh()];

    expect($beforeLedger->accruedAmount)->toBe('5.00')
        ->and($afterLedger->accruedAmount)->toBe('5.00')
        ->and($account->fresh()->ledgerEntries()->count())->toBe($ledgerCountBeforeCalculation)
        ->and($ledgerCountBeforeCalculation)->toBe($ledgerCount + 2)
        ->and([$account->fresh()->status->value, $account->period_start->toDateString(), $account->period_end->toDateString()])->toBe($accountSnapshot)
        ->and([$policy->fresh()->entitlement_days, $policy->fresh()->accrual_method->value, $policy->fresh()->accrual_frequency->value, $policy->fresh()->status->value])->toBe($policySnapshot)
        ->and([$assignment->fresh()->employee_id, $assignment->fresh()->leave_type_id, $assignment->fresh()->leave_policy_id, $assignment->fresh()->effective_from->toDateString(), $assignment->fresh()->effective_to?->toDateString()])->toBe($assignmentSnapshot)
        ->and([$freshRequests[0]->status->value, $freshRequests[0]->requested_days, $freshRequests[1]->status->value, $freshRequests[1]->requested_days])->toBe($requestSnapshots);
});

test('zero entitlement is fully accrued without a balance mutation', function () {
    [, , , , , $account] = leaveAccrualFixture(['entitlement_days' => '0.00']);

    $before = calculateLeaveAccrual($account, '2025-12-31');
    $atStart = calculateLeaveAccrual($account, '2026-01-01');

    expect($before->accruedAmount)->toBe('0.00')
        ->and($before->remainingUnaccruedAmount)->toBe('0.00')
        ->and($before->isFullyAccrued)->toBeTrue()
        ->and($atStart->isFullyAccrued)->toBeTrue();
});

test('calculated accrual results are immutable', function () {
    [, , , , , $account] = leaveAccrualFixture();
    $result = calculateLeaveAccrual($account, '2026-05-31');

    expect(fn () => $result->accruedAmount = '99.00')->toThrow(Error::class);
});
