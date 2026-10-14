<?php

use App\Actions\SeedCompanyRoles;
use App\Enums\LeaveLedgerTransactionType;
use App\Enums\LeaveRequestStatus;
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
use App\Services\Leave\LeaveRequestReservationCalculator;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

/** @return array{Company, User, Employee} */
function employeeLeaveCancellationIdentity(?Company $company = null, ?User $user = null): array
{
    $company ??= Company::factory()->create(['timezone' => 'Asia/Kolkata']);
    $user ??= User::factory()->create();
    CompanyUser::query()->create(['company_id' => $company->getKey(), 'user_id' => $user->getKey(), 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($company);

    $registrar = app(PermissionRegistrar::class);
    $previousTeamId = $registrar->getPermissionsTeamId();
    $registrar->setPermissionsTeamId($company->getKey());
    $user->assignRole('employee');
    $registrar->setPermissionsTeamId($previousTeamId);

    $employee = Employee::factory()->create([
        'company_id' => $company->getKey(),
        'user_id' => $user->getKey(),
    ]);

    return [$company, $user, $employee];
}

/** @return array{LeaveType, LeavePolicy, EmployeeLeavePolicyAssignment, EmployeeLeaveBalanceAccount} */
function employeeLeaveCancellationConfiguration(Employee $employee, Company $company): array
{
    $type = LeaveType::factory()->create([
        'company_id' => $company->getKey(),
        'name' => 'Annual Leave '.$employee->getKey(),
    ]);
    $policy = LeavePolicy::factory()->create(['company_id' => $company->getKey(), 'leave_type_id' => $type->getKey()]);
    $assignment = EmployeeLeavePolicyAssignment::factory()->create([
        'company_id' => $company->getKey(),
        'employee_id' => $employee->getKey(),
        'leave_type_id' => $type->getKey(),
        'leave_policy_id' => $policy->getKey(),
        'effective_from' => '2026-01-01',
        'effective_to' => null,
    ]);
    $account = EmployeeLeaveBalanceAccount::factory()->create([
        'company_id' => $company->getKey(),
        'employee_id' => $employee->getKey(),
        'leave_type_id' => $type->getKey(),
        'leave_policy_id' => $policy->getKey(),
        'employee_leave_policy_assignment_id' => $assignment->getKey(),
        'period_start' => '2026-01-01',
        'period_end' => '2026-12-31',
        'status' => 'open',
    ]);

    return [$type, $policy, $assignment, $account];
}

/** @param array<string, mixed> $attributes */
function employeeLeaveCancellationRequest(Employee $employee, Company $company, LeaveType $type, LeavePolicy $policy, EmployeeLeavePolicyAssignment $assignment, EmployeeLeaveBalanceAccount $account, array $attributes = []): LeaveRequest
{
    return LeaveRequest::factory()->create(array_merge([
        'company_id' => $company->getKey(),
        'employee_id' => $employee->getKey(),
        'leave_type_id' => $type->getKey(),
        'leave_policy_id' => $policy->getKey(),
        'employee_leave_policy_assignment_id' => $assignment->getKey(),
        'leave_balance_account_id' => $account->getKey(),
        'start_date' => '2026-10-12',
        'end_date' => '2026-10-12',
        'requested_days' => '1.00',
        'duration' => 'full_day',
        'status' => LeaveRequestStatus::Pending,
        'requested_by' => $employee->user_id ?? User::factory()->create()->getKey(),
        'requested_at' => '2026-10-06 10:00:00+00',
    ], $attributes));
}

beforeEach(function (): void {
    config()->set('inertia.testing.page_paths', [resource_path('js/pages')]);
    $this->travelTo(CarbonImmutable::parse('2026-10-06 10:00:00', 'Asia/Kolkata'));
});

test('employee can withdraw own pending leave request and release reservation without ledger reversal', function () {
    [$company, $user, $employee] = employeeLeaveCancellationIdentity();
    [$type, $policy, $assignment, $account] = employeeLeaveCancellationConfiguration($employee, $company);
    $leaveRequest = employeeLeaveCancellationRequest($employee, $company, $type, $policy, $assignment, $account, [
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-01',
    ]);
    $ledgerEntry = $account->ledgerEntries()->create([
        'company_id' => $company->getKey(),
        'employee_id' => $employee->getKey(),
        'leave_type_id' => $type->getKey(),
        'leave_policy_id' => $policy->getKey(),
        'employee_leave_policy_assignment_id' => $assignment->getKey(),
        'transaction_type' => LeaveLedgerTransactionType::OpeningBalance,
        'amount' => '5.00',
        'effective_date' => '2026-01-01',
        'description' => 'Opening balance',
        'created_at' => now(),
    ]);
    $reservations = app(LeaveRequestReservationCalculator::class);
    $ledgerCount = $account->ledgerEntries()->count();

    $response = $this->actingAs($user)->withSession(['active_company_id' => $company->getKey()])
        ->post(route('employee.leave.requests.cancel', $leaveRequest->getKey()), [
            'cancellation_reason' => 'Plans changed',
        ]);

    $response->assertRedirect(route('employee.leave.requests.show', $leaveRequest->getKey()))
        ->assertSessionHas('success', 'Your pending leave request was withdrawn.');
    expect($leaveRequest->fresh()->status)->toBe(LeaveRequestStatus::Cancelled)
        ->and($leaveRequest->fresh()->cancelled_by)->toBe($user->getKey())
        ->and($leaveRequest->fresh()->cancelled_at)->not->toBeNull()
        ->and($leaveRequest->fresh()->cancellation_reason)->toBe('Plans changed')
        ->and($reservations->pendingCents($account->fresh()))->toBe(0)
        ->and($account->ledgerEntries()->count())->toBe($ledgerCount)
        ->and($ledgerEntry->fresh()->amount)->toBe('5.00');

    $this->post(route('employee.leave.requests.cancel', $leaveRequest->getKey()), [
        'cancellation_reason' => 'Retry after a response was lost',
    ])->assertRedirect(route('employee.leave.requests.show', $leaveRequest->getKey()))
        ->assertSessionHas('warning');

    expect($leaveRequest->fresh()->cancellation_reason)->toBe('Plans changed')
        ->and($account->ledgerEntries()->count())->toBe($ledgerCount);

    $this->get(route('employee.leave.requests.show', $leaveRequest->getKey()))
        ->assertInertia(fn (Assert $page) => $page
            ->where('leaveRequest.status', 'cancelled')
            ->where('canWithdraw', false)
            ->where('withdrawUrl', null)
            ->missing('leaveRequest.cancellationReason'));
});

test('employee detail exposes withdrawal eligibility only for an owned pending request', function () {
    [$company, $user, $employee] = employeeLeaveCancellationIdentity();
    [$type, $policy, $assignment, $account] = employeeLeaveCancellationConfiguration($employee, $company);
    $pending = employeeLeaveCancellationRequest($employee, $company, $type, $policy, $assignment, $account);
    $approved = employeeLeaveCancellationRequest($employee, $company, $type, $policy, $assignment, $account, [
        'status' => LeaveRequestStatus::Approved,
        'reviewed_by' => User::factory()->create()->getKey(),
        'reviewed_at' => now(),
    ]);

    $this->actingAs($user)->withSession(['active_company_id' => $company->getKey()])
        ->get(route('employee.leave.requests.show', $pending->getKey()))
        ->assertInertia(fn (Assert $page) => $page
            ->where('canWithdraw', true)
            ->where('withdrawUrl', route('employee.leave.requests.cancel', $pending->getKey()))
            ->where('auth.user', null)
            ->where('companies', [])
            ->missing('leaveRequest.employee_id')
            ->missing('leaveRequest.company_id'));

    $this->get(route('employee.leave.requests.show', $approved->getKey()))
        ->assertInertia(fn (Assert $page) => $page
            ->where('canWithdraw', false)
            ->where('withdrawUrl', null));
});

test('employee cannot withdraw a coworker or another company request', function () {
    [$company, $user, $employee] = employeeLeaveCancellationIdentity();
    [$ownType, $ownPolicy, $ownAssignment, $ownAccount] = employeeLeaveCancellationConfiguration($employee, $company);
    $ownRequest = employeeLeaveCancellationRequest($employee, $company, $ownType, $ownPolicy, $ownAssignment, $ownAccount);

    $coworker = Employee::factory()->create(['company_id' => $company->getKey()]);
    [$coworkerType, $coworkerPolicy, $coworkerAssignment, $coworkerAccount] = employeeLeaveCancellationConfiguration($coworker, $company);
    $coworkerRequest = employeeLeaveCancellationRequest($coworker, $company, $coworkerType, $coworkerPolicy, $coworkerAssignment, $coworkerAccount);

    $foreignCompany = Company::factory()->create();
    $foreignEmployee = Employee::factory()->create(['company_id' => $foreignCompany->getKey()]);
    [$foreignType, $foreignPolicy, $foreignAssignment, $foreignAccount] = employeeLeaveCancellationConfiguration($foreignEmployee, $foreignCompany);
    $foreignRequest = employeeLeaveCancellationRequest($foreignEmployee, $foreignCompany, $foreignType, $foreignPolicy, $foreignAssignment, $foreignAccount);

    $this->actingAs($user)->withSession(['active_company_id' => $company->getKey()]);
    $this->post(route('employee.leave.requests.cancel', $coworkerRequest->getKey()), ['cancellation_reason' => 'Plans changed'])->assertNotFound();
    $this->post(route('employee.leave.requests.cancel', $foreignRequest->getKey()), ['cancellation_reason' => 'Plans changed'])->assertNotFound();
    $this->get(route('employee.leave.requests.show', $ownRequest->getKey()))->assertOk();

    expect($coworkerRequest->fresh()->status)->toBe(LeaveRequestStatus::Pending)
        ->and($foreignRequest->fresh()->status)->toBe(LeaveRequestStatus::Pending);
});

test('same user withdraws only the selected active company employee request', function () {
    [$companyA, $user, $employeeA] = employeeLeaveCancellationIdentity();
    [$typeA, $policyA, $assignmentA, $accountA] = employeeLeaveCancellationConfiguration($employeeA, $companyA);
    $requestA = employeeLeaveCancellationRequest($employeeA, $companyA, $typeA, $policyA, $assignmentA, $accountA);

    $companyB = Company::factory()->create(['timezone' => 'Asia/Kolkata']);
    [, , $employeeB] = employeeLeaveCancellationIdentity($companyB, $user);
    [$typeB, $policyB, $assignmentB, $accountB] = employeeLeaveCancellationConfiguration($employeeB, $companyB);
    $requestB = employeeLeaveCancellationRequest($employeeB, $companyB, $typeB, $policyB, $assignmentB, $accountB);

    $this->actingAs($user)->withSession(['active_company_id' => $companyB->getKey()])
        ->post(route('employee.leave.requests.cancel', $requestB->getKey()), [
            'cancellation_reason' => 'Plans changed',
        ])->assertRedirect(route('employee.leave.requests.show', $requestB->getKey()));

    expect($requestB->fresh()->status)->toBe(LeaveRequestStatus::Cancelled)
        ->and($requestA->fresh()->status)->toBe(LeaveRequestStatus::Pending);

    $this->get(route('employee.leave.requests.show', $requestA->getKey()))->assertNotFound();
});

test('archived employee identity cannot withdraw a leave request', function () {
    [$company, $user, $employee] = employeeLeaveCancellationIdentity();
    [$type, $policy, $assignment, $account] = employeeLeaveCancellationConfiguration($employee, $company);
    $leaveRequest = employeeLeaveCancellationRequest($employee, $company, $type, $policy, $assignment, $account);
    $employee->delete();

    $this->actingAs($user)->withSession(['active_company_id' => $company->getKey()])
        ->post(route('employee.leave.requests.cancel', $leaveRequest->getKey()), [
            'cancellation_reason' => 'Plans changed',
        ])->assertForbidden();

    expect($leaveRequest->fresh()->status)->toBe(LeaveRequestStatus::Pending);
});

test('approved rejected and already cancelled requests cannot be withdrawn or create duplicate effects', function () {
    [$company, $user, $employee] = employeeLeaveCancellationIdentity();
    [$type, $policy, $assignment, $account] = employeeLeaveCancellationConfiguration($employee, $company);
    $reviewer = User::factory()->create();
    $approved = employeeLeaveCancellationRequest($employee, $company, $type, $policy, $assignment, $account, [
        'status' => LeaveRequestStatus::Approved,
        'reviewed_by' => $reviewer->getKey(),
        'reviewed_at' => now(),
    ]);
    $rejected = employeeLeaveCancellationRequest($employee, $company, $type, $policy, $assignment, $account, [
        'status' => LeaveRequestStatus::Rejected,
        'reviewed_by' => $reviewer->getKey(),
        'reviewed_at' => now(),
        'review_note' => 'Not approved this time',
    ]);
    $cancelled = employeeLeaveCancellationRequest($employee, $company, $type, $policy, $assignment, $account, [
        'status' => LeaveRequestStatus::Cancelled,
        'cancelled_by' => $reviewer->getKey(),
        'cancelled_at' => now(),
        'cancellation_reason' => 'Already cancelled',
    ]);

    $this->actingAs($user)->withSession(['active_company_id' => $company->getKey()]);
    foreach ([$approved, $rejected, $cancelled] as $leaveRequest) {
        $this->post(route('employee.leave.requests.cancel', $leaveRequest->getKey()), [
            'cancellation_reason' => 'Plans changed',
        ])->assertRedirect(route('employee.leave.requests.show', $leaveRequest->getKey()))
            ->assertSessionHas('warning');
    }

    expect($approved->fresh()->status)->toBe(LeaveRequestStatus::Approved)
        ->and($rejected->fresh()->status)->toBe(LeaveRequestStatus::Rejected)
        ->and($cancelled->fresh()->cancellation_reason)->toBe('Already cancelled')
        ->and($account->ledgerEntries()->count())->toBe(0);
});

test('employee withdrawal rejects missing permission forged fields and invalid reason', function () {
    [$company, $user, $employee] = employeeLeaveCancellationIdentity();
    [$type, $policy, $assignment, $account] = employeeLeaveCancellationConfiguration($employee, $company);
    $leaveRequest = employeeLeaveCancellationRequest($employee, $company, $type, $policy, $assignment, $account);

    $response = $this->actingAs($user)->withSession(['active_company_id' => $company->getKey()])
        ->post(route('employee.leave.requests.cancel', $leaveRequest->getKey()), [
            'cancellation_reason' => '  ',
            'company_id' => $company->getKey() + 500,
            'employee_id' => $employee->getKey() + 500,
            'user_id' => $user->getKey() + 500,
            'status' => 'approved',
            'approved_days' => '90',
        ]);

    $response->assertSessionHasErrors(['cancellation_reason', 'company_id', 'employee_id', 'user_id', 'status', 'approved_days']);
    expect($leaveRequest->fresh()->status)->toBe(LeaveRequestStatus::Pending)
        ->and($leaveRequest->fresh()->cancelled_by)->toBeNull()
        ->and($account->ledgerEntries()->count())->toBe(0);
});

test('employee cannot withdraw a request when self cancellation permission is removed', function () {
    [$company, $user, $employee] = employeeLeaveCancellationIdentity();
    [$type, $policy, $assignment, $account] = employeeLeaveCancellationConfiguration($employee, $company);
    $leaveRequest = employeeLeaveCancellationRequest($employee, $company, $type, $policy, $assignment, $account);
    $registrar = app(PermissionRegistrar::class);
    $previousTeamId = $registrar->getPermissionsTeamId();
    $registrar->setPermissionsTeamId($company->getKey());
    $user->roles()->where('name', 'employee')->firstOrFail()->revokePermissionTo('leave_request.self_cancel');
    $registrar->setPermissionsTeamId($previousTeamId);

    $this->actingAs($user)->withSession(['active_company_id' => $company->getKey()])
        ->post(route('employee.leave.requests.cancel', $leaveRequest->getKey()), [
            'cancellation_reason' => 'Plans changed',
        ])->assertForbidden();

    expect($leaveRequest->fresh()->status)->toBe(LeaveRequestStatus::Pending);
});

test('employee leave detail get remains read only', function () {
    [$company, $user, $employee] = employeeLeaveCancellationIdentity();
    [$type, $policy, $assignment, $account] = employeeLeaveCancellationConfiguration($employee, $company);
    $leaveRequest = employeeLeaveCancellationRequest($employee, $company, $type, $policy, $assignment, $account);
    $requestCount = LeaveRequest::query()->count();
    $ledgerCount = LeaveLedgerEntry::query()->count();

    $this->actingAs($user)->withSession(['active_company_id' => $company->getKey()])
        ->get(route('employee.leave.requests.show', $leaveRequest->getKey()))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canWithdraw', true));

    expect(LeaveRequest::query()->count())->toBe($requestCount)
        ->and(LeaveLedgerEntry::query()->count())->toBe($ledgerCount)
        ->and($leaveRequest->fresh()->status)->toBe(LeaveRequestStatus::Pending);
});
