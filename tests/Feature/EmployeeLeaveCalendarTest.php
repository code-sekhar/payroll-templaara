<?php

use App\Actions\SeedCompanyRoles;
use App\Enums\LeaveRequestDayType;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\EmployeeHolidayCalendarAssignment;
use App\Models\EmployeeLeaveBalanceAccount;
use App\Models\EmployeeLeavePolicyAssignment;
use App\Models\EmployeeWorkScheduleAssignment;
use App\Models\Holiday;
use App\Models\HolidayCalendar;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** @return array{Company, User, Employee} */
function employeeLeaveCalendarIdentity(?Company $company = null, ?User $user = null): array
{
    $company ??= Company::factory()->create(['timezone' => 'Asia/Kolkata']);
    $user ??= User::factory()->create();
    CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $user->id, 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($company);

    $registrar = app(PermissionRegistrar::class);
    $previousTeamId = $registrar->getPermissionsTeamId();
    $registrar->setPermissionsTeamId($company->id);
    $user->assignRole('employee');
    $registrar->setPermissionsTeamId($previousTeamId);

    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'user_id' => $user->id,
        'first_name' => 'Nila',
        'last_name' => 'Calendar',
    ]);

    return [$company, $user, $employee];
}

/** @return array{LeaveType, LeavePolicy, EmployeeLeavePolicyAssignment, EmployeeLeaveBalanceAccount} */
function employeeLeaveCalendarConfiguration(Employee $employee, Company $company, array $typeAttributes = []): array
{
    $type = LeaveType::factory()->create(array_merge(['company_id' => $company->id, 'name' => 'Annual Leave'], $typeAttributes));
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

function employeeLeaveCalendarRequest(Employee $employee, Company $company, LeaveType $type, LeavePolicy $policy, EmployeeLeavePolicyAssignment $assignment, EmployeeLeaveBalanceAccount $account, array $attributes = []): LeaveRequest
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
    ], $attributes));
}

function employeeLeaveCalendarDay(LeaveRequest $request, Company $company, Employee $employee, string $date, string $amount = '1.00', LeaveRequestDayType $type = LeaveRequestDayType::FullDay): void
{
    $request->days()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_date' => $date,
        'day_type' => $type,
        'requested_amount' => $amount,
    ]);
}

beforeEach(function (): void {
    config()->set('inertia.testing.page_paths', [resource_path('js/pages')]);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 08:00:00', 'Asia/Kolkata'));
});

test('employee calendar shows only own pending and approved persisted day snapshots for the selected month', function () {
    [$company, $user, $employee] = employeeLeaveCalendarIdentity();
    [$type, $policy, $assignment, $account] = employeeLeaveCalendarConfiguration($employee, $company);
    $type->delete();

    $pending = employeeLeaveCalendarRequest($employee, $company, $type, $policy, $assignment, $account, [
        'start_date' => '2026-10-30', 'end_date' => '2026-11-02', 'requested_days' => '1.50',
    ]);
    employeeLeaveCalendarDay($pending, $company, $employee, '2026-10-30', '0.50', LeaveRequestDayType::HalfDay);
    employeeLeaveCalendarDay($pending, $company, $employee, '2026-10-31', '1.00');
    employeeLeaveCalendarDay($pending, $company, $employee, '2026-11-02', '0.00', LeaveRequestDayType::Holiday);

    $approved = employeeLeaveCalendarRequest($employee, $company, $type, $policy, $assignment, $account, [
        'status' => 'approved', 'reviewed_by' => User::factory()->create()->id,
        'reviewed_at' => '2026-10-02 10:00:00+00', 'start_date' => '2026-10-20', 'end_date' => '2026-10-20',
    ]);
    employeeLeaveCalendarDay($approved, $company, $employee, '2026-10-20', '0.50', LeaveRequestDayType::HalfDay);

    $rejected = employeeLeaveCalendarRequest($employee, $company, $type, $policy, $assignment, $account, [
        'status' => 'rejected', 'reviewed_by' => User::factory()->create()->id,
        'reviewed_at' => '2026-10-02 10:00:00+00', 'start_date' => '2026-10-21', 'end_date' => '2026-10-21',
    ]);
    employeeLeaveCalendarDay($rejected, $company, $employee, '2026-10-21');

    $cancelled = employeeLeaveCalendarRequest($employee, $company, $type, $policy, $assignment, $account, [
        'status' => 'cancelled', 'cancelled_by' => $user->id, 'cancelled_at' => '2026-10-03 10:00:00+00',
        'cancellation_reason' => 'Plans changed', 'start_date' => '2026-10-22', 'end_date' => '2026-10-22',
    ]);
    employeeLeaveCalendarDay($cancelled, $company, $employee, '2026-10-22');

    $coworker = Employee::factory()->create(['company_id' => $company->id, 'first_name' => 'Hidden', 'last_name' => 'Coworker']);
    [$otherType, $otherPolicy, $otherAssignment, $otherAccount] = employeeLeaveCalendarConfiguration($coworker, $company, ['name' => 'Private Coworker Leave']);
    $otherRequest = employeeLeaveCalendarRequest($coworker, $company, $otherType, $otherPolicy, $otherAssignment, $otherAccount, ['start_date' => '2026-10-30', 'end_date' => '2026-10-30']);
    employeeLeaveCalendarDay($otherRequest, $company, $coworker, '2026-10-30');

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->get(route('employee.leave.calendar', ['month' => '2026-10', 'employee_id' => $coworker->id, 'company_id' => 999999]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('employee-portal/leave-calendar', false)
            ->where('calendar.month', '2026-10')
            ->where('calendar.days.29.date', '2026-10-30')
            ->has('calendar.days.29.leaveItems', 1)
            ->where('calendar.days.29.leaveItems.0.status', 'pending')
            ->where('calendar.days.29.leaveItems.0.amount', '0.50')
            ->where('calendar.days.29.leaveItems.0.dayType', 'Half day')
            ->where('calendar.days.29.leaveItems.0.leaveType', 'Annual Leave')
            ->where('calendar.days.29.leaveItems.0.href', route('employee.leave.requests.show', $pending->id))
            ->where('calendar.days.19.leaveItems.0.status', 'approved')
            ->where('calendar.days.30.date', '2026-10-31')
            ->has('calendar.days.30.leaveItems', 1)
            ->missing('calendar.days.30.leaveItems.0.employee_id')
            ->where('auth.user', null)
            ->where('companies', [])
            ->missing('calendar.days.29.leaveItems.0.company_id')
            ->missing('calendar.days.29.leaveItems.0.request_id')
            ->missing('calendar.days.29.leaveItems.0.reviewer_id')
            ->missing('calendar.otherEmployee')
            ->missing('calendar.days.29.leaveItems.1.review_note'));

    $this->get(route('employee.leave.calendar', ['month' => '2026-11']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('calendar.days.1.date', '2026-11-02')
            ->has('calendar.days.1.leaveItems', 1)
            ->where('calendar.days.1.leaveItems.0.href', route('employee.leave.requests.show', $pending->id)));
});

test('calendar defaults to company-local month and does not mutate leave or attendance data', function () {
    $company = Company::factory()->create(['timezone' => 'Asia/Kolkata']);
    [$company, $user, $employee] = employeeLeaveCalendarIdentity($company);
    [$type, $policy, $assignment, $account] = employeeLeaveCalendarConfiguration($employee, $company);
    $request = employeeLeaveCalendarRequest($employee, $company, $type, $policy, $assignment, $account);
    employeeLeaveCalendarDay($request, $company, $employee, '2026-10-20');
    $before = [
        LeaveRequest::query()->count(),
        $employee->leaveBalanceAccounts()->count(),
        $employee->leaveRequests()->count(),
        $employee->dailyAttendanceRecords()->count(),
    ];

    $this->travelTo(CarbonImmutable::parse('2026-09-30 19:00:00', 'UTC'));
    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->get(route('employee.leave.calendar', ['employee_id' => 999999, 'company_id' => 888888]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('calendar.month', '2026-10')
            ->where('calendar.today', '2026-10-01')
            ->where('calendar.previousMonth', '2026-09')
            ->where('calendar.nextMonth', '2026-11')
            ->where('calendar.timezone', 'Asia/Kolkata')
            ->where('employeeName', 'Nila Calendar')
            ->where('auth.user', null));

    expect([
        LeaveRequest::query()->count(),
        $employee->leaveBalanceAccounts()->count(),
        $employee->leaveRequests()->count(),
        $employee->dailyAttendanceRecords()->count(),
    ])->toBe($before);
});

test('calendar respects assigned company holiday and effective schedule weekly off without assuming weekends', function () {
    [$company, $user, $employee] = employeeLeaveCalendarIdentity();
    $calendar = HolidayCalendar::factory()->create(['company_id' => $company->id, 'year' => 2026]);
    EmployeeHolidayCalendarAssignment::factory()->create([
        'company_id' => $company->id, 'employee_id' => $employee->id, 'holiday_calendar_id' => $calendar->id,
        'effective_from' => '2026-01-01', 'effective_to' => null,
    ]);
    Holiday::factory()->create([
        'company_id' => $company->id, 'holiday_calendar_id' => $calendar->id, 'date' => '2026-10-05', 'name' => 'Founders Day',
    ]);
    [$type, $policy, $policyAssignment, $account] = employeeLeaveCalendarConfiguration($employee, $company);
    $request = employeeLeaveCalendarRequest($employee, $company, $type, $policy, $policyAssignment, $account, [
        'start_date' => '2026-10-05', 'end_date' => '2026-10-05',
    ]);
    employeeLeaveCalendarDay($request, $company, $employee, '2026-10-05', '1.00');
    $schedule = WorkSchedule::factory()->create(['company_id' => $company->id]);
    EmployeeWorkScheduleAssignment::factory()->create([
        'company_id' => $company->id, 'employee_id' => $employee->id, 'work_schedule_id' => $schedule->id,
        'effective_from' => '2026-10-01', 'effective_to' => '2026-10-15', 'status' => 'active',
    ]);

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->get(route('employee.leave.calendar', ['month' => '2026-10']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('calendar.days.4.holidayName', 'Founders Day')
            ->where('calendar.days.4.leaveItems.0.status', 'pending')
            ->where('calendar.days.4.weeklyOff', true)
            ->where('calendar.days.4.scheduleAvailable', true)
            ->where('calendar.days.15.scheduleAvailable', false)
            ->where('calendar.days.15.weeklyOff', false));
});

test('same user sees only the selected company employee calendar after company switching', function () {
    [$companyA, $user, $employeeA] = employeeLeaveCalendarIdentity();
    [$typeA, $policyA, $assignmentA, $accountA] = employeeLeaveCalendarConfiguration($employeeA, $companyA, ['name' => 'Company A Leave']);
    $requestA = employeeLeaveCalendarRequest($employeeA, $companyA, $typeA, $policyA, $assignmentA, $accountA);
    employeeLeaveCalendarDay($requestA, $companyA, $employeeA, '2026-10-20');

    $companyB = Company::factory()->create(['timezone' => 'Pacific/Auckland']);
    CompanyUser::query()->create(['company_id' => $companyB->id, 'user_id' => $user->id, 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($companyB);
    $registrar = app(PermissionRegistrar::class);
    $previousTeamId = $registrar->getPermissionsTeamId();
    $registrar->setPermissionsTeamId($companyB->id);
    $user->assignRole('employee');
    $registrar->setPermissionsTeamId($previousTeamId);
    $employeeB = Employee::factory()->create(['company_id' => $companyB->id, 'user_id' => $user->id, 'first_name' => 'Mina', 'last_name' => 'Other']);
    [$typeB, $policyB, $assignmentB, $accountB] = employeeLeaveCalendarConfiguration($employeeB, $companyB, ['name' => 'Company B Leave']);
    $requestB = employeeLeaveCalendarRequest($employeeB, $companyB, $typeB, $policyB, $assignmentB, $accountB);
    employeeLeaveCalendarDay($requestB, $companyB, $employeeB, '2026-10-21');

    $this->actingAs($user)->withSession(['active_company_id' => $companyA->id])
        ->get(route('employee.leave.calendar', ['month' => '2026-10']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('employeeName', $employeeA->full_name)
            ->where('calendar.days.19.leaveItems.0.leaveType', 'Company A Leave')
            ->missing('calendar.days.20.leaveItems.0'));

    $this->withSession(['active_company_id' => $companyB->id])
        ->get(route('employee.leave.calendar', ['month' => '2026-10']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('employeeName', $employeeB->full_name)
            ->where('calendar.timezone', 'Pacific/Auckland')
            ->where('calendar.days.20.leaveItems.0.leaveType', 'Company B Leave')
            ->missing('calendar.days.19.leaveItems.0'));
});

test('calendar validates month, supports leap February and excludes invalid status events', function () {
    [$company, $user, $employee] = employeeLeaveCalendarIdentity();
    [$type, $policy, $assignment, $account] = employeeLeaveCalendarConfiguration($employee, $company);
    $request = employeeLeaveCalendarRequest($employee, $company, $type, $policy, $assignment, $account, [
        'start_date' => '2024-02-29', 'end_date' => '2024-02-29',
    ]);
    employeeLeaveCalendarDay($request, $company, $employee, '2024-02-29', '0.50', LeaveRequestDayType::HalfDay);

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->get(route('employee.leave.calendar', ['month' => '2024-02']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('calendar.monthLabel', 'February 2024')
            ->has('calendar.days', 29)
            ->where('calendar.days.28.date', '2024-02-29')
            ->where('calendar.days.28.leaveItems.0.amount', '0.50'));

    $this->get(route('employee.leave.calendar', ['month' => '2026-13']))->assertSessionHasErrors('month');
    $this->get(route('employee.leave.calendar', ['month' => ['2026-10']]))->assertSessionHasErrors('month');
    $this->get(route('employee.leave.calendar', ['month' => str_repeat('9', 10000)]))->assertSessionHasErrors('month');
});

test('calendar requires employee identity and leave self-view permission', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create();
    CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $user->id, 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($company);

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->get(route('employee.leave.calendar'))
        ->assertForbidden();

    [$company, $employeeUser] = employeeLeaveCalendarIdentity($company);
    $this->app[PermissionRegistrar::class]->setPermissionsTeamId($company->id);
    Role::findByName('employee')->revokePermissionTo('leave_request.self_view');

    $this->actingAs($employeeUser)->withSession(['active_company_id' => $company->id])
        ->get(route('employee.leave.calendar'))
        ->assertForbidden();
});
