<?php

use App\Actions\LeaveRequests\ApproveLeaveRequest;
use App\Actions\LeaveRequests\CancelLeaveRequest;
use App\Actions\LeaveRequests\CreateLeaveRequest;
use App\Actions\LeaveRequests\RejectLeaveRequest;
use App\Actions\SeedCompanyRoles;
use App\Enums\DayOfWeek;
use App\Enums\LeaveLedgerTransactionType;
use App\Enums\LeaveRequestDayType;
use App\Enums\LeaveRequestStatus;
use App\Enums\WorkScheduleDayType;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\EmployeeLeaveBalanceAccount;
use App\Models\EmployeeLeavePolicyAssignment;
use App\Models\LeaveLedgerEntry;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Notifications\LeaveRequestApproved;
use App\Notifications\LeaveRequestRejected;
use App\Notifications\LeaveRequestSubmitted;
use App\Notifications\LeaveRequestWithdrawn;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** @return array{Company, User, Employee} */
function leaveLifecycleEmployee(?Company $company = null): array
{
    $company ??= Company::factory()->create(['timezone' => 'Asia/Kolkata']);
    $user = User::factory()->create();
    CompanyUser::query()->create(['company_id' => $company->getKey(), 'user_id' => $user->getKey(), 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($company);
    leaveLifecycleAssignRole($user, $company, 'employee');
    $employee = Employee::factory()->create(['company_id' => $company->getKey(), 'user_id' => $user->getKey()]);

    return [$company, $user, $employee];
}

function leaveLifecycleMember(Company $company, string $role): User
{
    $user = User::factory()->create();
    CompanyUser::query()->create(['company_id' => $company->getKey(), 'user_id' => $user->getKey(), 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($company);
    leaveLifecycleAssignRole($user, $company, $role);

    return $user;
}

function leaveLifecycleAssignRole(User $user, Company $company, string $role): void
{
    $registrar = app(PermissionRegistrar::class);
    $previousTeamId = $registrar->getPermissionsTeamId();
    $registrar->setPermissionsTeamId($company->getKey());
    $user->unsetRelation('roles')->unsetRelation('permissions')->assignRole($role);
    $registrar->setPermissionsTeamId($previousTeamId);
}

/** @return array{LeaveType, LeavePolicy, EmployeeLeavePolicyAssignment, EmployeeLeaveBalanceAccount} */
function leaveLifecycleConfiguration(Company $company, Employee $employee, string $openingBalance = '10.00'): array
{
    $type = LeaveType::factory()->create(['company_id' => $company->getKey(), 'name' => 'Annual Leave '.$employee->getKey()]);
    $policy = LeavePolicy::factory()->create([
        'company_id' => $company->getKey(),
        'leave_type_id' => $type->getKey(),
        'minimum_request_days' => '0.50',
        'minimum_notice_days' => 0,
    ]);
    $assignment = EmployeeLeavePolicyAssignment::factory()->create([
        'company_id' => $company->getKey(),
        'employee_id' => $employee->getKey(),
        'leave_type_id' => $type->getKey(),
        'leave_policy_id' => $policy->getKey(),
        'effective_from' => '2026-01-01',
        'effective_to' => '2026-12-31',
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
    $account->ledgerEntries()->create([
        'company_id' => $company->getKey(),
        'employee_id' => $employee->getKey(),
        'leave_type_id' => $type->getKey(),
        'leave_policy_id' => $policy->getKey(),
        'employee_leave_policy_assignment_id' => $assignment->getKey(),
        'transaction_type' => LeaveLedgerTransactionType::OpeningBalance,
        'amount' => $openingBalance,
        'effective_date' => '2026-01-01',
        'description' => 'Lifecycle notification fixture',
    ]);

    return [$type, $policy, $assignment, $account];
}

function leaveLifecycleSchedule(Company $company, Employee $employee): void
{
    $schedule = WorkSchedule::factory()->create(['company_id' => $company->getKey()]);
    $shift = Shift::factory()->create(['company_id' => $company->getKey()]);
    $workingDays = [
        DayOfWeek::Monday->value,
        DayOfWeek::Tuesday->value,
        DayOfWeek::Wednesday->value,
        DayOfWeek::Thursday->value,
        DayOfWeek::Friday->value,
    ];
    $schedule->days()->whereIn('day_of_week', $workingDays)->update([
        'day_type' => WorkScheduleDayType::Working->value,
        'shift_id' => $shift->getKey(),
    ]);
    $company->employeeWorkScheduleAssignments()->create([
        'employee_id' => $employee->getKey(),
        'work_schedule_id' => $schedule->getKey(),
        'effective_from' => '2026-01-01',
        'effective_to' => '2026-12-31',
        'status' => 'active',
    ]);
}

function leaveLifecyclePendingRequest(Company $company, Employee $employee, LeaveType $type, LeavePolicy $policy, EmployeeLeavePolicyAssignment $assignment, EmployeeLeaveBalanceAccount $account, User $requester): LeaveRequest
{
    $request = LeaveRequest::factory()->create([
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
        'requested_by' => $requester->getKey(),
        'requested_at' => now('UTC'),
    ]);
    $request->days()->create([
        'company_id' => $company->getKey(),
        'employee_id' => $employee->getKey(),
        'leave_date' => '2026-10-12',
        'day_type' => LeaveRequestDayType::FullDay,
        'requested_amount' => '1.00',
    ]);

    return $request;
}

beforeEach(function (): void {
    config()->set('inertia.testing.page_paths', [resource_path('js/pages')]);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 08:00:00', 'Asia/Kolkata'));
});

test('Employee submission notifies only current in-scope reviewers with decision permission', function () {
    [$company, $employeeUser, $employee] = leaveLifecycleEmployee();
    [$type] = leaveLifecycleConfiguration($company, $employee);
    leaveLifecycleSchedule($company, $employee);

    $hr = leaveLifecycleMember($company, 'hr_admin');
    $teamManager = leaveLifecycleMember($company, 'manager');
    $managerEmployee = Employee::factory()->create(['company_id' => $company->getKey(), 'user_id' => $teamManager->getKey()]);
    $employee->update(['manager_id' => $managerEmployee->getKey()]);
    $unrelatedManager = leaveLifecycleMember($company, 'manager');
    Employee::factory()->create(['company_id' => $company->getKey(), 'user_id' => $unrelatedManager->getKey()]);
    $readOnlyManager = leaveLifecycleMember($company, 'manager');
    $readOnlyManagerEmployee = Employee::factory()->create(['company_id' => $company->getKey(), 'user_id' => $readOnlyManager->getKey()]);
    $employee->update(['manager_id' => $managerEmployee->getKey()]);
    $accountant = leaveLifecycleMember($company, 'accountant');

    $registrar = app(PermissionRegistrar::class);
    $previousTeamId = $registrar->getPermissionsTeamId();
    $registrar->setPermissionsTeamId($company->getKey());
    $teamManager->givePermissionTo('leave_request.approve');
    $unrelatedManager->givePermissionTo('leave_request.approve');
    $accountant->givePermissionTo('leave_request.view');
    $registrar->setPermissionsTeamId($previousTeamId);

    $request = app(CreateLeaveRequest::class)->handle(
        $employee,
        $type,
        '2026-10-12',
        '2026-10-12',
        'full_day',
        'Personal appointment',
        null,
        $employeeUser,
    );

    $notificationFor = fn (User $user) => $user->notifications()->where('company_id', $company->getKey())->where('type', LeaveRequestSubmitted::class);
    expect($notificationFor($hr)->count())->toBe(1)
        ->and($notificationFor($teamManager)->count())->toBe(1)
        ->and($notificationFor($unrelatedManager)->count())->toBe(0)
        ->and($notificationFor($readOnlyManager)->count())->toBe(0)
        ->and($notificationFor($accountant)->count())->toBe(0)
        ->and($employeeUser->notifications()->count())->toBe(0);

    $notification = $notificationFor($hr)->firstOrFail();
    expect((int) $notification->company_id)->toBe((int) $company->getKey())
        ->and($notification->data)->toBe([
            'company_id' => (int) $company->getKey(),
            'leave_request_id' => (int) $request->getKey(),
        ]);

    $this->actingAs($hr)->withSession(['active_company_id' => $company->getKey()])
        ->get(route('notifications.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('notificationCenter.unread_count', 1)
            ->where('notificationCenter.notifications.0.category', 'leave')
            ->where('notificationCenter.notifications.0.title', 'Leave request submitted')
            ->where('notificationCenter.notifications.0.summary', 'A leave request is awaiting your review.')
            ->where('notificationCenter.notifications.0.destination_url', route('leave.approvals.show', $request->getKey()))
            ->missing('notificationCenter.notifications.0.leave_request_id')
            ->missing('notificationCenter.notifications.0.company_id')
            ->missing('notificationCenter.notifications.0.review_note'));

    $this->post(route('notifications.read', $notification->getKey()))->assertRedirect();
    expect($notification->fresh()->read_at)->not->toBeNull();

    $unread = $hr->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => LeaveRequestSubmitted::class,
        'company_id' => $company->getKey(),
        'data' => ['company_id' => $company->getKey(), 'leave_request_id' => $request->getKey()],
    ]);
    $this->post(route('notifications.read-all'))->assertRedirect();
    expect($unread->fresh()->read_at)->not->toBeNull();

    $inaccessibleUnread = $hr->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => LeaveRequestSubmitted::class,
        'company_id' => $company->getKey(),
        'data' => ['company_id' => $company->getKey(), 'leave_request_id' => $request->getKey()],
    ]);
    $registrar->setPermissionsTeamId($company->getKey());
    Role::query()->where('team_id', $company->getKey())->where('name', 'hr_admin')->firstOrFail()->revokePermissionTo('leave_request.view');
    $registrar->setPermissionsTeamId($previousTeamId);

    $this->get(route('notifications.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('notificationCenter.unread_count', 0)
            ->has('notificationCenter.notifications', 0));
    $this->post(route('notifications.read-all'))->assertRedirect();
    expect($inaccessibleUnread->fresh()->read_at)->toBeNull();
});

test('submission with no authorized reviewer remains committed without an authorization bypass', function () {
    [$company, $employeeUser, $employee] = leaveLifecycleEmployee();
    [$type] = leaveLifecycleConfiguration($company, $employee);
    leaveLifecycleSchedule($company, $employee);

    $request = app(CreateLeaveRequest::class)->handle($employee, $type, '2026-10-12', '2026-10-12', 'full_day', null, null, $employeeUser);

    expect($request->fresh()->status)->toBe(LeaveRequestStatus::Pending)
        ->and(LeaveRequest::query()->whereKey($request->getKey())->exists())->toBeTrue()
        ->and($employeeUser->notifications()->count())->toBe(0);
});

test('approval and rejection notify only the linked Employee with a minimal payload', function () {
    $company = Company::factory()->create();
    app(SeedCompanyRoles::class)->handle($company);
    $reviewer = leaveLifecycleMember($company, 'hr_admin');

    [$approvedCompany, $approvedUser, $approvedEmployee] = leaveLifecycleEmployee($company);
    [$approvedType, $approvedPolicy, $approvedAssignment, $approvedAccount] = leaveLifecycleConfiguration($company, $approvedEmployee);
    $approved = leaveLifecyclePendingRequest($company, $approvedEmployee, $approvedType, $approvedPolicy, $approvedAssignment, $approvedAccount, $approvedUser);
    app(ApproveLeaveRequest::class)->handle($approved, $reviewer, $company, 'Confidential internal review note');

    [$rejectedCompany, $rejectedUser, $rejectedEmployee] = leaveLifecycleEmployee($company);
    [$rejectedType, $rejectedPolicy, $rejectedAssignment, $rejectedAccount] = leaveLifecycleConfiguration($company, $rejectedEmployee);
    $rejected = leaveLifecyclePendingRequest($company, $rejectedEmployee, $rejectedType, $rejectedPolicy, $rejectedAssignment, $rejectedAccount, $rejectedUser);
    app(RejectLeaveRequest::class)->handle($rejected, $reviewer, $company, 'Confidential rejection note');

    $approvedNotification = $approvedUser->notifications()->where('type', LeaveRequestApproved::class)->firstOrFail();
    $rejectedNotification = $rejectedUser->notifications()->where('type', LeaveRequestRejected::class)->firstOrFail();
    expect($approvedNotification->data)->toBe(['company_id' => $company->getKey(), 'leave_request_id' => $approved->getKey()])
        ->and($rejectedNotification->data)->toBe(['company_id' => $company->getKey(), 'leave_request_id' => $rejected->getKey()])
        ->and($approvedUser->notifications()->count())->toBe(1)
        ->and($rejectedUser->notifications()->count())->toBe(1)
        ->and($reviewer->notifications()->count())->toBe(0);

    $this->actingAs($approvedUser)->withSession(['active_company_id' => $company->getKey()])
        ->get(route('employee.notifications.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('notificationCenter.notifications.0.title', 'Leave request approved')
            ->where('notificationCenter.notifications.0.destination_url', route('employee.leave.requests.show', $approved->getKey()))
            ->missing('notificationCenter.notifications.0.review_note')
            ->where('auth.user', null));
});

test('Employee withdrawal notifies scoped reviewers once and stale retries do not duplicate it', function () {
    [$company, $employeeUser, $employee] = leaveLifecycleEmployee();
    [$type, $policy, $assignment, $account] = leaveLifecycleConfiguration($company, $employee);
    $reviewer = leaveLifecycleMember($company, 'hr_admin');
    $request = leaveLifecyclePendingRequest($company, $employee, $type, $policy, $assignment, $account, $employeeUser);

    app(CancelLeaveRequest::class)->handle($request, $employeeUser, 'Plans changed');
    expect($request->fresh()->status)->toBe(LeaveRequestStatus::Cancelled)
        ->and($reviewer->notifications()->where('type', LeaveRequestWithdrawn::class)->where('company_id', $company->getKey())->count())->toBe(1)
        ->and(LeaveLedgerEntry::query()->where('company_id', $company->getKey())->where('transaction_type', LeaveLedgerTransactionType::LeaveUsed->value)->count())->toBe(0);

    expect(fn () => app(CancelLeaveRequest::class)->handle($request, $employeeUser, 'Plans changed again'))
        ->toThrow(ValidationException::class);
    expect($reviewer->notifications()->where('type', LeaveRequestWithdrawn::class)->count())->toBe(1);
});

test('synchronous Leave notifications roll back with the canonical Leave transaction', function () {
    [$company, $employeeUser, $employee] = leaveLifecycleEmployee();
    [$type] = leaveLifecycleConfiguration($company, $employee);
    leaveLifecycleSchedule($company, $employee);
    $reviewer = leaveLifecycleMember($company, 'hr_admin');

    try {
        DB::transaction(function () use ($employee, $type, $employeeUser): void {
            app(CreateLeaveRequest::class)->handle($employee, $type, '2026-10-12', '2026-10-12', 'full_day', null, null, $employeeUser);
            throw new RuntimeException('Force the outer Leave transaction to roll back.');
        });
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Force the outer Leave transaction to roll back.');
    }

    expect(LeaveRequest::query()->where('company_id', $company->getKey())->count())->toBe(0)
        ->and($reviewer->notifications()->where('company_id', $company->getKey())->count())->toBe(0);
});

test('same User receives only the active Company Leave notifications after switching Company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $employeeUser = User::factory()->create();

    foreach ([$companyA, $companyB] as $company) {
        CompanyUser::query()->create(['company_id' => $company->getKey(), 'user_id' => $employeeUser->getKey(), 'status' => 'active']);
        app(SeedCompanyRoles::class)->handle($company);
        leaveLifecycleAssignRole($employeeUser, $company, 'employee');
    }

    $employeeA = Employee::factory()->create(['company_id' => $companyA->getKey(), 'user_id' => $employeeUser->getKey()]);
    $employeeB = Employee::factory()->create(['company_id' => $companyB->getKey(), 'user_id' => $employeeUser->getKey()]);
    [$typeA, $policyA, $assignmentA, $accountA] = leaveLifecycleConfiguration($companyA, $employeeA);
    [$typeB, $policyB, $assignmentB, $accountB] = leaveLifecycleConfiguration($companyB, $employeeB);
    $requestA = leaveLifecyclePendingRequest($companyA, $employeeA, $typeA, $policyA, $assignmentA, $accountA, $employeeUser);
    $requestB = leaveLifecyclePendingRequest($companyB, $employeeB, $typeB, $policyB, $assignmentB, $accountB, $employeeUser);
    $reviewerA = leaveLifecycleMember($companyA, 'hr_admin');
    $reviewerB = leaveLifecycleMember($companyB, 'hr_admin');

    app(ApproveLeaveRequest::class)->handle($requestA, $reviewerA, $companyA);
    app(ApproveLeaveRequest::class)->handle($requestB, $reviewerB, $companyB);

    $this->actingAs($employeeUser)->withSession(['active_company_id' => $companyA->getKey()])
        ->get(route('employee.notifications.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('notificationCenter.unread_count', 1)
            ->has('notificationCenter.notifications', 1)
            ->where('notificationCenter.notifications.0.destination_url', route('employee.leave.requests.show', $requestA->getKey())));

    $this->withSession(['active_company_id' => $companyB->getKey()])
        ->get(route('employee.notifications.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('notificationCenter.unread_count', 1)
            ->has('notificationCenter.notifications', 1)
            ->where('notificationCenter.notifications.0.destination_url', route('employee.leave.requests.show', $requestB->getKey())));
});

test('current Manager reviewer scope hides forged coworker Leave notifications and read actions', function () {
    $company = Company::factory()->create();
    $manager = leaveLifecycleMember($company, 'manager');
    $registrar = app(PermissionRegistrar::class);
    $previousTeamId = $registrar->getPermissionsTeamId();
    $registrar->setPermissionsTeamId($company->getKey());
    $manager->givePermissionTo('leave_request.approve');
    $registrar->setPermissionsTeamId($previousTeamId);

    $managerEmployee = Employee::factory()->create(['company_id' => $company->getKey(), 'user_id' => $manager->getKey()]);
    $directEmployee = Employee::factory()->create(['company_id' => $company->getKey(), 'manager_id' => $managerEmployee->getKey()]);
    $unrelatedEmployee = Employee::factory()->create(['company_id' => $company->getKey()]);
    [$directType, $directPolicy, $directAssignment, $directAccount] = leaveLifecycleConfiguration($company, $directEmployee);
    [$otherType, $otherPolicy, $otherAssignment, $otherAccount] = leaveLifecycleConfiguration($company, $unrelatedEmployee);
    $directRequest = leaveLifecyclePendingRequest($company, $directEmployee, $directType, $directPolicy, $directAssignment, $directAccount, User::factory()->create());
    $unrelatedRequest = leaveLifecyclePendingRequest($company, $unrelatedEmployee, $otherType, $otherPolicy, $otherAssignment, $otherAccount, User::factory()->create());

    $manager->notify(new LeaveRequestSubmitted((int) $company->getKey(), (int) $directRequest->getKey()));
    $manager->notify(new LeaveRequestSubmitted((int) $company->getKey(), (int) $unrelatedRequest->getKey()));
    $notifications = $manager->notifications()->where('type', LeaveRequestSubmitted::class)->orderBy('id')->get();
    $directNotification = $notifications->firstWhere('data.leave_request_id', $directRequest->getKey());
    $unrelatedNotification = $notifications->firstWhere('data.leave_request_id', $unrelatedRequest->getKey());

    $this->actingAs($manager)->withSession(['active_company_id' => $company->getKey()])
        ->get(route('notifications.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('notificationCenter.unread_count', 1)
            ->has('notificationCenter.notifications', 1)
            ->where('notificationCenter.notifications.0.destination_url', route('leave.approvals.show', $directRequest->getKey())));

    $this->post(route('notifications.read', $unrelatedNotification->getKey()))->assertNotFound();
    expect($directNotification->fresh()->read_at)->toBeNull()
        ->and($unrelatedNotification->fresh()->read_at)->toBeNull();

    $directEmployee->update(['manager_id' => null]);
    $this->get(route('notifications.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('notificationCenter.unread_count', 0)
            ->has('notificationCenter.notifications', 0));
});
