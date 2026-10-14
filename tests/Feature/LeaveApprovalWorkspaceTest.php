<?php

use App\Actions\LeaveRequests\ApproveLeaveRequest;
use App\Actions\LeaveRequests\RejectLeaveRequest;
use App\Actions\SeedCompanyRoles;
use App\Enums\LeaveRequestDayType;
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
use App\Services\Leave\LeaveRequestReviewerScope;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** @return array{Company, User} */
function leaveApprovalWorkspaceActor(string $role = 'hr_admin', ?Company $company = null, ?User $user = null): array
{
    $company ??= Company::factory()->create(['timezone' => 'Asia/Kolkata']);
    $user ??= User::factory()->create();
    CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $user->id, 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($company);
    $registrar = app(PermissionRegistrar::class);
    $previousTeamId = $registrar->getPermissionsTeamId();
    $registrar->setPermissionsTeamId($company->id);
    $user->assignRole($role);
    $registrar->setPermissionsTeamId($previousTeamId);

    return [$company, $user];
}

/** @param list<string> $permissions */
function leaveApprovalWorkspaceCustomReviewer(Company $company, array $permissions): User
{
    $user = User::factory()->create();
    CompanyUser::query()->create(['company_id' => $company->getKey(), 'user_id' => $user->getKey(), 'status' => 'active']);
    $role = Role::query()->create([
        'team_id' => $company->getKey(),
        'name' => 'reviewer_'.uniqid(),
        'guard_name' => 'web',
    ]);
    $role->givePermissionTo(Permission::query()->where('guard_name', 'web')->whereIn('name', $permissions)->get());
    $registrar = app(PermissionRegistrar::class);
    $previousTeamId = $registrar->getPermissionsTeamId();
    $registrar->setPermissionsTeamId($company->getKey());
    $user->assignRole($role);
    $registrar->setPermissionsTeamId($previousTeamId);

    return $user;
}

/** @return array{Employee, LeaveType, LeavePolicy, EmployeeLeavePolicyAssignment, EmployeeLeaveBalanceAccount, User} */
function leaveApprovalWorkspaceRequestData(Company $company, array $employeeAttributes = [], string $typeName = 'Annual Leave'): array
{
    $employee = Employee::factory()->create(array_merge(['company_id' => $company->id], $employeeAttributes));
    $type = LeaveType::factory()->create(['company_id' => $company->id, 'name' => $typeName]);
    $policy = LeavePolicy::factory()->create(['company_id' => $company->id, 'leave_type_id' => $type->id]);
    $assignment = EmployeeLeavePolicyAssignment::factory()->create([
        'company_id' => $company->id, 'employee_id' => $employee->id, 'leave_type_id' => $type->id,
        'leave_policy_id' => $policy->id, 'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31',
    ]);
    $account = EmployeeLeaveBalanceAccount::factory()->create([
        'company_id' => $company->id, 'employee_id' => $employee->id, 'leave_type_id' => $type->id,
        'leave_policy_id' => $policy->id, 'employee_leave_policy_assignment_id' => $assignment->id,
        'period_start' => '2026-01-01', 'period_end' => '2026-12-31', 'status' => 'open',
    ]);
    $requester = User::factory()->create();

    return [$employee, $type, $policy, $assignment, $account, $requester];
}

/** @param array<string, mixed> $attributes */
function leaveApprovalWorkspaceRequest(
    Company $company,
    Employee $employee,
    LeaveType $type,
    LeavePolicy $policy,
    EmployeeLeavePolicyAssignment $assignment,
    EmployeeLeaveBalanceAccount $account,
    User $requester,
    array $attributes = [],
    array $snapshotDays = [],
): LeaveRequest {
    $status = $attributes['status'] ?? LeaveRequestStatus::Pending;
    $reviewer = $attributes['reviewer'] ?? User::factory()->create();
    unset($attributes['status'], $attributes['reviewer']);

    $factory = LeaveRequest::factory();
    if ($status === LeaveRequestStatus::Approved) {
        $factory = $factory->approved($reviewer);
    } elseif ($status === LeaveRequestStatus::Rejected) {
        $factory = $factory->rejected($reviewer);
    } elseif ($status === LeaveRequestStatus::Cancelled) {
        $factory = $factory->cancelled();
    }

    $request = $factory->create(array_merge([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'leave_policy_id' => $policy->id,
        'employee_leave_policy_assignment_id' => $assignment->id,
        'leave_balance_account_id' => $account->id,
        'start_date' => '2026-10-12',
        'end_date' => '2026-10-12',
        'requested_days' => '1.00',
        'duration' => 'full_day',
        'status' => $status,
        'requested_by' => $requester->id,
        'requested_at' => '2026-10-06 04:00:00+00',
    ], $attributes));

    if (! $request->days()->exists()) {
        $snapshotDays = $snapshotDays ?: [[
            $request->start_date->toDateString(), LeaveRequestDayType::FullDay, (string) $request->requested_days,
        ]];
        foreach ($snapshotDays as [$date, $dayType, $amount]) {
            $request->days()->create([
                'company_id' => $company->id,
                'employee_id' => $employee->id,
                'leave_date' => $date,
                'day_type' => $dayType,
                'requested_amount' => $amount,
            ]);
        }
    }

    return $request->refresh();
}

beforeEach(function (): void {
    config()->set('inertia.testing.page_paths', [resource_path('js/pages')]);
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00:00', 'Asia/Kolkata'));
});

test('HR inbox defaults to pending, paginates deterministically, and exposes only explicit request summary fields', function () {
    [$company, $reviewer] = leaveApprovalWorkspaceActor();
    [$employee, $type, $policy, $assignment, $account, $requester] = leaveApprovalWorkspaceRequestData($company, [
        'first_name' => 'Asha', 'last_name' => 'Sharma', 'employee_code' => 'EMP-101',
    ]);

    $requests = collect(range(1, 21))->map(fn (int $index): LeaveRequest => leaveApprovalWorkspaceRequest(
        $company, $employee, $type, $policy, $assignment, $account, $requester,
        ['requested_at' => '2026-10-06 04:00:00+00', 'start_date' => sprintf('2026-10-%02d', $index + 10), 'end_date' => sprintf('2026-10-%02d', $index + 10)],
    ));
    leaveApprovalWorkspaceRequest($company, $employee, $type, $policy, $assignment, $account, $requester, ['status' => LeaveRequestStatus::Approved]);

    $this->actingAs($reviewer)->withSession(['active_company_id' => $company->id])
        ->get(route('leave.approvals.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('leave-approvals/index', false)
            ->where('filters.status', 'pending')
            ->where('requests.total', 21)
            ->where('requests.per_page', 20)
            ->where('requests.data.0.employee.name', 'Asha Sharma')
            ->where('requests.data.0.employee.code', 'EMP-101')
            ->where('requests.data.0.leaveType', 'Annual Leave')
            ->where('requests.data.0.requestedDays', '1.00')
            ->where('requests.data.0.statusLabel', 'Pending')
            ->where('requests.data.0.href', route('leave.approvals.show', $requests->last()->id))
            ->where('requests.next_page_url', fn (string $url): bool => str_contains($url, 'status=pending'))
            ->missing('requests.data.0.id')
            ->missing('requests.data.0.employee_id')
            ->missing('requests.data.0.company_id')
            ->missing('requests.data.0.requester_id')
            ->missing('requests.data.0.ledger_id'));
});

test('inbox filters statuses including historical requests and inactive leave types without recalculating requests', function () {
    [$company, $reviewer] = leaveApprovalWorkspaceActor();
    [$employee, $type, $policy, $assignment, $account, $requester] = leaveApprovalWorkspaceRequestData($company);
    $pending = leaveApprovalWorkspaceRequest($company, $employee, $type, $policy, $assignment, $account, $requester);
    $approved = leaveApprovalWorkspaceRequest($company, $employee, $type, $policy, $assignment, $account, $requester, ['status' => LeaveRequestStatus::Approved]);
    $rejected = leaveApprovalWorkspaceRequest($company, $employee, $type, $policy, $assignment, $account, $requester, ['status' => LeaveRequestStatus::Rejected]);
    $cancelled = leaveApprovalWorkspaceRequest($company, $employee, $type, $policy, $assignment, $account, $requester, ['status' => LeaveRequestStatus::Cancelled]);
    $type->delete();

    $this->actingAs($reviewer)->withSession(['active_company_id' => $company->id])
        ->get(route('leave.approvals.index', ['status' => 'all', 'search' => $employee->employee_code, 'leave_type_id' => $type->id, 'date_from' => '2026-10-01', 'date_to' => '2026-10-31']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('requests.total', 4)
            ->where('filters.status', 'all')
            ->where('requests.data.0.status', $cancelled->status->value)
            ->where('requests.data.1.status', $rejected->status->value)
            ->where('requests.data.2.status', $approved->status->value)
            ->where('requests.data.3.status', $pending->status->value)
            ->where('leaveTypes.0.name', 'Annual Leave'));

    $this->get(route('leave.approvals.index', ['date_from' => '2026-10-20', 'date_to' => '2026-10-01']))
        ->assertSessionHasErrors('date_to');
});

test('Manager inbox is constrained to own employee record and direct reports and out-of-scope detail is hidden', function () {
    [$company, $manager] = leaveApprovalWorkspaceActor('manager');
    $managerEmployee = Employee::factory()->create(['company_id' => $company->id, 'user_id' => $manager->id, 'employee_code' => 'M-01']);
    [$direct, $directType, $directPolicy, $directAssignment, $directAccount, $directRequester] = leaveApprovalWorkspaceRequestData($company, ['manager_id' => $managerEmployee->id, 'employee_code' => 'D-01']);
    [$other, $otherType, $otherPolicy, $otherAssignment, $otherAccount, $otherRequester] = leaveApprovalWorkspaceRequestData($company, ['employee_code' => 'X-01'], 'Other Leave');
    $directRequest = leaveApprovalWorkspaceRequest($company, $direct, $directType, $directPolicy, $directAssignment, $directAccount, $directRequester);
    $otherRequest = leaveApprovalWorkspaceRequest($company, $other, $otherType, $otherPolicy, $otherAssignment, $otherAccount, $otherRequester);

    $this->actingAs($manager)->withSession(['active_company_id' => $company->id])
        ->get(route('leave.approvals.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('requests.total', 1)
            ->where('requests.data.0.employee.code', 'D-01')
            ->where('canFilterDepartments', false)
            ->where('departments', []));

    $this->get(route('leave.approvals.show', $otherRequest->id))->assertNotFound();
    $this->get(route('leave.approvals.index', ['department_id' => 999]))->assertSessionHasErrors('department_id');
    $this->get(route('leave.approvals.show', $directRequest->id))->assertOk();
});

test('approval detail shows saved fractional day snapshots, current balance is clearly labelled, and GET is read-only', function () {
    [$company, $reviewer] = leaveApprovalWorkspaceActor();
    [$employee, $type, $policy, $assignment, $account, $requester] = leaveApprovalWorkspaceRequestData($company, [
        'first_name' => 'Mira', 'last_name' => 'Sen', 'employee_code' => 'EMP-220',
    ]);
    $request = leaveApprovalWorkspaceRequest($company, $employee, $type, $policy, $assignment, $account, $requester, [
        'start_date' => '2026-10-12', 'end_date' => '2026-10-14', 'requested_days' => '1.50',
    ], [
        ['2026-10-12', LeaveRequestDayType::FullDay, '1.00'],
        ['2026-10-13', LeaveRequestDayType::HalfDay, '0.50'],
        ['2026-10-14', LeaveRequestDayType::Holiday, '0.00'],
    ]);
    $account->ledgerEntries()->create([
        'company_id' => $company->id, 'employee_id' => $employee->id, 'leave_type_id' => $type->id,
        'leave_policy_id' => $policy->id, 'employee_leave_policy_assignment_id' => $assignment->id,
        'transaction_type' => 'opening_balance', 'amount' => '8.00', 'effective_date' => '2026-01-01',
        'description' => 'Opening balance', 'created_by' => $reviewer->id, 'created_at' => now(),
    ]);
    leaveApprovalWorkspaceRequest($company, $employee, $type, $policy, $assignment, $account, $requester, ['requested_days' => '1.00']);
    $countsBefore = [
        'requests' => LeaveRequest::query()->count(),
        'days' => DB::table('leave_request_days')->count(),
        'ledger' => LeaveLedgerEntry::query()->count(),
        'accounts' => EmployeeLeaveBalanceAccount::query()->count(),
    ];

    $this->actingAs($reviewer)->withSession(['active_company_id' => $company->id])
        ->get(route('leave.approvals.show', $request->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('leave-approvals/show', false)
            ->where('request.employee.name', 'Mira Sen')
            ->where('request.statusLabel', 'Pending')
            ->where('request.requestedDays', '1.50')
            ->where('request.days.1.dayType', 'Half day')
            ->where('request.days.1.amount', '0.50')
            ->where('request.days.2.dayType', 'Holiday')
            ->where('request.balance.ledgerBalance', '8.00')
            ->where('request.balance.pendingReserved', '2.50')
            ->where('request.balance.availableAfterPending', '5.50')
            ->where('request.balance.label', 'Current balance; this is not the balance at request submission.')
            ->where('request.timeline.0.label', 'Submitted')
            ->where('request.decisions.canApprove', true)
            ->where('request.decisions.approveUrl', route('companies.leave-requests.approve-from-inbox', [$company, $request]))
            ->where('request.decisions.canReject', true)
            ->where('request.decisions.rejectUrl', route('companies.leave-requests.reject-from-inbox', [$company, $request]))
            ->missing('request.employee.id')
            ->missing('request.company_id')
            ->missing('request.balanceAccountId')
            ->missing('request.policyId')
            ->missing('request.ledgerEntries'));

    expect([
        'requests' => LeaveRequest::query()->count(),
        'days' => DB::table('leave_request_days')->count(),
        'ledger' => LeaveLedgerEntry::query()->count(),
        'accounts' => EmployeeLeaveBalanceAccount::query()->count(),
    ])->toBe($countsBefore);
});

test('employee, inactive member, missing permission, and foreign company cannot open the reviewer workspace', function () {
    [$company, $employeeUser] = leaveApprovalWorkspaceActor('employee');
    $this->actingAs($employeeUser)->withSession(['active_company_id' => $company->id])
        ->get(route('leave.approvals.index'))->assertForbidden();

    [$secondCompany, $reviewer] = leaveApprovalWorkspaceActor();
    $this->actingAs($reviewer)->withSession(['active_company_id' => $secondCompany->id])
        ->get(route('leave.approvals.index'))->assertOk();
    CompanyUser::query()->where('company_id', $secondCompany->id)->where('user_id', $reviewer->id)->update(['status' => 'inactive']);
    $this->withSession(['active_company_id' => $secondCompany->id])->get(route('leave.approvals.index'))->assertForbidden();

    [$thirdCompany, $thirdReviewer] = leaveApprovalWorkspaceActor();
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($thirdCompany->id);
    Role::query()->where('team_id', $thirdCompany->id)->where('name', 'hr_admin')->firstOrFail()->revokePermissionTo('leave_request.view');
    $registrar->setPermissionsTeamId(null);
    $this->actingAs($thirdReviewer)->withSession(['active_company_id' => $thirdCompany->id])
        ->get(route('leave.approvals.index'))->assertForbidden();
});

test('same reviewer user sees only the selected company inbox and forged tenant filters do not expand scope', function () {
    $reviewer = User::factory()->create();
    [$companyA] = leaveApprovalWorkspaceActor('hr_admin', null, $reviewer);
    [$employeeA, $typeA, $policyA, $assignmentA, $accountA, $requesterA] = leaveApprovalWorkspaceRequestData($companyA, ['employee_code' => 'A-01']);
    $requestA = leaveApprovalWorkspaceRequest($companyA, $employeeA, $typeA, $policyA, $assignmentA, $accountA, $requesterA);
    [$companyB] = leaveApprovalWorkspaceActor('hr_admin', null, $reviewer);
    [$employeeB, $typeB, $policyB, $assignmentB, $accountB, $requesterB] = leaveApprovalWorkspaceRequestData($companyB, ['employee_code' => 'B-01']);
    $requestB = leaveApprovalWorkspaceRequest($companyB, $employeeB, $typeB, $policyB, $assignmentB, $accountB, $requesterB);

    $this->actingAs($reviewer)->withSession(['active_company_id' => $companyA->id])
        ->get(route('leave.approvals.index', ['company_id' => $companyB->id]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('requests.total', 1)->where('requests.data.0.employee.code', 'A-01'));

    $this->withSession(['active_company_id' => $companyB->id])
        ->get(route('leave.approvals.index'))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('requests.total', 1)->where('requests.data.0.employee.code', 'B-01'));

    $this->get(route('leave.approvals.show', $requestA->id))->assertNotFound();
    $this->get(route('leave.approvals.show', $requestB->id))->assertOk();
});

test('authorized HR approves from the workspace through the canonical action and records one ledger usage', function () {
    [$company, $reviewer] = leaveApprovalWorkspaceActor();
    [$employee, $type, $policy, $assignment, $account, $requester] = leaveApprovalWorkspaceRequestData($company);
    $account->ledgerEntries()->create([
        'company_id' => $company->id, 'employee_id' => $employee->id, 'leave_type_id' => $type->id,
        'leave_policy_id' => $policy->id, 'employee_leave_policy_assignment_id' => $assignment->id,
        'transaction_type' => 'opening_balance', 'amount' => '5.00', 'effective_date' => '2026-01-01',
        'description' => 'Opening balance', 'created_by' => $reviewer->id,
    ]);
    $request = leaveApprovalWorkspaceRequest($company, $employee, $type, $policy, $assignment, $account, $requester, [
        'employee_note' => 'Employee-submitted note remains unchanged.',
    ], [
        ['2026-10-12', LeaveRequestDayType::FullDay, '1.00'],
    ]);
    $daySnapshot = $request->days()->pluck('requested_amount', 'leave_date')->all();

    $approveUrl = route('companies.leave-requests.approve-from-inbox', [$company, $request]);
    $response = $this->actingAs($reviewer)->withSession(['active_company_id' => $company->id])
        ->post($approveUrl, [
            'review_note' => 'Reviewed and approved.',
            'employee_note' => 'Forged employee note',
            'status' => 'rejected',
            'company_id' => 99999,
            'employee_id' => 99999,
            'requested_days' => '99.00',
            'reviewed_by' => 99999,
        ])->assertSessionHasErrors(['company_id', 'employee_id', 'status', 'reviewed_by']);

    expect($request->fresh()->status)->toBe(LeaveRequestStatus::Pending)
        ->and($account->ledgerEntries()->where('transaction_type', 'leave_used')->count())->toBe(0);

    $response = $this->post($approveUrl, ['review_note' => 'Reviewed and approved.']);

    $response->assertRedirect(route('leave.approvals.show', $request->id))
        ->assertSessionHas('success', 'Leave request approved. Its reserved amount was applied through the Leave ledger.');
    $request->refresh();
    $usage = $account->ledgerEntries()->where('transaction_type', 'leave_used')->sole();

    expect($request->status)->toBe(LeaveRequestStatus::Approved)
        ->and($request->reviewed_by)->toBe($reviewer->id)
        ->and($request->reviewed_at)->not->toBeNull()
        ->and($request->review_note)->toBe('Reviewed and approved.')
        ->and($request->employee_note)->toBe('Employee-submitted note remains unchanged.')
        ->and($usage->amount)->toBe('-1.00')
        ->and($usage->reference_type)->toBe(LeaveRequest::class)
        ->and($usage->reference_id)->toBe($request->id)
        ->and($account->ledgerEntries()->where('transaction_type', 'leave_used')->count())->toBe(1)
        ->and(app(LeaveRequestReservationCalculator::class)->pendingCents($account))->toBe(0)
        ->and($request->days()->pluck('requested_amount', 'leave_date')->all())->toBe($daySnapshot);

    $this->get(route('leave.approvals.show', $request->id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('request.statusLabel', 'Approved')
            ->where('request.decisions.canApprove', false)
            ->where('request.decisions.canReject', false)
            ->where('request.decisions.approveUrl', null)
            ->where('request.reviewNote', 'Reviewed and approved.'));
});

test('authorized HR rejection requires a review reason and releases reservation without ledger consumption', function () {
    [$company, $reviewer] = leaveApprovalWorkspaceActor();
    [$employee, $type, $policy, $assignment, $account, $requester] = leaveApprovalWorkspaceRequestData($company);
    $account->ledgerEntries()->create([
        'company_id' => $company->id, 'employee_id' => $employee->id, 'leave_type_id' => $type->id,
        'leave_policy_id' => $policy->id, 'employee_leave_policy_assignment_id' => $assignment->id,
        'transaction_type' => 'opening_balance', 'amount' => '5.00', 'effective_date' => '2026-01-01',
        'description' => 'Opening balance', 'created_by' => $reviewer->id,
    ]);
    $request = leaveApprovalWorkspaceRequest($company, $employee, $type, $policy, $assignment, $account, $requester);
    $ledgerCount = $account->ledgerEntries()->count();

    $this->actingAs($reviewer)->withSession(['active_company_id' => $company->id])
        ->post(route('companies.leave-requests.reject-from-inbox', [$company, $request]), ['review_note' => '   '])
        ->assertSessionHasErrors('review_note');
    expect($request->fresh()->status)->toBe(LeaveRequestStatus::Pending);

    $rejectUrl = route('companies.leave-requests.reject-from-inbox', [$company, $request]);
    $this->post($rejectUrl, [
        'review_note' => 'Insufficient supporting information.',
        'status' => 'approved',
        'reviewed_by' => 99999,
        'employee_note' => 'Forged employee note',
    ])->assertSessionHasErrors(['status', 'reviewed_by']);
    expect($request->fresh()->status)->toBe(LeaveRequestStatus::Pending)
        ->and($account->ledgerEntries()->count())->toBe($ledgerCount);

    $this->post($rejectUrl, ['review_note' => 'Insufficient supporting information.'])
        ->assertRedirect(route('leave.approvals.show', $request->id))
        ->assertSessionHas('success', 'Leave request rejected. Its pending reservation has been released.');

    expect($request->fresh()->status)->toBe(LeaveRequestStatus::Rejected)
        ->and($request->fresh()->reviewed_by)->toBe($reviewer->id)
        ->and($request->fresh()->review_note)->toBe('Insufficient supporting information.')
        ->and($request->fresh()->employee_note)->toBeNull()
        ->and($account->ledgerEntries()->count())->toBe($ledgerCount)
        ->and($account->ledgerEntries()->where('transaction_type', 'leave_used')->count())->toBe(0)
        ->and(app(LeaveRequestReservationCalculator::class)->pendingCents($account))->toBe(0);
});

test('review permission alone never authorizes decisions and out-of-scope requests remain hidden', function () {
    [$company, $manager] = leaveApprovalWorkspaceActor('manager');
    $managerEmployee = Employee::factory()->create(['company_id' => $company->id, 'user_id' => $manager->id]);
    [$employee, $type, $policy, $assignment, $account, $requester] = leaveApprovalWorkspaceRequestData($company, ['manager_id' => $managerEmployee->id]);
    $request = leaveApprovalWorkspaceRequest($company, $employee, $type, $policy, $assignment, $account, $requester);
    [$coworker, $coworkerType, $coworkerPolicy, $coworkerAssignment, $coworkerAccount, $coworkerRequester] = leaveApprovalWorkspaceRequestData($company, [], 'Coworker Leave');
    $coworkerRequest = leaveApprovalWorkspaceRequest($company, $coworker, $coworkerType, $coworkerPolicy, $coworkerAssignment, $coworkerAccount, $coworkerRequester);

    $this->actingAs($manager)->withSession(['active_company_id' => $company->id])
        ->get(route('leave.approvals.show', $request->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('request.decisions.canApprove', false)
            ->where('request.decisions.approveUrl', null)
            ->where('request.decisions.canReject', false)
            ->where('request.decisions.rejectUrl', null));

    $this->post(route('companies.leave-requests.approve-from-inbox', [$company, $request]))->assertForbidden();
    $this->post(route('companies.leave-requests.reject-from-inbox', [$company, $request]), ['review_note' => 'Not approved by HR'])->assertForbidden();
    $this->get(route('leave.approvals.show', $coworkerRequest->id))->assertNotFound();
    expect($request->fresh()->status)->toBe(LeaveRequestStatus::Pending)
        ->and($request->balanceAccount->ledgerEntries()->where('transaction_type', 'leave_used')->count())->toBe(0);
});

test('review decision routes hide foreign companies and reject stale or already decided requests', function () {
    [$companyA, $reviewer] = leaveApprovalWorkspaceActor();
    [$companyB] = leaveApprovalWorkspaceActor('hr_admin', null, $reviewer);
    [$employee, $type, $policy, $assignment, $account, $requester] = leaveApprovalWorkspaceRequestData($companyB);
    $account->ledgerEntries()->create([
        'company_id' => $companyB->id, 'employee_id' => $employee->id, 'leave_type_id' => $type->id,
        'leave_policy_id' => $policy->id, 'employee_leave_policy_assignment_id' => $assignment->id,
        'transaction_type' => 'opening_balance', 'amount' => '5.00', 'effective_date' => '2026-01-01',
        'description' => 'Opening balance',
    ]);
    $request = leaveApprovalWorkspaceRequest($companyB, $employee, $type, $policy, $assignment, $account, $requester);

    $this->actingAs($reviewer)->withSession(['active_company_id' => $companyA->id])
        ->post(route('companies.leave-requests.approve-from-inbox', [$companyA, $request]))
        ->assertNotFound();
    expect($request->fresh()->status)->toBe(LeaveRequestStatus::Pending);

    $this->withSession(['active_company_id' => $companyB->id])
        ->post(route('companies.leave-requests.approve-from-inbox', [$companyB, $request]))
        ->assertRedirect(route('leave.approvals.show', $request->id));
    $ledgerCount = $account->ledgerEntries()->where('transaction_type', 'leave_used')->count();
    $this->post(route('companies.leave-requests.approve-from-inbox', [$companyB, $request]))
        ->assertSessionHasErrors(['request' => 'Only pending leave requests can be approved.']);

    expect($request->fresh()->status)->toBe(LeaveRequestStatus::Approved)
        ->and($account->ledgerEntries()->where('transaction_type', 'leave_used')->count())->toBe($ledgerCount)
        ->and($ledgerCount)->toBe(1);
});

test('reviewer scope fails closed unless company-wide or team visibility is explicitly granted', function () {
    [$company] = leaveApprovalWorkspaceActor();
    [$teamManager] = [leaveApprovalWorkspaceCustomReviewer($company, ['leave_request.view', 'employee.view_team'])];
    [$companyReviewer] = [leaveApprovalWorkspaceCustomReviewer($company, ['leave_request.view', 'employee.view'])];
    $viewOnly = leaveApprovalWorkspaceCustomReviewer($company, ['leave_request.view']);
    $approveWithoutScope = leaveApprovalWorkspaceCustomReviewer($company, ['leave_request.view', 'leave_request.approve']);
    $rejectWithoutScope = leaveApprovalWorkspaceCustomReviewer($company, ['leave_request.view', 'leave_request.reject']);
    $noScope = leaveApprovalWorkspaceCustomReviewer($company, ['leave_request.view', 'leave_request.approve', 'leave_request.reject']);
    $managerEmployee = Employee::factory()->create(['company_id' => $company->getKey(), 'user_id' => $teamManager->getKey()]);
    [$direct, $directType, $directPolicy, $directAssignment, $directAccount, $directRequester] = leaveApprovalWorkspaceRequestData($company, ['manager_id' => $managerEmployee->getKey()], 'Team Leave');
    [$other, $otherType, $otherPolicy, $otherAssignment, $otherAccount, $otherRequester] = leaveApprovalWorkspaceRequestData($company, [], 'Other Team Leave');
    $directRequest = leaveApprovalWorkspaceRequest($company, $direct, $directType, $directPolicy, $directAssignment, $directAccount, $directRequester);
    $otherRequest = leaveApprovalWorkspaceRequest($company, $other, $otherType, $otherPolicy, $otherAssignment, $otherAccount, $otherRequester);
    $scope = app(LeaveRequestReviewerScope::class);

    expect($scope->apply(LeaveRequest::query(), $viewOnly, $company)->count())->toBe(0)
        ->and($scope->apply(LeaveRequest::query(), $approveWithoutScope, $company)->count())->toBe(0)
        ->and($scope->apply(LeaveRequest::query(), $rejectWithoutScope, $company)->count())->toBe(0)
        ->and($scope->apply(LeaveRequest::query(), $noScope, $company)->count())->toBe(0)
        ->and($scope->apply(LeaveRequest::query(), $teamManager, $company)->pluck('id')->all())->toBe([$directRequest->getKey()])
        ->and($scope->apply(LeaveRequest::query(), $companyReviewer, $company)->count())->toBe(2)
        ->and($scope->canViewCompanyRequests($teamManager, $company))->toBeFalse()
        ->and($scope->canViewCompanyRequests($companyReviewer, $company))->toBeTrue();

    $this->actingAs($viewOnly)->withSession(['active_company_id' => $company->getKey()])
        ->get(route('leave.approvals.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('requests.total', 0)->where('canFilterDepartments', false));
    $this->get(route('leave.approvals.show', $directRequest->getKey()))->assertNotFound();
    $this->actingAs($teamManager)->withSession(['active_company_id' => $company->getKey()])
        ->get(route('leave.approvals.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('requests.total', 1)->where('canFilterDepartments', false));
    $this->actingAs($companyReviewer)->withSession(['active_company_id' => $company->getKey()])
        ->get(route('leave.approvals.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('requests.total', 2)->where('canFilterDepartments', true));
});

test('canonical review actions revalidate current membership permissions and reviewer scope inside the transaction', function () {
    [$company, $reviewer] = leaveApprovalWorkspaceActor('hr_admin');
    [$employee, $type, $policy, $assignment, $account, $requester] = leaveApprovalWorkspaceRequestData($company);
    $request = leaveApprovalWorkspaceRequest($company, $employee, $type, $policy, $assignment, $account, $requester);
    $registrar = app(PermissionRegistrar::class);
    $previousTeamId = $registrar->getPermissionsTeamId();
    $registrar->setPermissionsTeamId($company->getKey());
    $reviewer->syncRoles([]);
    $registrar->setPermissionsTeamId($previousTeamId);

    expect(fn () => app(ApproveLeaveRequest::class)->handle($request, $reviewer, $company))
        ->toThrow(AuthorizationException::class);
    expect($request->fresh()->status)->toBe(LeaveRequestStatus::Pending)
        ->and($account->ledgerEntries()->where('transaction_type', 'leave_used')->count())->toBe(0);

    $approveWithoutScope = leaveApprovalWorkspaceCustomReviewer($company, ['leave_request.view', 'leave_request.approve']);
    $rejectWithoutScope = leaveApprovalWorkspaceCustomReviewer($company, ['leave_request.view', 'leave_request.reject']);
    expect(fn () => app(ApproveLeaveRequest::class)->handle($request, $approveWithoutScope, $company))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(RejectLeaveRequest::class)->handle($request, $rejectWithoutScope, $company, 'No reviewer scope available'))
        ->toThrow(AuthorizationException::class);

    $companyReviewer = leaveApprovalWorkspaceCustomReviewer($company, [
        'leave_request.view', 'leave_request.reject', 'employee.view',
    ]);
    app(RejectLeaveRequest::class)->handle($request, $companyReviewer, $company, 'Declined after review');
    $employee->update(['user_id' => $companyReviewer->getKey()]);
    $selfReviewRequest = leaveApprovalWorkspaceRequest($company, $employee, $type, $policy, $assignment, $account, $requester);
    expect(fn () => app(ApproveLeaveRequest::class)->handle($selfReviewRequest, $companyReviewer, $company))
        ->toThrow(AuthorizationException::class);
    expect($request->fresh()->status)->toBe(LeaveRequestStatus::Rejected)
        ->and($request->fresh()->reviewed_by)->toBe($companyReviewer->getKey())
        ->and($account->ledgerEntries()->where('transaction_type', 'leave_used')->count())->toBe(0);
});

test('canonical review actions reject a reviewer whose active Company membership was removed', function () {
    [$company, $reviewer] = leaveApprovalWorkspaceActor('hr_admin');
    [$employee, $type, $policy, $assignment, $account, $requester] = leaveApprovalWorkspaceRequestData($company);
    $request = leaveApprovalWorkspaceRequest($company, $employee, $type, $policy, $assignment, $account, $requester);
    CompanyUser::query()->where('company_id', $company->getKey())->where('user_id', $reviewer->getKey())->update(['status' => 'inactive']);

    expect(fn () => app(ApproveLeaveRequest::class)->handle($request, $reviewer, $company))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(RejectLeaveRequest::class)->handle($request, $reviewer, $company, 'Membership is inactive'))
        ->toThrow(AuthorizationException::class);

    expect($request->fresh()->status)->toBe(LeaveRequestStatus::Pending)
        ->and($account->ledgerEntries()->where('transaction_type', 'leave_used')->count())->toBe(0);
});
