<?php

use App\Actions\SeedCompanyRoles;
use App\Enums\LeaveRequestDayType;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\DailyAttendanceRecord;
use App\Models\Employee;
use App\Models\EmployeeLeaveBalanceAccount;
use App\Models\EmployeeLeavePolicyAssignment;
use App\Models\LeaveLedgerEntry;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestDay;
use App\Models\LeaveType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** @return array{Company, User, Employee} */
function employeeLeaveHistoryIdentity(?Company $company = null, ?User $user = null): array
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

    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'user_id' => $user->id,
        'first_name' => 'Leena',
        'last_name' => 'Employee',
    ]);

    return [$company, $user, $employee];
}

/** @return array{LeaveType, LeavePolicy, EmployeeLeavePolicyAssignment, EmployeeLeaveBalanceAccount} */
function employeeLeaveHistoryConfiguration(Employee $employee, Company $company, string $typeName = 'Annual Leave'): array
{
    $type = LeaveType::factory()->create(['company_id' => $company->id, 'name' => $typeName]);
    $policy = LeavePolicy::factory()->create(['company_id' => $company->id, 'leave_type_id' => $type->id]);
    $assignment = EmployeeLeavePolicyAssignment::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'leave_policy_id' => $policy->id,
        'effective_from' => '2026-01-01',
        'effective_to' => null,
    ]);
    $account = EmployeeLeaveBalanceAccount::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'leave_policy_id' => $policy->id,
        'employee_leave_policy_assignment_id' => $assignment->id,
        'period_start' => '2026-01-01',
        'period_end' => '2026-12-31',
        'status' => 'open',
    ]);

    return [$type, $policy, $assignment, $account];
}

function employeeLeaveHistoryRequest(Employee $employee, Company $company, LeaveType $type, LeavePolicy $policy, EmployeeLeavePolicyAssignment $assignment, EmployeeLeaveBalanceAccount $account, array $attributes = []): LeaveRequest
{
    return LeaveRequest::factory()->create(array_merge([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'leave_policy_id' => $policy->id,
        'employee_leave_policy_assignment_id' => $assignment->id,
        'leave_balance_account_id' => $account->id,
        'requested_by' => $employee->user_id ?? User::factory()->create()->id,
        'requested_at' => '2026-10-01 10:00:00+00',
        'start_date' => '2026-10-20',
        'end_date' => '2026-10-20',
        'requested_days' => '1.00',
        'duration' => 'full_day',
        'status' => 'pending',
        'reason' => 'Personal leave',
    ], $attributes));
}

beforeEach(function (): void {
    config()->set('inertia.testing.page_paths', [resource_path('js/pages')]);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 08:00:00', 'Asia/Kolkata'));
});

test('employee leave request history is bounded, stable, filterable, and excludes coworker requests', function () {
    [$company, $user, $employee] = employeeLeaveHistoryIdentity();
    [$type, $policy, $assignment, $account] = employeeLeaveHistoryConfiguration($employee, $company);

    $ownRequests = collect(range(1, 17))->map(fn (int $index): LeaveRequest => employeeLeaveHistoryRequest(
        $employee,
        $company,
        $type,
        $policy,
        $assignment,
        $account,
        [
            'requested_at' => '2026-10-01 10:00:00+00',
            'start_date' => sprintf('2026-10-%02d', $index + 10),
            'end_date' => sprintf('2026-10-%02d', $index + 10),
            'status' => $index === 1 ? 'approved' : 'pending',
        ],
    ));

    $coworker = Employee::factory()->create(['company_id' => $company->id, 'first_name' => 'Other', 'last_name' => 'Employee']);
    [$coworkerType, $coworkerPolicy, $coworkerAssignment, $coworkerAccount] = employeeLeaveHistoryConfiguration($coworker, $company, 'Private coworker leave');
    employeeLeaveHistoryRequest($coworker, $company, $coworkerType, $coworkerPolicy, $coworkerAssignment, $coworkerAccount);

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->get(route('employee.leave.requests.index', ['employee_id' => $coworker->id, 'company_id' => $company->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('employee-portal/leave-requests/index', false)
            ->has('history.requests', 15)
            ->where('history.pagination.total', 17)
            ->where('history.pagination.currentPage', 1)
            ->where('history.requests.0.href', route('employee.leave.requests.show', $ownRequests->last()->id))
            ->where('history.requests.0.statusLabel', 'Pending')
            ->where('history.pagination.nextUrl', fn (string $url): bool => ! str_contains($url, 'employee_id') && ! str_contains($url, 'company_id'))
            ->where('history.leaveTypes.0.name', 'Annual Leave')
            ->where('auth.user', null)
            ->where('companies', [])
            ->missing('history.requests.0.id')
            ->missing('history.requests.0.employee_id')
            ->missing('history.requests.0.company_id')
            ->missing('history.requests.0.review_note')
            ->missing('history.otherTenant'));

    $this->get(route('employee.leave.requests.index', ['page' => 2]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('history.requests', 2)
            ->where('history.pagination.currentPage', 2)
            ->where('history.pagination.total', 17));

    $this->get(route('employee.leave.requests.index', ['status' => 'pending']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('history.pagination.total', 16)
            ->where('history.filters.status', 'pending')
            ->where('history.pagination.nextUrl', fn (string $url): bool => str_contains($url, 'status=pending')));

    $this->get(route('employee.leave.requests.index', ['leave_type_id' => $coworkerType->id]))
        ->assertSessionHasErrors('leave_type_id');

    $this->get(route('employee.leave.requests.index', ['status' => 'approved']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('history.requests', 1)
            ->where('history.requests.0.status', 'approved')
            ->where('history.filters.status', 'approved'));

    $this->get(route('employee.leave.requests.index', ['date' => '2026-10-12']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('history.requests', 1)
            ->where('history.requests.0.startDate', '2026-10-12')
            ->where('history.filters.date', '2026-10-12'));

    $this->get(route('employee.leave.requests.index', ['status' => 'unknown']))
        ->assertSessionHasErrors('status');
});

test('employee request detail shows immutable saved day snapshots and omits internal review and cancellation notes', function () {
    [$company, $user, $employee] = employeeLeaveHistoryIdentity();
    [$type, $policy, $assignment, $account] = employeeLeaveHistoryConfiguration($employee, $company);
    $request = employeeLeaveHistoryRequest($employee, $company, $type, $policy, $assignment, $account, [
        'start_date' => '2026-10-20',
        'end_date' => '2026-10-22',
        'requested_days' => '1.50',
        'duration' => 'half_day',
        'reason' => '<script>do not execute</script>',
        'review_note' => 'Private reviewer note',
        'cancellation_reason' => 'Private cancellation note',
    ]);
    $request->days()->createMany([
        ['company_id' => $company->id, 'employee_id' => $employee->id, 'leave_date' => '2026-10-20', 'day_type' => LeaveRequestDayType::HalfDay, 'requested_amount' => '0.50'],
        ['company_id' => $company->id, 'employee_id' => $employee->id, 'leave_date' => '2026-10-21', 'day_type' => LeaveRequestDayType::Holiday, 'requested_amount' => '0.00'],
        ['company_id' => $company->id, 'employee_id' => $employee->id, 'leave_date' => '2026-10-22', 'day_type' => LeaveRequestDayType::FullDay, 'requested_amount' => '1.00'],
    ]);

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->get(route('employee.leave.requests.show', $request->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('employee-portal/leave-requests/show', false)
            ->where('leaveRequest.leaveType', 'Annual Leave')
            ->where('leaveRequest.status', 'pending')
            ->where('leaveRequest.requestedDays', '1.50')
            ->where('leaveRequest.durationLabel', 'Half day')
            ->where('leaveRequest.reason', '<script>do not execute</script>')
            ->where('leaveRequest.days.0.dayType', 'Half day')
            ->where('leaveRequest.days.0.amount', '0.50')
            ->where('leaveRequest.days.1.dayType', 'Holiday')
            ->where('leaveRequest.days.1.amount', '0.00')
            ->where('leaveRequest.days.2.dayType', 'Full day')
            ->where('leaveRequest.days.2.amount', '1.00')
            ->where('leaveRequest.timeline.0.label', 'Submitted')
            ->missing('leaveRequest.company_id')
            ->missing('leaveRequest.employee_id')
            ->missing('leaveRequest.reviewerId')
            ->missing('leaveRequest.reviewNote')
            ->missing('leaveRequest.cancellationReason')
            ->missing('leaveRequest.balanceAccountId')
            ->where('auth.user', null)
            ->where('activeCompany', null));
});

test('request detail distinguishes pending, reviewed, and cancelled lifecycle timestamps without exposing review notes', function () {
    [$company, $user, $employee] = employeeLeaveHistoryIdentity();
    [$type, $policy, $assignment, $account] = employeeLeaveHistoryConfiguration($employee, $company);
    $reviewer = User::factory()->create();
    $pending = employeeLeaveHistoryRequest($employee, $company, $type, $policy, $assignment, $account);
    $approved = employeeLeaveHistoryRequest($employee, $company, $type, $policy, $assignment, $account, [
        'status' => 'approved', 'reviewed_by' => $reviewer->id, 'reviewed_at' => '2026-10-02 10:00:00+00', 'review_note' => 'Internal approval note',
    ]);
    $rejected = employeeLeaveHistoryRequest($employee, $company, $type, $policy, $assignment, $account, [
        'status' => 'rejected', 'reviewed_by' => $reviewer->id, 'reviewed_at' => '2026-10-03 10:00:00+00', 'review_note' => 'Private rejection detail',
    ]);
    $cancelledPending = employeeLeaveHistoryRequest($employee, $company, $type, $policy, $assignment, $account, [
        'status' => 'cancelled', 'cancelled_by' => $reviewer->id, 'cancelled_at' => '2026-10-04 10:00:00+00', 'cancellation_reason' => 'Private cancellation detail',
    ]);
    $cancelledApproved = employeeLeaveHistoryRequest($employee, $company, $type, $policy, $assignment, $account, [
        'status' => 'cancelled', 'reviewed_by' => $reviewer->id, 'reviewed_at' => '2026-10-02 10:00:00+00',
        'cancelled_by' => $reviewer->id, 'cancelled_at' => '2026-10-04 10:00:00+00', 'cancellation_reason' => 'Private cancellation detail',
    ]);

    $this->actingAs($user)->withSession(['active_company_id' => $company->id]);
    $this->get(route('employee.leave.requests.show', $pending->id))
        ->assertInertia(fn (Assert $page) => $page->where('leaveRequest.statusLabel', 'Pending')->has('leaveRequest.timeline', 1));
    $this->get(route('employee.leave.requests.show', $approved->id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('leaveRequest.statusLabel', 'Approved')
            ->where('leaveRequest.timeline.1.label', 'Approved')
            ->missing('leaveRequest.reviewNote')
            ->missing('leaveRequest.reviewedBy'));
    $this->get(route('employee.leave.requests.show', $rejected->id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('leaveRequest.statusLabel', 'Rejected')
            ->where('leaveRequest.timeline.1.label', 'Rejected')
            ->missing('leaveRequest.reviewNote'));
    $this->get(route('employee.leave.requests.show', $cancelledPending->id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('leaveRequest.statusLabel', 'Cancelled')
            ->where('leaveRequest.timeline.1.label', 'Cancelled')
            ->missing('leaveRequest.cancellationReason'));
    $this->get(route('employee.leave.requests.show', $cancelledApproved->id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('leaveRequest.statusLabel', 'Cancelled')
            ->where('leaveRequest.timeline.1.label', 'Approved before cancellation')
            ->where('leaveRequest.timeline.2.label', 'Cancelled'));
});

test('employee cannot open a coworker or another company request by changing the route id', function () {
    [$companyA, $user, $employeeA] = employeeLeaveHistoryIdentity();
    [$typeA, $policyA, $assignmentA, $accountA] = employeeLeaveHistoryConfiguration($employeeA, $companyA);
    $ownRequest = employeeLeaveHistoryRequest($employeeA, $companyA, $typeA, $policyA, $assignmentA, $accountA);

    $coworker = Employee::factory()->create(['company_id' => $companyA->id]);
    [$coworkerType, $coworkerPolicy, $coworkerAssignment, $coworkerAccount] = employeeLeaveHistoryConfiguration($coworker, $companyA, 'Coworker private');
    $coworkerRequest = employeeLeaveHistoryRequest($coworker, $companyA, $coworkerType, $coworkerPolicy, $coworkerAssignment, $coworkerAccount);

    $companyB = Company::factory()->create(['timezone' => 'Asia/Kolkata']);
    $employeeB = Employee::factory()->create(['company_id' => $companyB->id]);
    [$typeB, $policyB, $assignmentB, $accountB] = employeeLeaveHistoryConfiguration($employeeB, $companyB, 'Tenant private');
    $foreignRequest = employeeLeaveHistoryRequest($employeeB, $companyB, $typeB, $policyB, $assignmentB, $accountB);

    $this->actingAs($user)->withSession(['active_company_id' => $companyA->id]);
    $this->get(route('employee.leave.requests.show', $ownRequest->id))->assertOk();
    $this->get(route('employee.leave.requests.show', $coworkerRequest->id))->assertNotFound();
    $this->get(route('employee.leave.requests.show', $foreignRequest->id))->assertNotFound();
});

test('historical requests remain visible after a type is archived and current assignments change', function () {
    [$company, $user, $employee] = employeeLeaveHistoryIdentity();
    [$type, $policy, $assignment, $account] = employeeLeaveHistoryConfiguration($employee, $company, 'Legacy Annual Leave');
    $request = employeeLeaveHistoryRequest($employee, $company, $type, $policy, $assignment, $account);
    $type->delete();
    $assignment->update(['status' => 'inactive']);

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->get(route('employee.leave.requests.index', ['leave_type_id' => $type->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('history.requests', 1)
            ->where('history.requests.0.leaveType', 'Legacy Annual Leave')
            ->where('history.requests.0.href', route('employee.leave.requests.show', $request->id))
            ->where('history.leaveTypes.0.id', $type->id));
});

test('same employee user sees only the active company request history after company switching', function () {
    [$companyA, $user, $employeeA] = employeeLeaveHistoryIdentity();
    [$typeA, $policyA, $assignmentA, $accountA] = employeeLeaveHistoryConfiguration($employeeA, $companyA, 'Company A Leave');
    $requestA = employeeLeaveHistoryRequest($employeeA, $companyA, $typeA, $policyA, $assignmentA, $accountA);

    $companyB = Company::factory()->create(['timezone' => 'Asia/Kolkata']);
    CompanyUser::query()->create(['company_id' => $companyB->id, 'user_id' => $user->id, 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($companyB);
    $registrar = app(PermissionRegistrar::class);
    $previousTeamId = $registrar->getPermissionsTeamId();
    $registrar->setPermissionsTeamId($companyB->id);
    $user->assignRole('employee');
    $registrar->setPermissionsTeamId($previousTeamId);
    $employeeB = Employee::factory()->create(['company_id' => $companyB->id, 'user_id' => $user->id]);
    [$typeB, $policyB, $assignmentB, $accountB] = employeeLeaveHistoryConfiguration($employeeB, $companyB, 'Company B Leave');
    employeeLeaveHistoryRequest($employeeB, $companyB, $typeB, $policyB, $assignmentB, $accountB);

    $this->actingAs($user)->withSession(['active_company_id' => $companyA->id])
        ->get(route('employee.leave.requests.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('history.requests.0.leaveType', 'Company A Leave')
            ->has('history.requests', 1));

    $this->withSession(['active_company_id' => $companyB->id])
        ->get(route('employee.leave.requests.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('history.requests.0.leaveType', 'Company B Leave')
            ->has('history.requests', 1));
    $this->get(route('employee.leave.requests.show', $requestA->id))->assertNotFound();
});

test('employee request history and detail GETs do not mutate leave or attendance records', function () {
    [$company, $user, $employee] = employeeLeaveHistoryIdentity();
    [$type, $policy, $assignment, $account] = employeeLeaveHistoryConfiguration($employee, $company);
    $request = employeeLeaveHistoryRequest($employee, $company, $type, $policy, $assignment, $account);
    $before = [
        LeaveRequest::query()->count(),
        LeaveRequestDay::query()->count(),
        LeaveLedgerEntry::query()->count(),
        EmployeeLeaveBalanceAccount::query()->count(),
        DailyAttendanceRecord::query()->count(),
    ];

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->get(route('employee.leave.requests.index'))->assertOk();
    $this->get(route('employee.leave.requests.show', $request->id))->assertOk();

    expect([
        LeaveRequest::query()->count(),
        LeaveRequestDay::query()->count(),
        LeaveLedgerEntry::query()->count(),
        EmployeeLeaveBalanceAccount::query()->count(),
        DailyAttendanceRecord::query()->count(),
    ])->toBe($before);
});

test('employee without self-view permission cannot access request history', function () {
    [$company, $user] = employeeLeaveHistoryIdentity();
    $registrar = app(PermissionRegistrar::class);
    $previousTeamId = $registrar->getPermissionsTeamId();
    $registrar->setPermissionsTeamId($company->id);
    Role::query()->where('name', 'employee')->firstOrFail()->revokePermissionTo('leave_request.self_view');
    $registrar->setPermissionsTeamId($previousTeamId);

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->get(route('employee.leave.requests.index'))
        ->assertForbidden();
});

test('request history visibility is independent from the self-apply permission', function () {
    [$company, $user] = employeeLeaveHistoryIdentity();
    $registrar = app(PermissionRegistrar::class);
    $previousTeamId = $registrar->getPermissionsTeamId();
    $registrar->setPermissionsTeamId($company->id);
    Role::query()->where('name', 'employee')->firstOrFail()->revokePermissionTo('leave_request.self_create');
    $registrar->setPermissionsTeamId($previousTeamId);

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->get(route('employee.leave.requests.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('canApplyForLeave', false)
            ->where('employeeName', 'Leena Employee'));
});
