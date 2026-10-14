<?php

use App\Actions\SeedCompanyRoles;
use App\Enums\LeaveRequestDayType;
use App\Enums\LeaveRequestStatus;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeLeaveBalanceAccount;
use App\Models\EmployeeLeavePolicyAssignment;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** @return array{Company, User} */
function teamCalendarActor(string $role = 'hr_admin', ?Company $company = null, ?User $user = null): array
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

/** @return array{Employee, LeaveType, LeaveRequest} */
function teamCalendarRequest(Company $company, array $employeeAttributes = [], array $requestAttributes = [], array $days = []): array
{
    $employee = Employee::factory()->create(array_merge(['company_id' => $company->id], $employeeAttributes));
    $type = LeaveType::factory()->create(['company_id' => $company->id, 'name' => $requestAttributes['type_name'] ?? 'Annual Leave '.$employee->getKey()]);
    $policy = LeavePolicy::factory()->create(['company_id' => $company->id, 'leave_type_id' => $type->id]);
    $assignment = EmployeeLeavePolicyAssignment::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'leave_policy_id' => $policy->id,
        'effective_from' => '2026-01-01',
        'effective_to' => '2026-12-31',
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
    $requester = User::factory()->create();
    $status = $requestAttributes['status'] ?? LeaveRequestStatus::Pending;
    $reviewer = User::factory()->create();
    $factory = LeaveRequest::factory();
    if ($status === LeaveRequestStatus::Approved) {
        $factory = $factory->approved($reviewer);
    } elseif ($status === LeaveRequestStatus::Rejected) {
        $factory = $factory->rejected($reviewer);
    } elseif ($status === LeaveRequestStatus::Cancelled) {
        $factory = $factory->cancelled();
    }
    $attributes = $requestAttributes;
    unset($attributes['type_name'], $attributes['status']);
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

    foreach ($days ?: [['2026-10-12', LeaveRequestDayType::FullDay, '1.00']] as [$date, $dayType, $amount]) {
        if ($request->days()->whereDate('leave_date', $date)->exists()) {
            continue;
        }

        $request->days()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'leave_date' => $date,
            'day_type' => $dayType,
            'requested_amount' => $amount,
        ]);
    }

    return [$employee, $type, $request->refresh()];
}

beforeEach(function (): void {
    config()->set('inertia.testing.page_paths', [resource_path('js/pages')]);
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00:00', 'Asia/Kolkata'));
});

test('HR team calendar groups authorized approved and pending saved days and paginates selected-day events', function () {
    [$company, $hr] = teamCalendarActor();
    [$employeeA, $type] = teamCalendarRequest($company, ['first_name' => 'Asha', 'last_name' => 'Sen', 'employee_code' => 'E-01'], [
        'status' => LeaveRequestStatus::Approved,
    ], [['2026-10-12', LeaveRequestDayType::FullDay, '1.00'], ['2026-10-13', LeaveRequestDayType::HalfDay, '0.50']]);
    [$employeeB, , $pending] = teamCalendarRequest($company, ['first_name' => 'Mira', 'last_name' => 'Das', 'employee_code' => 'E-02'], [
        'status' => LeaveRequestStatus::Pending,
    ], [['2026-10-12', LeaveRequestDayType::HalfDay, '0.50']]);
    teamCalendarRequest($company, [], ['status' => LeaveRequestStatus::Rejected]);
    teamCalendarRequest($company, [], ['status' => LeaveRequestStatus::Cancelled]);

    $this->actingAs($hr)->withSession(['active_company_id' => $company->id])
        ->get(route('leave.calendar.index', ['month' => '2026-10', 'date' => '2026-10-12']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('leave-calendar/index', false)
            ->where('month', '2026-10')
            ->where('monthLabel', 'October 2026')
            ->where('timezone', 'Asia/Kolkata')
            ->where('approvedEvents', 2)
            ->where('pendingEvents', 1)
            ->where('selectedDay.total', 2)
            ->where('selectedDay.data.0.employeeName', 'Asha Sen')
            ->where('selectedDay.data.0.leaveType', $type->name)
            ->where('selectedDay.data.0.amount', '1.00')
            ->where('selectedDay.data.0.dayType', 'Full day')
            ->where('selectedDay.data.0.href', route('leave.approvals.show', $employeeA->leaveRequests()->first()->id))
            ->where('selectedDay.data.1.employeeName', 'Mira Das')
            ->where('selectedDay.data.1.status', 'pending')
            ->where('days.11.approvedCount', 1)
            ->where('days.11.pendingCount', 1)
            ->missing('selectedDay.data.0.employeeId')
            ->missing('selectedDay.data.0.companyId')
            ->missing('selectedDay.data.0.ledgerId'));
});

test('selected day event detail is bounded and exposes pagination for busy dates', function () {
    [$company, $hr] = teamCalendarActor();
    foreach (range(1, 21) as $index) {
        teamCalendarRequest($company, [
            'first_name' => 'Employee',
            'last_name' => (string) $index,
            'employee_code' => 'EMP-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
        ]);
    }

    $this->actingAs($hr)->withSession(['active_company_id' => $company->id])
        ->get(route('leave.calendar.index', ['month' => '2026-10', 'date' => '2026-10-12']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('selectedDay.total', 21)
            ->where('selectedDay.per_page', 20)
            ->where('selectedDay.data', fn ($data): bool => $data->count() === 20)
            ->where('selectedDay.last_page', 2)
            ->where('selectedDay.next_page_url', fn (string $url): bool => str_contains($url, 'page=2') && str_contains($url, 'month=2026-10')));
});

test('manager counts, employee search, and selected-day events stay within reviewer scope', function () {
    [$company, $manager] = teamCalendarActor('manager');
    $managerEmployee = Employee::factory()->create(['company_id' => $company->id, 'user_id' => $manager->id, 'employee_code' => 'M-01']);
    teamCalendarRequest($company, ['manager_id' => $managerEmployee->id, 'first_name' => 'Direct', 'last_name' => 'Report', 'employee_code' => 'D-01']);
    teamCalendarRequest($company, ['first_name' => 'Hidden', 'employee_code' => 'H-01']);

    $this->actingAs($manager)->withSession(['active_company_id' => $company->id])
        ->get(route('leave.calendar.index', ['month' => '2026-10', 'date' => '2026-10-12']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('pendingEvents', 1)
            ->where('selectedDay.total', 1)
            ->where('selectedDay.data.0.employeeName', 'Direct Report')
            ->where('canFilterDepartments', false)
            ->where('departments', []));

    $this->get(route('leave.calendar.index', ['month' => '2026-10', 'date' => '2026-10-12', 'search' => 'H-01']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('approvedEvents', 0)
            ->where('pendingEvents', 0)
            ->where('selectedDay.total', 0)
            ->where('canFilterDepartments', false)
            ->where('departments', [])
            ->missing('selectedDay.data.0.employeeName'));

    $this->get(route('leave.calendar.index', ['month' => '2026-10', 'date' => '2026-10-12', 'department_id' => 1]))
        ->assertSessionHasErrors('department_id');
});

test('HR filters by company department, leave type, status, and case-insensitive employee name', function () {
    [$company, $hr] = teamCalendarActor();
    $engineering = Department::factory()->create(['company_id' => $company->id, 'name' => 'Engineering']);
    $sales = Department::factory()->create(['company_id' => $company->id, 'name' => 'Sales']);
    [$employee, $type] = teamCalendarRequest($company, [
        'department_id' => $engineering->id,
        'first_name' => 'Asha',
        'last_name' => 'Rao',
        'employee_code' => 'ENG-1',
    ], ['status' => LeaveRequestStatus::Approved]);
    teamCalendarRequest($company, ['department_id' => $sales->id, 'first_name' => 'Another']);

    $this->actingAs($hr)->withSession(['active_company_id' => $company->id])
        ->get(route('leave.calendar.index', [
            'month' => '2026-10', 'date' => '2026-10-12', 'search' => 'asha',
            'leave_type_id' => $type->id, 'status' => 'approved', 'department_id' => $engineering->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('approvedEvents', 1)
            ->where('pendingEvents', 0)
            ->where('selectedDay.total', 1)
            ->where('selectedDay.data.0.employeeName', 'Asha Rao')
            ->where('filters.department_id', (string) $engineering->id)
            ->where('canFilterDepartments', true));
});

test('calendar uses the active Company and rejects malformed months and out-of-month dates', function () {
    [$companyA, $reviewer] = teamCalendarActor();
    [$companyB] = teamCalendarActor('hr_admin', null, $reviewer);
    teamCalendarRequest($companyA, ['first_name' => 'Company', 'last_name' => 'A']);
    teamCalendarRequest($companyB, ['first_name' => 'Company', 'last_name' => 'B']);

    $this->actingAs($reviewer)->withSession(['active_company_id' => $companyA->id])
        ->get(route('leave.calendar.index', ['month' => '2026-10', 'date' => '2026-10-12', 'company_id' => $companyB->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('selectedDay.data.0.employeeName', 'Company A'));

    $this->get(route('leave.calendar.index', ['month' => '2026-13']))->assertSessionHasErrors('month');
    $this->get(route('leave.calendar.index', ['month' => str_repeat('9', 1000)]))->assertSessionHasErrors('month');
    $this->get(route('leave.calendar.index', ['month' => '2026-10', 'date' => '2026-11-01']))->assertSessionHasErrors('date');

    $this->withSession(['active_company_id' => $companyB->id])
        ->get(route('leave.calendar.index', ['month' => '2026-10', 'date' => '2026-10-12']))
        ->assertInertia(fn (Assert $page) => $page->where('selectedDay.data.0.employeeName', 'Company B'));
});

test('calendar GET is read-only and unauthorized users cannot enter the reviewer workspace', function () {
    [$company, $hr] = teamCalendarActor();
    [$employee, $type, $request] = teamCalendarRequest($company);
    $before = [
        'requests' => LeaveRequest::query()->count(),
        'days' => DB::table('leave_request_days')->count(),
        'balances' => EmployeeLeaveBalanceAccount::query()->count(),
        'ledger' => DB::table('leave_ledger_entries')->count(),
        'attendance' => DB::table('daily_attendance_records')->count(),
    ];

    $this->actingAs($hr)->withSession(['active_company_id' => $company->id])
        ->get(route('leave.calendar.index'))
        ->assertOk();
    expect([
        'requests' => LeaveRequest::query()->count(),
        'days' => DB::table('leave_request_days')->count(),
        'balances' => EmployeeLeaveBalanceAccount::query()->count(),
        'ledger' => DB::table('leave_ledger_entries')->count(),
        'attendance' => DB::table('daily_attendance_records')->count(),
    ])->toBe($before);

    [$employeeCompany, $employeeUser] = teamCalendarActor('employee');
    Employee::factory()->create(['company_id' => $employeeCompany->id, 'user_id' => $employeeUser->id]);
    $this->actingAs($employeeUser)->withSession(['active_company_id' => $employeeCompany->id])
        ->get(route('leave.calendar.index'))
        ->assertForbidden();

    [$foreignCompany, $inactiveHr] = teamCalendarActor();
    CompanyUser::query()->where('company_id', $foreignCompany->id)->where('user_id', $inactiveHr->id)->update(['status' => 'inactive']);
    $this->actingAs($inactiveHr)->withSession(['active_company_id' => $foreignCompany->id])
        ->get(route('leave.calendar.index'))
        ->assertForbidden();

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($foreignCompany->id);
    Role::query()->where('team_id', $foreignCompany->id)->where('name', 'hr_admin')->firstOrFail()->revokePermissionTo('leave_request.view');
    $registrar->setPermissionsTeamId(null);
    CompanyUser::query()->where('company_id', $foreignCompany->id)->where('user_id', $inactiveHr->id)->update(['status' => 'active']);
    $this->withSession(['active_company_id' => $foreignCompany->id])
        ->get(route('leave.calendar.index'))
        ->assertForbidden();
});

test('manager cannot see a same-company coworker or foreign-company event by changing filters', function () {
    [$company, $manager] = teamCalendarActor('manager');
    $managerEmployee = Employee::factory()->create(['company_id' => $company->id, 'user_id' => $manager->id]);
    [, , $ownRequest] = teamCalendarRequest($company, ['manager_id' => $managerEmployee->id, 'employee_code' => 'DIRECT']);
    [, , $otherRequest] = teamCalendarRequest($company, ['employee_code' => 'COWORKER']);
    [$foreignCompany] = teamCalendarActor();
    [, , $foreignRequest] = teamCalendarRequest($foreignCompany, ['employee_code' => 'FOREIGN']);

    $this->actingAs($manager)->withSession(['active_company_id' => $company->id])
        ->get(route('leave.calendar.index', [
            'month' => '2026-10',
            'date' => '2026-10-12',
            'search' => 'COWORKER',
            'employee_id' => $ownRequest->employee_id,
            'company_id' => $foreignCompany->id,
        ]))
        ->assertInertia(fn (Assert $page) => $page->where('selectedDay.total', 0)->missing('selectedDay.data.0.employeeName'));

    expect($ownRequest->company_id)->toBe($company->id)
        ->and($otherRequest->company_id)->toBe($company->id)
        ->and($foreignRequest->company_id)->toBe($foreignCompany->id);
});
