<?php

use App\Actions\LeaveBalances\ReconcileLeaveAccrual;
use App\Actions\SeedCompanyRoles;
use App\Enums\LeaveAccrualFrequency;
use App\Enums\LeaveAccrualMethod;
use App\Enums\LeaveBalanceAccountStatus;
use App\Enums\LeaveLedgerTransactionType;
use App\Enums\LeavePolicyStatus;
use App\Enums\LeaveTypeStatus;
use App\Exceptions\Leave\InvalidLeaveAccrualConfigurationException;
use App\Exceptions\Leave\LeaveAccrualReconciliationException;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\EmployeeLeaveBalanceAccount;
use App\Models\EmployeeLeavePolicyAssignment;
use App\Models\LeaveLedgerEntry;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Leave\LeaveAccrualCalculator;
use App\Services\Leave\LeaveAmount;
use Carbon\CarbonImmutable;
use Spatie\Permission\PermissionRegistrar;

/** @return array{Company, Employee, LeaveType, LeavePolicy, EmployeeLeavePolicyAssignment, EmployeeLeaveBalanceAccount} */
function leaveAccrualReconciliationFixture(array $policyAttributes = [], string $periodStart = '2026-01-01', string $periodEnd = '2026-12-31', LeaveBalanceAccountStatus $accountStatus = LeaveBalanceAccountStatus::Open): array
{
    $company = Company::factory()->create(['timezone' => 'Asia/Kolkata']);
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
        'effective_from' => $periodStart,
        'effective_to' => $periodEnd,
    ]);
    $account = $company->leaveBalanceAccounts()->create([
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'leave_policy_id' => $policy->id,
        'employee_leave_policy_assignment_id' => $assignment->id,
        'period_start' => $periodStart,
        'period_end' => $periodEnd,
        'status' => $accountStatus,
    ]);

    return [$company, $employee, $leaveType, $policy, $assignment, $account];
}

function postAccrualFixtureEntry(EmployeeLeaveBalanceAccount $account, LeaveLedgerTransactionType $type, string $amount): LeaveLedgerEntry
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
        'description' => 'Existing test ledger entry',
        'created_at' => now(),
    ]);
}

function reconcileFixture(EmployeeLeaveBalanceAccount $account, string $date, ?User $actor = null)
{
    return app(ReconcileLeaveAccrual::class)->execute($account, CarbonImmutable::parse($date), $actor);
}

test('upfront entitlement posts once at period start and is a no-op before the period', function () {
    [, , , , , $account] = leaveAccrualReconciliationFixture([
        'accrual_method' => LeaveAccrualMethod::Upfront,
        'accrual_frequency' => LeaveAccrualFrequency::None,
    ]);

    $before = reconcileFixture($account, '2025-12-31');
    $posted = reconcileFixture($account, '2026-01-01');
    $rerun = reconcileFixture($account, '2026-06-01');

    expect($before->wasPosted)->toBeFalse()
        ->and($before->postedAmount)->toBe('0.00')
        ->and($posted->targetAccruedAmount)->toBe('12.00')
        ->and($posted->previouslyPostedAccrualAmount)->toBe('0.00')
        ->and($posted->postedAmount)->toBe('12.00')
        ->and($posted->ledgerEntry?->effective_date->toDateString())->toBe('2026-01-01')
        ->and($rerun->wasPosted)->toBeFalse()
        ->and($rerun->previouslyPostedAccrualAmount)->toBe('12.00')
        ->and($account->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::Accrual->value)->count())->toBe(1);
});

test('monthly reconciliation catches up and posts only the incremental missing accrual', function () {
    [, , , , , $account] = leaveAccrualReconciliationFixture();

    $first = reconcileFixture($account, '2026-05-31');
    $sameDate = reconcileFixture($account, '2026-05-31');
    $later = reconcileFixture($account, '2026-07-31');
    $afterPeriod = reconcileFixture($account, '2027-02-01');

    expect($first->targetAccruedAmount)->toBe('5.00')
        ->and($first->postedAmount)->toBe('5.00')
        ->and($first->ledgerEntry?->effective_date->toDateString())->toBe('2026-05-31')
        ->and($sameDate->wasPosted)->toBeFalse()
        ->and($later->previouslyPostedAccrualAmount)->toBe('5.00')
        ->and($later->postedAmount)->toBe('2.00')
        ->and($afterPeriod->targetAccruedAmount)->toBe('12.00')
        ->and($afterPeriod->postedAmount)->toBe('5.00')
        ->and($afterPeriod->ledgerEntry?->effective_date->toDateString())->toBe('2026-12-31')
        ->and($account->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::Accrual->value)->count())->toBe(3);
});

test('accrual reconciliation ignores every non-accrual ledger transaction type', function () {
    [, , , , , $account] = leaveAccrualReconciliationFixture();
    postAccrualFixtureEntry($account, LeaveLedgerTransactionType::Accrual, '3.00');
    postAccrualFixtureEntry($account, LeaveLedgerTransactionType::OpeningBalance, '10.00');
    postAccrualFixtureEntry($account, LeaveLedgerTransactionType::Adjustment, '4.00');
    postAccrualFixtureEntry($account, LeaveLedgerTransactionType::LeaveUsed, '-2.00');

    $result = reconcileFixture($account, '2026-05-31');

    expect($result->targetAccruedAmount)->toBe('5.00')
        ->and($result->previouslyPostedAccrualAmount)->toBe('3.00')
        ->and($result->postedAmount)->toBe('2.00')
        ->and($account->ledgerEntries()->count())->toBe(5);
});

test('monthly remainder distributions reconcile exact integer hundredths', function () {
    [, , , , , $tenDayAccount] = leaveAccrualReconciliationFixture(['entitlement_days' => '10.00']);
    [, , , , , $fifteenDayAccount] = leaveAccrualReconciliationFixture(['entitlement_days' => '15.50']);

    foreach (['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31', '2026-06-30', '2026-07-31', '2026-08-31', '2026-09-30', '2026-10-31', '2026-11-30', '2026-12-31'] as $date) {
        reconcileFixture($tenDayAccount, $date);
        reconcileFixture($fifteenDayAccount, $date);
    }

    $tenAccrual = app(LeaveAccrualCalculator::class)->calculate($tenDayAccount, CarbonImmutable::parse('2026-12-31'))->accruedAmount;
    $fifteenAccrual = app(LeaveAccrualCalculator::class)->calculate($fifteenDayAccount, CarbonImmutable::parse('2026-12-31'))->accruedAmount;
    $tenPosted = $tenDayAccount->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::Accrual->value)->get()->sum(fn (LeaveLedgerEntry $entry): int => LeaveAmount::toCents((string) $entry->getRawOriginal('amount')));
    $fifteenPosted = $fifteenDayAccount->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::Accrual->value)->get()->sum(fn (LeaveLedgerEntry $entry): int => LeaveAmount::toCents((string) $entry->getRawOriginal('amount')));

    expect($tenAccrual)->toBe('10.00')
        ->and(LeaveAmount::fromCents($tenPosted))->toBe('10.00')
        ->and($fifteenAccrual)->toBe('15.50')
        ->and(LeaveAmount::fromCents($fifteenPosted))->toBe('15.50');
});

test('over-accrual fails explicitly without changing ledger or account', function () {
    [, , , , , $account] = leaveAccrualReconciliationFixture([
        'accrual_method' => LeaveAccrualMethod::Upfront,
        'accrual_frequency' => LeaveAccrualFrequency::None,
        'entitlement_days' => '5.00',
    ]);
    postAccrualFixtureEntry($account, LeaveLedgerTransactionType::Accrual, '6.00');
    $beforeUpdatedBy = $account->updated_by;

    try {
        reconcileFixture($account, '2026-01-01');
        test()->fail('Over-accrual should throw.');
    } catch (LeaveAccrualReconciliationException $exception) {
        expect($exception->reason)->toBe('POSTED_ACCRUAL_EXCEEDS_CALCULATED_TARGET');
    }

    expect($account->ledgerEntries()->count())->toBe(1)
        ->and($account->fresh()->updated_by)->toBe($beforeUpdatedBy);
});

test('closed accounts may be read but cannot receive a missing accrual', function () {
    [, , , , , $account] = leaveAccrualReconciliationFixture(
        ['accrual_method' => LeaveAccrualMethod::Upfront, 'accrual_frequency' => LeaveAccrualFrequency::None],
        accountStatus: LeaveBalanceAccountStatus::Closed,
    );

    expect(app(LeaveAccrualCalculator::class)->calculate($account, CarbonImmutable::parse('2026-01-01'))->accruedAmount)->toBe('12.00');

    try {
        reconcileFixture($account, '2026-01-01');
        test()->fail('Closed account should not receive accrual.');
    } catch (LeaveAccrualReconciliationException $exception) {
        expect($exception->reason)->toBe('BALANCE_ACCOUNT_CLOSED');
    }

    expect($account->ledgerEntries()->count())->toBe(0);
});

test('none accrual method is a no-op and an existing accrual is reported as over-accrued', function () {
    [, , , , , $account] = leaveAccrualReconciliationFixture([
        'accrual_method' => LeaveAccrualMethod::None,
        'accrual_frequency' => LeaveAccrualFrequency::None,
    ]);

    $result = reconcileFixture($account, '2026-06-30');
    expect($result->targetAccruedAmount)->toBe('0.00')->and($result->isUpToDate)->toBeTrue();

    postAccrualFixtureEntry($account, LeaveLedgerTransactionType::Accrual, '0.01');
    expect(fn () => reconcileFixture($account, '2026-06-30'))
        ->toThrow(LeaveAccrualReconciliationException::class);
});

test('reconciliation keeps historical inactive policy and leave type valid and does not mutate account audit fields', function () {
    [, , $leaveType, $policy, , $account] = leaveAccrualReconciliationFixture([
        'accrual_method' => LeaveAccrualMethod::Upfront,
        'accrual_frequency' => LeaveAccrualFrequency::None,
    ]);
    $policy->update(['status' => LeavePolicyStatus::Inactive]);
    $leaveType->update(['status' => LeaveTypeStatus::Inactive]);
    $account->updated_by = null;
    $account->save();

    $result = reconcileFixture($account, '2026-01-01');

    expect($result->wasPosted)->toBeTrue()
        ->and($result->ledgerEntry?->created_by)->toBeNull()
        ->and($account->fresh()->updated_by)->toBeNull();
});

test('reconciliation records an optional actor and a deterministic auditable description', function () {
    [, , , , , $account] = leaveAccrualReconciliationFixture([
        'accrual_method' => LeaveAccrualMethod::Upfront,
        'accrual_frequency' => LeaveAccrualFrequency::None,
    ]);
    $actor = User::factory()->create();

    $result = reconcileFixture($account, '2026-03-10', $actor);

    expect($result->ledgerEntry?->transaction_type)->toBe(LeaveLedgerTransactionType::Accrual)
        ->and($result->ledgerEntry?->amount)->toBe('12.00')
        ->and($result->ledgerEntry?->effective_date->toDateString())->toBe('2026-03-10')
        ->and($result->ledgerEntry?->description)->toBe('Leave accrual reconciliation through 2026-03-10')
        ->and($result->ledgerEntry?->created_by)->toBe($actor->id);
});

test('balance cap prevents an accrual posting without changing account or ledger', function () {
    [, , , , , $account] = leaveAccrualReconciliationFixture([
        'accrual_method' => LeaveAccrualMethod::Upfront,
        'accrual_frequency' => LeaveAccrualFrequency::None,
        'max_balance_days' => '4.00',
    ]);
    $updatedBy = $account->updated_by;

    try {
        reconcileFixture($account, '2026-01-01');
        test()->fail('Balance cap should prevent posting.');
    } catch (LeaveAccrualReconciliationException $exception) {
        expect($exception->reason)->toBe('BALANCE_CAP_EXCEEDED');
    }

    expect($account->ledgerEntries()->count())->toBe(0)
        ->and($account->fresh()->updated_by)->toBe($updatedBy);
});

test('unsupported quarterly and yearly frequencies remain explicit configuration failures', function (LeaveAccrualFrequency $frequency) {
    [, , , , , $account] = leaveAccrualReconciliationFixture([
        'accrual_frequency' => $frequency,
    ]);

    expect(fn () => reconcileFixture($account, '2026-04-30'))
        ->toThrow(InvalidLeaveAccrualConfigurationException::class);
})->with([
    'quarterly' => LeaveAccrualFrequency::Quarterly,
    'yearly' => LeaveAccrualFrequency::Yearly,
]);

test('pending reservation and approved usage do not change the accrual target', function () {
    [$company, $employee, $leaveType, $policy, $assignment, $account] = leaveAccrualReconciliationFixture();
    $pending = LeaveRequest::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'leave_policy_id' => $policy->id,
        'employee_leave_policy_assignment_id' => $assignment->id,
        'leave_balance_account_id' => $account->id,
    ]);
    $pendingResult = reconcileFixture($account, '2026-05-31');
    expect($pendingResult->targetAccruedAmount)->toBe('5.00');

    $reviewer = User::factory()->create();
    $approved = LeaveRequest::factory()->approved($reviewer)->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'leave_policy_id' => $policy->id,
        'employee_leave_policy_assignment_id' => $assignment->id,
        'leave_balance_account_id' => $account->id,
    ]);
    $beforeRequest = $approved->only(['status', 'reviewed_by', 'reviewed_at', 'review_note', 'requested_days']);
    $beforeDays = $approved->days()->get()->map(fn ($day): array => $day->only(['requested_amount', 'day_type', 'leave_date']))->all();
    $beforeUsageCount = $account->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::LeaveUsed->value)->count();

    $afterUsage = reconcileFixture($account, '2026-05-31');

    expect($afterUsage->targetAccruedAmount)->toBe('5.00')
        ->and($afterUsage->postedAmount)->toBe('0.00')
        ->and($approved->fresh()->only(['status', 'reviewed_by', 'reviewed_at', 'review_note', 'requested_days']))->toEqual($beforeRequest)
        ->and($approved->days()->get()->map(fn ($day): array => $day->only(['requested_amount', 'day_type', 'leave_date']))->all())->toEqual($beforeDays)
        ->and($account->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::LeaveUsed->value)->count())->toBe($beforeUsageCount);
});

test('manual leave ledger endpoint still rejects accrual transaction type', function () {
    $actor = User::factory()->create();
    [$company, , , , , $account] = leaveAccrualReconciliationFixture();
    CompanyUser::create(['company_id' => $company->id, 'user_id' => $actor->id, 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($company);
    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $actor->assignRole('hr_admin');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $payload = [
        'amount' => '2.00',
        'effective_date' => '2026-01-01',
        'description' => 'Manual accrual attempt',
        'transaction_type' => LeaveLedgerTransactionType::Accrual->value,
    ];

    $this->actingAs($actor)
        ->post(route('companies.leave-balances.adjustments', [$company, $account]), $payload)
        ->assertSessionHasErrors('transaction_type');
    $this->post(route('companies.leave-balances.opening', [$company, $account]), $payload)
        ->assertSessionHasErrors('transaction_type');

    expect($account->ledgerEntries()->count())->toBe(0);
});
