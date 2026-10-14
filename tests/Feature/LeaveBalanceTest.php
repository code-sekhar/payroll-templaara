<?php

use App\Actions\LeaveBalances\CreateLeaveBalanceAccount;
use App\Actions\SeedCompanyRoles;
use App\Enums\LeaveBalanceAccountStatus;
use App\Enums\LeaveLedgerTransactionType;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\EmployeeLeaveBalanceAccount;
use App\Models\EmployeeLeavePolicyAssignment;
use App\Models\LeaveLedgerEntry;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Leave\LeaveBalanceCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

/** @return array{User, Company} */
function leaveBalanceActor(string $role): array
{
    $user = User::factory()->create();
    $company = Company::factory()->create();
    CompanyUser::create(['company_id' => $company->id, 'user_id' => $user->id, 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($company);
    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    return [$user, $company];
}

function leaveBalanceAssignment(Company $company, Employee $employee, LeavePolicy $policy, array $attributes = []): EmployeeLeavePolicyAssignment
{
    return $company->employeeLeavePolicyAssignments()->create(array_merge([
        'employee_id' => $employee->id,
        'leave_type_id' => $policy->leave_type_id,
        'leave_policy_id' => $policy->id,
        'effective_from' => '2026-01-01',
        'effective_to' => '2026-12-31',
        'status' => 'active',
    ], $attributes));
}

function leaveBalanceAccount(Company $company, EmployeeLeavePolicyAssignment $assignment, array $attributes = []): EmployeeLeaveBalanceAccount
{
    return $company->leaveBalanceAccounts()->create(array_merge([
        'employee_id' => $assignment->employee_id,
        'leave_type_id' => $assignment->leave_type_id,
        'leave_policy_id' => $assignment->leave_policy_id,
        'employee_leave_policy_assignment_id' => $assignment->id,
        'period_start' => '2026-01-01',
        'period_end' => '2026-12-31',
        'status' => LeaveBalanceAccountStatus::Open,
    ], $attributes));
}

function leaveBalanceEntry(EmployeeLeaveBalanceAccount $account, string $type, string $amount, string $date, array $attributes = []): LeaveLedgerEntry
{
    return $account->ledgerEntries()->create(array_merge([
        'company_id' => $account->company_id,
        'employee_id' => $account->employee_id,
        'leave_type_id' => $account->leave_type_id,
        'leave_policy_id' => $account->leave_policy_id,
        'employee_leave_policy_assignment_id' => $account->employee_leave_policy_assignment_id,
        'transaction_type' => $type,
        'amount' => $amount,
        'effective_date' => $date,
        'description' => 'Test ledger posting',
        'created_at' => now(),
    ], $attributes));
}

function leaveBalancePolicy(Company $company, Employee $employee, array $policyAttributes = []): array
{
    $type = LeaveType::factory()->create(['company_id' => $company->id]);
    $policy = LeavePolicy::factory()->create(array_merge(['company_id' => $company->id, 'leave_type_id' => $type->id], $policyAttributes));
    $assignment = leaveBalanceAssignment($company, $employee, $policy);

    return [$type, $policy, $assignment];
}

function leaveBalanceUrl(Company $company): string
{
    return route('companies.leave-balances.index', $company);
}

function leaveBalancePayload(EmployeeLeavePolicyAssignment $assignment, array $overrides = []): array
{
    return array_merge([
        'employee_leave_policy_assignment_id' => $assignment->id,
        'period_start' => '2026-01-01',
        'period_end' => '2026-12-31',
    ], $overrides);
}

test('HR creates account ownership from assignment and cannot spoof tenant fields', function () {
    [$hr, $company] = leaveBalanceActor('hr_admin');
    $employee = Employee::factory()->create(['company_id' => $company->id]);
    [$type, $policy, $assignment] = leaveBalancePolicy($company, $employee);

    $this->actingAs($hr)->post(leaveBalanceUrl($company), leaveBalancePayload($assignment, [
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'leave_policy_id' => $policy->id,
    ]))->assertSessionHasErrors(['company_id', 'employee_id', 'leave_type_id', 'leave_policy_id']);

    $this->post(leaveBalanceUrl($company), leaveBalancePayload($assignment))->assertRedirect();
    $account = $company->leaveBalanceAccounts()->firstOrFail();
    expect($account->employee_id)->toBe($employee->id)
        ->and($account->leave_type_id)->toBe($type->id)
        ->and($account->leave_policy_id)->toBe($policy->id)
        ->and($account->created_by)->toBe($hr->id)
        ->and($account->status)->toBe(LeaveBalanceAccountStatus::Open);
});

test('account periods must fit inside the selected assignment and duplicate logical periods are rejected', function () {
    [$hr, $company] = leaveBalanceActor('hr_admin');
    $employee = Employee::factory()->create(['company_id' => $company->id]);
    [, , $assignment] = leaveBalancePolicy($company, $employee);
    $this->actingAs($hr);

    $this->post(leaveBalanceUrl($company), leaveBalancePayload($assignment, ['period_start' => '2025-12-01']))->assertSessionHasErrors('period_start');
    $this->post(leaveBalanceUrl($company), leaveBalancePayload($assignment, ['period_end' => '2027-01-01']))->assertSessionHasErrors('period_start');
    $this->post(leaveBalanceUrl($company), leaveBalancePayload($assignment, ['period_end' => '2025-12-31']))->assertSessionHasErrors('period_end');
    $this->post(leaveBalanceUrl($company), leaveBalancePayload($assignment))->assertRedirect();
    $this->post(leaveBalanceUrl($company), leaveBalancePayload($assignment))->assertSessionHasErrors('period_start');
});

test('account creation rejects foreign and archived assignments', function () {
    [$hr, $company] = leaveBalanceActor('hr_admin');
    $employee = Employee::factory()->create(['company_id' => $company->id]);
    [, , $ownAssignment] = leaveBalancePolicy($company, $employee);
    $otherCompany = Company::factory()->create();
    $foreignEmployee = Employee::factory()->create(['company_id' => $otherCompany->id]);
    [, , $foreignAssignment] = leaveBalancePolicy($otherCompany, $foreignEmployee);
    $this->actingAs($hr);

    $this->post(leaveBalanceUrl($company), leaveBalancePayload($foreignAssignment))->assertSessionHasErrors('employee_leave_policy_assignment_id');
    $ownAssignment->delete();
    $this->post(leaveBalanceUrl($company), leaveBalancePayload($ownAssignment))->assertSessionHasErrors('employee_leave_policy_assignment_id');
});

test('open-ended assignments support contained accounts and inactive policies preserve account history', function () {
    [$hr, $company] = leaveBalanceActor('hr_admin');
    $employee = Employee::factory()->create(['company_id' => $company->id]);
    [, $policy, $assignment] = leaveBalancePolicy($company, $employee, ['max_balance_days' => '20.00']);
    $assignment->update(['effective_to' => null]);
    $account = app(CreateLeaveBalanceAccount::class)->handle($company, $hr, [
        'employee_leave_policy_assignment_id' => $assignment->id,
        'period_start' => '2027-01-01',
        'period_end' => '2027-12-31',
    ]);
    leaveBalanceEntry($account, 'opening_balance', '5.00', '2027-01-01');
    $policy->update(['status' => 'inactive']);

    expect($account->fresh()->leavePolicy->status->value)->toBe('inactive')
        ->and(app(LeaveBalanceCalculator::class)->calculate($account)->balance)->toBe('5.00')
        ->and($account->ledgerEntries()->count())->toBe(1);
});

test('opening balance is exact period-start-only unique and appends auditable actor data', function () {
    [$hr, $company] = leaveBalanceActor('hr_admin');
    $employee = Employee::factory()->create(['company_id' => $company->id]);
    [, , $assignment] = leaveBalancePolicy($company, $employee);
    $account = leaveBalanceAccount($company, $assignment);
    $this->actingAs($hr);
    $url = route('companies.leave-balances.opening', [$company, $account]);

    $this->post($url, ['amount' => '10.50', 'effective_date' => '2026-01-02', 'description' => 'Opening balance'])->assertSessionHasErrors('effective_date');
    $this->post($url, ['amount' => '10.50', 'effective_date' => '2026-01-01', 'description' => 'Opening balance'])->assertRedirect();
    $entry = $account->ledgerEntries()->firstOrFail();
    expect($entry->amount)->toBe('10.50')
        ->and($entry->transaction_type)->toBe(LeaveLedgerTransactionType::OpeningBalance)
        ->and($entry->effective_date->toDateString())->toBe('2026-01-01')
        ->and($entry->created_by)->toBe($hr->id);

    $this->post($url, ['amount' => '1.00', 'effective_date' => '2026-01-01', 'description' => 'Second opening'])->assertSessionHasErrors('amount');
    $this->post($url, ['amount' => '0.00', 'effective_date' => '2026-01-01', 'description' => 'Zero opening'])->assertSessionHasErrors('amount');
});

test('opening and adjustment respect negative floors and policy maximum balances', function () {
    [$hr, $company] = leaveBalanceActor('hr_admin');
    $employee = Employee::factory()->create(['company_id' => $company->id]);
    [, $policy, $assignment] = leaveBalancePolicy($company, $employee, ['max_balance_days' => '12.00']);
    $account = leaveBalanceAccount($company, $assignment);
    $this->actingAs($hr);
    $openingUrl = route('companies.leave-balances.opening', [$company, $account]);
    $adjustmentUrl = route('companies.leave-balances.adjustments', [$company, $account]);

    $this->post($openingUrl, ['amount' => '-0.50', 'effective_date' => '2026-01-01', 'description' => 'Opening debt'])->assertSessionHasErrors('amount');
    $this->post($openingUrl, ['amount' => '12.50', 'effective_date' => '2026-01-01', 'description' => 'Opening credit'])->assertSessionHasErrors('amount');
    $this->post($openingUrl, ['amount' => '10.00', 'effective_date' => '2026-01-01', 'description' => 'Opening balance'])->assertRedirect();
    $this->post($adjustmentUrl, ['amount' => '-10.50', 'effective_date' => '2026-02-01', 'description' => 'Too much debit'])->assertSessionHasErrors('amount');
    $this->post($adjustmentUrl, ['amount' => '2.50', 'effective_date' => '2026-02-01', 'description' => 'Too much credit'])->assertSessionHasErrors('amount');

    $policy->update(['allow_negative_balance' => true, 'max_negative_balance_days' => '2.00']);
    $this->post($adjustmentUrl, ['amount' => '-12.00', 'effective_date' => '2026-02-01', 'description' => 'Allowed reduction'])->assertRedirect();
    $this->post($adjustmentUrl, ['amount' => '-0.01', 'effective_date' => '2026-02-01', 'description' => 'Beyond limit'])->assertSessionHasErrors('amount');
});

test('permitted negative opening balances remain within the policy floor', function () {
    [$hr, $company] = leaveBalanceActor('hr_admin');
    $employee = Employee::factory()->create(['company_id' => $company->id]);
    [, , $assignment] = leaveBalancePolicy($company, $employee, [
        'allow_negative_balance' => true,
        'max_negative_balance_days' => '2.00',
    ]);
    $account = leaveBalanceAccount($company, $assignment);
    $this->actingAs($hr);
    $url = route('companies.leave-balances.opening', [$company, $account]);

    $this->post($url, ['amount' => '-1.50', 'effective_date' => '2026-01-01', 'description' => 'Opening debit'])->assertRedirect();
    expect(app(LeaveBalanceCalculator::class)->calculate($account)->balance)->toBe('-1.50');
});

test('adjustments require signed nonzero decimal amounts a meaningful reason and an in-period date', function () {
    [$hr, $company] = leaveBalanceActor('hr_admin');
    $employee = Employee::factory()->create(['company_id' => $company->id]);
    [, , $assignment] = leaveBalancePolicy($company, $employee);
    $account = leaveBalanceAccount($company, $assignment);
    $this->actingAs($hr);
    $url = route('companies.leave-balances.adjustments', [$company, $account]);

    $this->post($url, ['amount' => '0.00', 'effective_date' => '2026-01-01', 'description' => 'Valid reason'])->assertSessionHasErrors('amount');
    $this->post($url, ['amount' => '1.001', 'effective_date' => '2026-01-01', 'description' => 'Too precise'])->assertSessionHasErrors('amount');
    $this->post($url, ['amount' => '0.50', 'effective_date' => '2026-01-01', 'description' => 'Fix'])->assertSessionHasErrors('description');
    $this->post($url, ['amount' => '0.50', 'effective_date' => '2025-12-31', 'description' => 'Valid reason'])->assertSessionHasErrors('effective_date');
    $this->post($url, ['amount' => '0.50', 'effective_date' => '2027-01-01', 'description' => 'Valid reason'])->assertSessionHasErrors('effective_date');
    $this->post($url, ['amount' => '0.50', 'effective_date' => '2026-03-01', 'description' => 'HR correction', 'company_id' => $company->id, 'created_by' => 999])->assertSessionHasErrors(['company_id', 'created_by']);
    $this->post($url, ['amount' => '0.50', 'effective_date' => '2026-03-01', 'description' => 'HR correction'])->assertRedirect();

    $entry = $account->ledgerEntries()->firstOrFail();
    expect($entry->amount)->toBe('0.50')->and($entry->created_by)->toBe($hr->id);
});

test('closed accounts remain readable and reject further entries without an ordinary reopen or delete route', function () {
    [$owner, $company] = leaveBalanceActor('company_owner');
    $employee = Employee::factory()->create(['company_id' => $company->id]);
    [, , $assignment] = leaveBalancePolicy($company, $employee);
    $account = leaveBalanceAccount($company, $assignment);
    leaveBalanceEntry($account, 'opening_balance', '4.00', '2026-01-01');
    $this->actingAs($owner);

    expect(fn () => $account->update(['period_start' => '2026-02-01']))->toThrow(LogicException::class)
        ->and(fn () => $account->delete())->toThrow(LogicException::class);

    $this->travelTo(CarbonImmutable::parse('2026-12-31 12:00:00', $company->timezone));
    $this->post(route('companies.leave-balances.close', [$company, $account]))->assertRedirect();
    expect($account->fresh()->status)->toBe(LeaveBalanceAccountStatus::Closed);
    $this->post(route('companies.leave-balances.adjustments', [$company, $account]), ['amount' => '1.00', 'effective_date' => '2026-03-01', 'description' => 'Post-closure'])->assertSessionHasErrors('account');
    $this->post(route('companies.leave-balances.opening', [$company, $account]), ['amount' => '1.00', 'effective_date' => '2026-01-01', 'description' => 'Second opening'])->assertSessionHasErrors('account');
    $this->get(route('companies.leave-balances.show', [$company, $account]))->assertOk();
    $this->delete(route('companies.leave-balances.show', [$company, $account]))->assertMethodNotAllowed();
    expect(LeaveLedgerEntry::where('leave_balance_account_id', $account->id)->count())->toBe(2);
});

test('ledger calculation is exact supports as-of totals and deterministic running balances', function () {
    [, $company] = leaveBalanceActor('company_owner');
    $employee = Employee::factory()->create(['company_id' => $company->id]);
    [, , $assignment] = leaveBalancePolicy($company, $employee);
    $account = leaveBalanceAccount($company, $assignment);
    leaveBalanceEntry($account, 'opening_balance', '10.50', '2026-01-01', ['created_at' => '2026-01-01 08:00:00']);
    leaveBalanceEntry($account, 'adjustment', '0.50', '2026-02-01', ['created_at' => '2026-02-01 09:00:00']);
    leaveBalanceEntry($account, 'adjustment', '-1.25', '2026-02-01', ['created_at' => '2026-02-01 10:00:00']);
    leaveBalanceEntry($account, 'adjustment', '3.00', '2026-03-01', ['created_at' => '2026-03-01 08:00:00']);
    $calculator = app(LeaveBalanceCalculator::class);

    expect($calculator->calculate($account)->balance)->toBe('12.75')
        ->and($calculator->calculate($account, '2026-02-01')->balance)->toBe('9.75')
        ->and($calculator->calculate($account, '2026-02-01')->openingBalance)->toBe('10.50')
        ->and($calculator->calculate($account, '2026-02-01')->positiveAdjustments)->toBe('0.50')
        ->and($calculator->calculate($account, '2026-02-01')->negativeAdjustments)->toBe('-1.25');

    $history = $calculator->runningBalanceHistory($account);
    expect(array_column($history, 'running_balance'))->toBe(['10.50', '11.00', '9.75', '12.75'])
        ->and($calculator->calculate($account, '2025-12-31')->balance)->toBe('0.00');
});

test('ledger entries reject model and direct database mutation', function () {
    [, $company] = leaveBalanceActor('company_owner');
    $employee = Employee::factory()->create(['company_id' => $company->id]);
    [, , $assignment] = leaveBalancePolicy($company, $employee);
    $account = leaveBalanceAccount($company, $assignment);
    $entry = leaveBalanceEntry($account, 'adjustment', '0.50', '2026-01-01');

    expect(fn () => $entry->update(['amount' => '1.00']))->toThrow(LogicException::class)
        ->and(fn () => $entry->delete())->toThrow(LogicException::class)
        ->and(fn () => DB::table('leave_ledger_entries')->where('id', $entry->id)->update(['amount' => '1.00']))->toThrow(QueryException::class)
        ->and(fn () => DB::table('leave_ledger_entries')->where('id', $entry->id)->delete())->toThrow(QueryException::class);
});

test('composite foreign keys prevent ledger ownership from drifting from account ownership', function () {
    [, $company] = leaveBalanceActor('company_owner');
    $employee = Employee::factory()->create(['company_id' => $company->id]);
    [$type, $policy, $assignment] = leaveBalancePolicy($company, $employee);
    $account = leaveBalanceAccount($company, $assignment);
    $otherEmployee = Employee::factory()->create(['company_id' => $company->id]);
    $otherType = LeaveType::factory()->create(['company_id' => $company->id]);
    $otherPolicy = LeavePolicy::factory()->create(['company_id' => $company->id, 'leave_type_id' => $otherType->id]);
    $otherAssignment = leaveBalanceAssignment($company, $otherEmployee, $otherPolicy);

    $base = [
        'company_id' => $account->company_id,
        'leave_balance_account_id' => $account->id,
        'employee_id' => $account->employee_id,
        'leave_type_id' => $account->leave_type_id,
        'leave_policy_id' => $account->leave_policy_id,
        'employee_leave_policy_assignment_id' => $account->employee_leave_policy_assignment_id,
        'transaction_type' => 'adjustment', 'amount' => '0.50', 'effective_date' => '2026-01-01', 'created_at' => now(),
    ];
    foreach ([
        ['company_id' => Company::factory()->create()->id],
        ['employee_id' => $otherEmployee->id],
        ['leave_type_id' => $otherType->id],
        ['leave_policy_id' => $otherPolicy->id],
        ['employee_leave_policy_assignment_id' => $otherAssignment->id],
    ] as $mismatch) {
        expect(fn () => DB::table('leave_ledger_entries')->insert(array_merge($base, $mismatch)))->toThrow(QueryException::class);
    }

    expect($type->company_id)->toBe($company->id)->and($policy->company_id)->toBe($company->id);
});

test('manager can view direct-report accounts but not unrelated accounts', function () {
    [$manager, $company] = leaveBalanceActor('manager');
    $managerEmployee = Employee::factory()->create(['company_id' => $company->id, 'user_id' => $manager->id]);
    $report = Employee::factory()->create(['company_id' => $company->id, 'manager_id' => $managerEmployee->id]);
    $unrelated = Employee::factory()->create(['company_id' => $company->id]);
    [, , $reportAssignment] = leaveBalancePolicy($company, $report);
    [, , $unrelatedAssignment] = leaveBalancePolicy($company, $unrelated);
    $visible = leaveBalanceAccount($company, $reportAssignment);
    $hidden = leaveBalanceAccount($company, $unrelatedAssignment, ['period_start' => '2027-01-01', 'period_end' => '2027-12-31']);

    $this->actingAs($manager)->get(leaveBalanceUrl($company))
        ->assertInertia(fn (Assert $page) => $page->where('accounts.total', 1)->where('accounts.data.0.id', $visible->id));
    $this->get(route('companies.leave-balances.show', [$company, $hidden]))->assertForbidden();
    $this->get(route('companies.leave-balances.show', [$company, $visible]))->assertOk();
    $this->post(leaveBalanceUrl($company), leaveBalancePayload($reportAssignment, ['period_start' => '2026-01-01', 'period_end' => '2026-12-31']))->assertForbidden();
});

test('company scoping and permissions prevent foreign and unauthorized access', function (string $role) {
    [$user, $company] = leaveBalanceActor($role);
    $otherCompany = Company::factory()->create();
    $employee = Employee::factory()->create(['company_id' => $otherCompany->id]);
    [, , $assignment] = leaveBalancePolicy($otherCompany, $employee);
    $account = leaveBalanceAccount($otherCompany, $assignment);

    $this->actingAs($user)->get(leaveBalanceUrl($otherCompany))->assertNotFound();
    $this->get(route('companies.leave-balances.show', [$company, $account]))->assertNotFound();
})->with(['employee', 'accountant']);

test('company owner HR admin and HR manager receive only their configured balance permissions', function () {
    foreach (['company_owner', 'hr_admin', 'hr_manager'] as $role) {
        [$actor, $company] = leaveBalanceActor($role);
        $employee = Employee::factory()->create(['company_id' => $company->id]);
        [, , $assignment] = leaveBalancePolicy($company, $employee);
        $this->actingAs($actor)->post(leaveBalanceUrl($company), leaveBalancePayload($assignment))->assertRedirect();
        $account = $company->leaveBalanceAccounts()->firstOrFail();
        $this->get(leaveBalanceUrl($company))->assertOk();
        $closeResponse = $this->post(route('companies.leave-balances.close', [$company, $account]));
        if ($role === 'hr_manager') {
            $closeResponse->assertForbidden();
        } else {
            $closeResponse->assertRedirect();
        }
    }
});

test('account list searches filters paginates and calculates aggregate balances within the tenant', function () {
    [$hr, $company] = leaveBalanceActor('hr_admin');
    $employees = Employee::factory()->count(21)->create(['company_id' => $company->id]);
    $type = LeaveType::factory()->create(['company_id' => $company->id, 'name' => 'Annual Searchable', 'code' => 'ANNUAL-SEARCH']);
    $policy = LeavePolicy::factory()->create(['company_id' => $company->id, 'leave_type_id' => $type->id, 'name' => 'Annual Standard', 'code' => 'ANNUAL-STD']);
    foreach ($employees as $index => $employee) {
        $assignment = leaveBalanceAssignment($company, $employee, $policy);
        $account = leaveBalanceAccount($company, $assignment);
        leaveBalanceEntry($account, 'opening_balance', (string) ($index + 1).'.00', '2026-01-01');
    }

    $this->actingAs($hr)->get(leaveBalanceUrl($company))
        ->assertInertia(fn (Assert $page) => $page->where('accounts.total', 21)->has('accounts.data', 20));
    $this->get(leaveBalanceUrl($company).'?search='.$employees[0]->employee_code)
        ->assertInertia(fn (Assert $page) => $page->where('accounts.total', 1)->where('accounts.data.0.balance', '1.00'));
    $this->get(leaveBalanceUrl($company).'?leave_type_id='.$type->id.'&leave_policy_id='.$policy->id.'&status=open&date=2026-06-01')
        ->assertInertia(fn (Assert $page) => $page->where('accounts.total', 21));
});

test('factory produces tenant-consistent account and ledger relationships', function () {
    $account = EmployeeLeaveBalanceAccount::factory()->create();
    $entry = LeaveLedgerEntry::factory()->create(['leave_balance_account_id' => $account->id]);

    expect($account->employee->company_id)->toBe($account->company_id)
        ->and($account->leaveType->company_id)->toBe($account->company_id)
        ->and($account->leavePolicy->company_id)->toBe($account->company_id)
        ->and($account->assignment->id)->toBe($account->employee_leave_policy_assignment_id)
        ->and($entry->account->id)->toBe($account->id)
        ->and($entry->employee_id)->toBe($account->employee_id)
        ->and($entry->leave_type_id)->toBe($account->leave_type_id)
        ->and($entry->leave_policy_id)->toBe($account->leave_policy_id)
        ->and($entry->employee_leave_policy_assignment_id)->toBe($account->employee_leave_policy_assignment_id);
});

test('unauthorized company roles cannot list or create leave balance accounts', function (string $role) {
    [$user, $company] = leaveBalanceActor($role);
    $employee = Employee::factory()->create(['company_id' => $company->id]);
    [, , $assignment] = leaveBalancePolicy($company, $employee);

    $this->actingAs($user)->get(leaveBalanceUrl($company))->assertForbidden();
    $this->get(route('companies.leave-balances.create', $company))->assertForbidden();
    $this->post(leaveBalanceUrl($company), leaveBalancePayload($assignment))->assertForbidden();
})->with(['employee', 'accountant']);
