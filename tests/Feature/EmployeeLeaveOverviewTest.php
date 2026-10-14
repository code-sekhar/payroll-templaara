<?php

use App\Actions\SeedCompanyRoles;
use App\Enums\LeaveLedgerTransactionType;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\DailyAttendanceRecord;
use App\Models\Employee;
use App\Models\EmployeeLeaveBalanceAccount;
use App\Models\EmployeeLeavePolicyAssignment;
use App\Models\LeaveLedgerEntry;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Leave\LeaveBalanceCalculator;
use App\Services\Leave\LeaveRequestReservationCalculator;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Spatie\Permission\PermissionRegistrar;

/** @return array{Company, User, Employee} */
function employeeLeaveOverviewIdentity(?User $user = null, ?Company $company = null, array $employeeAttributes = []): array
{
    $company ??= Company::factory()->create(['timezone' => 'Asia/Kolkata']);
    $user ??= User::factory()->create();
    CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $user->id, 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($company);
    $registrar = app(PermissionRegistrar::class);
    $previousTeamId = $registrar->getPermissionsTeamId();
    $registrar->setPermissionsTeamId($company->id);
    if (! $user->hasRole('employee')) {
        $user->assignRole('employee');
    }
    $registrar->setPermissionsTeamId($previousTeamId);

    $employee = Employee::factory()->create(array_merge([
        'company_id' => $company->id,
        'user_id' => $user->id,
        'first_name' => 'Rahul',
        'last_name' => 'Das',
    ], $employeeAttributes));

    return [$company, $user, $employee];
}

/** @return array{LeaveType, LeavePolicy, EmployeeLeavePolicyAssignment, EmployeeLeaveBalanceAccount} */
function employeeLeaveOverviewAccount(Employee $employee, Company $company, array $attributes = []): array
{
    [$type, $policy, $assignment] = employeeLeaveOverviewAssignment($employee, $company, $attributes);
    $account = EmployeeLeaveBalanceAccount::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'leave_policy_id' => $policy->id,
        'employee_leave_policy_assignment_id' => $assignment->id,
        'period_start' => $attributes['period_start'] ?? '2026-01-01',
        'period_end' => $attributes['period_end'] ?? '2026-12-31',
        'status' => $attributes['account_status'] ?? 'open',
    ]);

    return [$type, $policy, $assignment, $account];
}

/** @return array{LeaveType, LeavePolicy, EmployeeLeavePolicyAssignment} */
function employeeLeaveOverviewAssignment(Employee $employee, Company $company, array $attributes = []): array
{
    $type = LeaveType::factory()->create([
        'company_id' => $company->id,
        'name' => $attributes['name'] ?? 'Annual Leave',
        'status' => $attributes['type_status'] ?? 'active',
    ]);
    $policy = LeavePolicy::factory()->create([
        'company_id' => $company->id,
        'leave_type_id' => $type->id,
        'status' => $attributes['policy_status'] ?? 'active',
    ]);
    $assignment = EmployeeLeavePolicyAssignment::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'leave_policy_id' => $policy->id,
        'effective_from' => $attributes['effective_from'] ?? '2026-01-01',
        'effective_to' => $attributes['effective_to'] ?? null,
        'status' => $attributes['assignment_status'] ?? 'active',
    ]);

    return [$type, $policy, $assignment];
}

function employeeLeaveOverviewLedgerEntry(EmployeeLeaveBalanceAccount $account, LeaveLedgerTransactionType $type, string $amount, string $date): LeaveLedgerEntry
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
        'description' => 'Overview test entry',
    ]);
}

function employeeLeaveOverviewRequest(Employee $employee, Company $company, LeaveType $type, LeavePolicy $policy, EmployeeLeavePolicyAssignment $assignment, EmployeeLeaveBalanceAccount $account, array $attributes = []): LeaveRequest
{
    return LeaveRequest::factory()->create(array_merge([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'leave_policy_id' => $policy->id,
        'employee_leave_policy_assignment_id' => $assignment->id,
        'leave_balance_account_id' => $account->id,
        'requested_days' => '1.00',
        'requested_at' => '2026-10-01 10:00:00',
        'start_date' => '2026-10-20',
        'end_date' => '2026-10-20',
        'status' => 'pending',
    ], $attributes));
}

test('employee sees current assigned leave balances from the ledger with saved reservation semantics', function () {
    $this->travelTo(Carbon::parse('2026-10-06 18:30:00', 'UTC'));
    [$company, $user, $employee] = employeeLeaveOverviewIdentity();
    [$type, $policy, $assignment, $account] = employeeLeaveOverviewAccount($employee, $company);
    employeeLeaveOverviewLedgerEntry($account, LeaveLedgerTransactionType::OpeningBalance, '12.50', '2026-01-01');
    employeeLeaveOverviewLedgerEntry($account, LeaveLedgerTransactionType::LeaveUsed, '-3.00', '2026-09-01');
    employeeLeaveOverviewLedgerEntry($account, LeaveLedgerTransactionType::LeaveReversed, '1.00', '2026-09-10');
    employeeLeaveOverviewLedgerEntry($account, LeaveLedgerTransactionType::Expiry, '-0.50', '2026-09-30');
    employeeLeaveOverviewRequest($employee, $company, $type, $policy, $assignment, $account, [
        'requested_days' => '1.25',
        'start_date' => '2026-10-20',
        'end_date' => '2026-10-22',
    ]);

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->get(route('employee.leave.index', ['employee_id' => 999999, 'company_id' => 999999]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('employee-portal/leave', false)
            ->where('employeeLeave.asOfDate', '2026-10-07')
            ->where('employeeLeave.hasApplicableLeaveTypes', true)
            ->where('employeeLeave.balances.0.leaveType', 'Annual Leave')
            ->where('employeeLeave.balances.0.periodStart', '2026-01-01')
            ->where('employeeLeave.balances.0.periodEnd', '2026-12-31')
            ->where('employeeLeave.balances.0.available', '8.75')
            ->where('employeeLeave.balances.0.used', '2.00')
            ->where('employeeLeave.balances.0.pending', '1.25')
            ->where('employeeLeave.recentRequests.0.requestedDays', '1.25')
            ->where('employeeLeave.recentRequests.0.startDate', '2026-10-20')
            ->where('employeeLeave.recentRequests.0.endDate', '2026-10-22')
            ->where('employeeLeave.recentRequests.0.status', 'pending')
            ->where('companyName', $company->name)
            ->where('employeeName', $employee->full_name)
            ->where('auth.user', null)
            ->where('activeCompany', null)
            ->where('companies', [])
            ->missing('employeeLeave.balances.0.employee_id')
            ->missing('employeeLeave.balances.0.company_id')
            ->missing('employeeLeave.balances.0.accountId')
            ->missing('employeeLeave.recentRequests.0.id')
            ->missing('employeeLeave.recentRequests.0.reviewerId'));
});

test('employee overview only includes effective active assignments and does not represent missing accounts as zero', function () {
    $this->travelTo(Carbon::parse('2026-10-07 12:00:00', 'Asia/Kolkata'));
    [$company, $user, $employee] = employeeLeaveOverviewIdentity();
    employeeLeaveOverviewAssignment($employee, $company, ['name' => 'Future Leave', 'effective_from' => '2027-01-01']);
    employeeLeaveOverviewAssignment($employee, $company, ['name' => 'Expired Leave', 'effective_to' => '2026-10-06']);
    employeeLeaveOverviewAssignment($employee, $company, ['name' => 'Inactive Assignment', 'assignment_status' => 'inactive']);
    employeeLeaveOverviewAssignment($employee, $company, ['name' => 'Inactive Type', 'type_status' => 'inactive']);
    employeeLeaveOverviewAssignment($employee, $company, ['name' => 'Inactive Policy', 'policy_status' => 'inactive']);
    employeeLeaveOverviewAssignment($employee, $company, ['name' => 'Sick Leave']);

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])->get(route('employee.leave.index'))
        ->assertInertia(fn ($page) => $page
            ->where('employeeLeave.hasApplicableLeaveTypes', true)
            ->where('employeeLeave.balances', [[
                'leaveType' => 'Sick Leave',
                'periodStart' => null,
                'periodEnd' => null,
                'configured' => false,
                'available' => null,
                'used' => null,
                'pending' => null,
            ]]));
});

test('employee overview scopes balances and requests to the linked employee and active company', function () {
    [$companyA, $user, $employeeA] = employeeLeaveOverviewIdentity();
    [$typeA, $policyA, $assignmentA, $accountA] = employeeLeaveOverviewAccount($employeeA, $companyA);
    employeeLeaveOverviewLedgerEntry($accountA, LeaveLedgerTransactionType::OpeningBalance, '4.00', '2026-01-01');
    employeeLeaveOverviewRequest($employeeA, $companyA, $typeA, $policyA, $assignmentA, $accountA);

    $coworker = Employee::factory()->create(['company_id' => $companyA->id, 'first_name' => 'Coworker', 'last_name' => 'Secret']);
    [$coworkerType, $coworkerPolicy, $coworkerAssignment, $coworkerAccount] = employeeLeaveOverviewAccount($coworker, $companyA, ['name' => 'Coworker Secret Leave']);
    employeeLeaveOverviewLedgerEntry($coworkerAccount, LeaveLedgerTransactionType::OpeningBalance, '99.00', '2026-01-01');
    employeeLeaveOverviewRequest($coworker, $companyA, $coworkerType, $coworkerPolicy, $coworkerAssignment, $coworkerAccount);

    $companyB = Company::factory()->create(['timezone' => 'Asia/Kolkata']);
    $employeeB = Employee::factory()->create(['company_id' => $companyB->id, 'first_name' => 'Other', 'last_name' => 'Tenant']);
    [$typeB, $policyB, $assignmentB, $accountB] = employeeLeaveOverviewAccount($employeeB, $companyB, ['name' => 'Tenant Secret Leave']);
    employeeLeaveOverviewLedgerEntry($accountB, LeaveLedgerTransactionType::OpeningBalance, '88.00', '2026-01-01');
    employeeLeaveOverviewRequest($employeeB, $companyB, $typeB, $policyB, $assignmentB, $accountB);

    $this->actingAs($user)->withSession(['active_company_id' => $companyA->id])->get(route('employee.leave.index'))
        ->assertInertia(fn ($page) => $page
            ->where('employeeLeave.balances.0.leaveType', 'Annual Leave')
            ->where('employeeLeave.balances.0.available', '3.00')
            ->has('employeeLeave.balances', 1)
            ->has('employeeLeave.recentRequests', 1)
            ->missing('employeeLeave.balances.1')
            ->missing('employeeLeave.recentRequests.1')
            ->missing('employeeLeave.coworker')
            ->missing('employeeLeave.otherTenant'));
});

test('same user receives the selected company employee leave overview after company switching', function () {
    [$companyA, $user, $employeeA] = employeeLeaveOverviewIdentity();
    [$typeA, $policyA, $assignmentA, $accountA] = employeeLeaveOverviewAccount($employeeA, $companyA, ['name' => 'Company A Leave']);
    employeeLeaveOverviewLedgerEntry($accountA, LeaveLedgerTransactionType::OpeningBalance, '3.00', '2026-01-01');

    $companyB = Company::factory()->create(['timezone' => 'Asia/Kolkata']);
    CompanyUser::query()->create(['company_id' => $companyB->id, 'user_id' => $user->id, 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($companyB);
    $registrar = app(PermissionRegistrar::class);
    $previousTeamId = $registrar->getPermissionsTeamId();
    $registrar->setPermissionsTeamId($companyB->id);
    $user->assignRole('employee');
    $registrar->setPermissionsTeamId($previousTeamId);
    $employeeB = Employee::factory()->create(['company_id' => $companyB->id, 'user_id' => $user->id, 'first_name' => 'Mira', 'last_name' => 'Sen']);
    [$typeB, $policyB, $assignmentB, $accountB] = employeeLeaveOverviewAccount($employeeB, $companyB, ['name' => 'Company B Leave']);
    employeeLeaveOverviewLedgerEntry($accountB, LeaveLedgerTransactionType::OpeningBalance, '9.00', '2026-01-01');

    $this->actingAs($user)->withSession(['active_company_id' => $companyA->id])->get(route('employee.leave.index'))
        ->assertInertia(fn ($page) => $page->where('employeeName', $employeeA->full_name)->where('employeeLeave.balances.0.leaveType', 'Company A Leave'));
    $this->withSession(['active_company_id' => $companyB->id])->get(route('employee.leave.index'))
        ->assertInertia(fn ($page) => $page->where('employeeName', $employeeB->full_name)->where('employeeLeave.balances.0.leaveType', 'Company B Leave'));
});

test('employee overview bounds requests in stable newest-first order and performs no leave writes', function () {
    $this->travelTo(Carbon::parse('2026-10-07 12:00:00', 'Asia/Kolkata'));
    [$company, $user, $employee] = employeeLeaveOverviewIdentity();
    [$type, $policy, $assignment, $account] = employeeLeaveOverviewAccount($employee, $company);
    employeeLeaveOverviewLedgerEntry($account, LeaveLedgerTransactionType::OpeningBalance, '6.00', '2026-01-01');

    foreach (range(1, 7) as $day) {
        employeeLeaveOverviewRequest($employee, $company, $type, $policy, $assignment, $account, [
            'start_date' => sprintf('2026-11-%02d', $day),
            'end_date' => sprintf('2026-11-%02d', $day),
            'requested_at' => sprintf('2026-10-%02d 10:00:00', $day),
        ]);
    }

    $counts = [
        'accounts' => EmployeeLeaveBalanceAccount::query()->count(),
        'ledger' => LeaveLedgerEntry::query()->count(),
        'requests' => LeaveRequest::query()->count(),
        'attendance' => DailyAttendanceRecord::query()->count(),
    ];

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])->get(route('employee.leave.index'))
        ->assertInertia(fn ($page) => $page
            ->has('employeeLeave.recentRequests', 5)
            ->where('employeeLeave.recentRequests.0.startDate', '2026-11-07')
            ->where('employeeLeave.recentRequests.4.startDate', '2026-11-03'));

    expect(EmployeeLeaveBalanceAccount::query()->count())->toBe($counts['accounts'])
        ->and(LeaveLedgerEntry::query()->count())->toBe($counts['ledger'])
        ->and(LeaveRequest::query()->count())->toBe($counts['requests'])
        ->and(DailyAttendanceRecord::query()->count())->toBe($counts['attendance']);
});

test('employee portal leave route rejects users without an employee identity', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create();
    CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $user->id, 'status' => 'active']);

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->get(route('employee.leave.index'))
        ->assertForbidden();
});

test('batched leave balance calculation preserves every ledger transaction total and date cutoff', function () {
    [$company, , $employee] = employeeLeaveOverviewIdentity();
    [, , , $account] = employeeLeaveOverviewAccount($employee, $company);

    foreach ([
        [LeaveLedgerTransactionType::OpeningBalance, '10.00', '2026-01-01'],
        [LeaveLedgerTransactionType::Entitlement, '12.00', '2026-01-01'],
        [LeaveLedgerTransactionType::Accrual, '2.00', '2026-02-01'],
        [LeaveLedgerTransactionType::CarryForward, '3.00', '2026-01-01'],
        [LeaveLedgerTransactionType::Adjustment, '1.00', '2026-03-01'],
        [LeaveLedgerTransactionType::Adjustment, '-2.00', '2026-03-02'],
        [LeaveLedgerTransactionType::Expiry, '-1.00', '2026-04-01'],
        [LeaveLedgerTransactionType::LeaveUsed, '-3.00', '2026-05-01'],
        [LeaveLedgerTransactionType::LeaveReversed, '1.00', '2026-06-01'],
        [LeaveLedgerTransactionType::Accrual, '100.00', '2026-12-01'],
    ] as [$type, $amount, $date]) {
        employeeLeaveOverviewLedgerEntry($account, $type, $amount, $date);
    }

    $calculated = app(LeaveBalanceCalculator::class)->calculateMany(new EloquentCollection([$account]), '2026-10-07')[$account->id];

    expect($calculated->openingBalance)->toBe('10.00')
        ->and($calculated->entitlement)->toBe('12.00')
        ->and($calculated->accrual)->toBe('2.00')
        ->and($calculated->carryForward)->toBe('3.00')
        ->and($calculated->positiveAdjustments)->toBe('1.00')
        ->and($calculated->negativeAdjustments)->toBe('-2.00')
        ->and($calculated->expiry)->toBe('-1.00')
        ->and($calculated->leaveUsed)->toBe('-3.00')
        ->and($calculated->leaveReversed)->toBe('1.00')
        ->and($calculated->balance)->toBe('23.00')
        ->and($calculated->transactionTotals[LeaveLedgerTransactionType::Accrual->value])->toBe('2.00');
});

test('batched balance and pending reservation calculations remain isolated across employees and companies', function () {
    [$companyA, , $employeeA] = employeeLeaveOverviewIdentity();
    $employeeB = Employee::factory()->create(['company_id' => $companyA->id]);
    $companyB = Company::factory()->create(['timezone' => 'Asia/Kolkata']);
    $employeeC = Employee::factory()->create(['company_id' => $companyB->id]);

    [$typeA, $policyA, $assignmentA, $accountA] = employeeLeaveOverviewAccount($employeeA, $companyA, ['name' => 'A Leave']);
    [$typeB, $policyB, $assignmentB, $accountB] = employeeLeaveOverviewAccount($employeeB, $companyA, ['name' => 'B Leave']);
    [$typeC, $policyC, $assignmentC, $accountC] = employeeLeaveOverviewAccount($employeeC, $companyB, ['name' => 'C Leave']);

    foreach ([[$accountA, '10.00'], [$accountB, '20.00'], [$accountC, '30.00']] as [$account, $amount]) {
        employeeLeaveOverviewLedgerEntry($account, LeaveLedgerTransactionType::OpeningBalance, $amount, '2026-01-01');
    }

    employeeLeaveOverviewRequest($employeeA, $companyA, $typeA, $policyA, $assignmentA, $accountA, ['requested_days' => '1.00']);
    employeeLeaveOverviewRequest($employeeB, $companyA, $typeB, $policyB, $assignmentB, $accountB, ['requested_days' => '2.00']);
    employeeLeaveOverviewRequest($employeeC, $companyB, $typeC, $policyC, $assignmentC, $accountC, ['requested_days' => '3.00']);
    employeeLeaveOverviewRequest($employeeA, $companyA, $typeA, $policyA, $assignmentA, $accountA, [
        'status' => 'approved',
        'requested_days' => '4.00',
    ]);
    employeeLeaveOverviewRequest($employeeA, $companyA, $typeA, $policyA, $assignmentA, $accountA, [
        'status' => 'rejected',
        'requested_days' => '5.00',
    ]);
    employeeLeaveOverviewRequest($employeeA, $companyA, $typeA, $policyA, $assignmentA, $accountA, [
        'status' => 'cancelled',
        'requested_days' => '6.00',
    ]);

    $accounts = new EloquentCollection([$accountA, $accountB, $accountC]);
    $balances = app(LeaveBalanceCalculator::class)->calculateMany($accounts, '2026-10-07');
    $reservations = app(LeaveRequestReservationCalculator::class)->pendingCentsForAccounts($accounts);

    expect($balances[$accountA->id]->balance)->toBe('10.00')
        ->and($balances[$accountB->id]->balance)->toBe('20.00')
        ->and($balances[$accountC->id]->balance)->toBe('30.00')
        ->and($reservations[$accountA->id])->toBe(100)
        ->and($reservations[$accountB->id])->toBe(200)
        ->and($reservations[$accountC->id])->toBe(300);
});
