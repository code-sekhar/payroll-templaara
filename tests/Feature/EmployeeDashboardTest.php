<?php

use App\Actions\SeedCompanyRoles;
use App\Enums\LeaveLedgerTransactionType;
use App\Enums\LeaveRequestStatus;
use App\Enums\WorkScheduleDayType;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\DailyAttendanceRecord;
use App\Models\Employee;
use App\Models\EmployeeHolidayCalendarAssignment;
use App\Models\EmployeeLeaveBalanceAccount;
use App\Models\EmployeeLeavePolicyAssignment;
use App\Models\EmployeeWorkScheduleAssignment;
use App\Models\Holiday;
use App\Models\HolidayCalendar;
use App\Models\LeaveLedgerEntry;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleDay;
use Spatie\Permission\PermissionRegistrar;

/** @return array{Company, User, Employee} */
function employeeDashboardIdentity(?Company $company = null, array $employeeAttributes = []): array
{
    $company ??= Company::factory()->create(['timezone' => 'Asia/Kolkata']);
    $user = User::factory()->create();
    CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $user->id, 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($company);
    $registrar = app(PermissionRegistrar::class);
    $previousTeamId = $registrar->getPermissionsTeamId();
    $registrar->setPermissionsTeamId($company->id);
    $user->assignRole('employee');
    $registrar->setPermissionsTeamId($previousTeamId);

    $employee = Employee::factory()->create(array_merge([
        'company_id' => $company->id,
        'user_id' => $user->id,
        'first_name' => 'Rahul',
        'middle_name' => null,
        'last_name' => 'Das',
        'employee_code' => 'EMP-001',
    ], $employeeAttributes));

    return [$company, $user, $employee];
}

/** @return array{EmployeeLeaveBalanceAccount, LeaveRequest} */
function employeeDashboardLeaveFixture(Employee $employee, Company $company, string $typeName): array
{
    $leaveType = LeaveType::factory()->create(['company_id' => $company->id, 'name' => $typeName]);
    $policy = LeavePolicy::factory()->create(['company_id' => $company->id, 'leave_type_id' => $leaveType->id]);
    $assignment = EmployeeLeavePolicyAssignment::factory()->create([
        'company_id' => $company->id, 'employee_id' => $employee->id, 'leave_type_id' => $leaveType->id,
        'leave_policy_id' => $policy->id, 'effective_from' => '2026-01-01', 'effective_to' => null,
    ]);
    $account = EmployeeLeaveBalanceAccount::factory()->create([
        'company_id' => $company->id, 'employee_id' => $employee->id, 'leave_type_id' => $leaveType->id,
        'leave_policy_id' => $policy->id, 'employee_leave_policy_assignment_id' => $assignment->id,
        'period_start' => '2026-01-01', 'period_end' => '2026-12-31',
    ]);
    LeaveLedgerEntry::factory()->create([
        'leave_balance_account_id' => $account->id, 'transaction_type' => LeaveLedgerTransactionType::OpeningBalance,
        'amount' => '7.00', 'effective_date' => '2026-01-01',
    ]);
    $request = LeaveRequest::factory()->create([
        'company_id' => $company->id, 'employee_id' => $employee->id, 'leave_type_id' => $leaveType->id,
        'leave_policy_id' => $policy->id, 'employee_leave_policy_assignment_id' => $assignment->id,
        'leave_balance_account_id' => $account->id, 'requested_at' => now(),
    ]);

    return [$account, $request];
}

test('employee dashboard resolves only the linked employee and exposes safe identity props', function () {
    [$company, $user, $employee] = employeeDashboardIdentity(employeeAttributes: [
        'personal_email' => 'private@example.test',
        'address' => 'Private address',
        'notes' => 'Confidential HR note',
    ]);

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->get(route('employee.portal', ['employee_id' => 999999]))
        ->assertInertia(fn ($page) => $page
            ->component('employee-portal/index', false)
            ->where('employeePortal.employee.name', $employee->full_name)
            ->where('employeePortal.employee.employeeCode', 'EMP-001')
            ->where('employeePortal.punchState.state', 'not_started')
            ->where('employeePortal.punchState.canPunchIn', true)
            ->where('employeePortal.punchState.canPunchOut', false)
            ->where('employeePortal.punchState.timezone', 'Asia/Kolkata')
            ->missing('employeePortal.punchState.employee_id')
            ->missing('employeePortal.punchState.company_id')
            ->where('employeePortal.today.workStatus', 'No schedule assigned')
            ->where('employeePortal.today.attendance', null)
            ->where('employeePortal.recentAttendance', [])
            ->where('employeePortal.recentLeaveRequests', [])
            ->where('employeePortal.leaveBalances', [])
            ->missing('employeePortal.employee.personal_email')
            ->missing('employeePortal.employee.address')
            ->missing('employeePortal.employee.notes')
            ->where('auth.user', null));
});

test('employee dashboard scopes all records to the linked employee and active company', function () {
    [$companyA, $user, $employeeA] = employeeDashboardIdentity();
    $employeeCoworker = Employee::factory()->create(['company_id' => $companyA->id, 'first_name' => 'Coworker', 'last_name' => 'Person']);
    $companyB = Company::factory()->create(['timezone' => 'Asia/Kolkata']);
    $employeeB = Employee::factory()->create(['company_id' => $companyB->id, 'first_name' => 'Other', 'last_name' => 'Tenant']);
    employeeDashboardLeaveFixture($employeeCoworker, $companyA, 'Coworker Leave');
    employeeDashboardLeaveFixture($employeeB, $companyB, 'Other Tenant Leave');

    DailyAttendanceRecord::factory()->present()->create([
        'company_id' => $companyA->id, 'employee_id' => $employeeCoworker->id, 'attendance_date' => '2026-10-06',
    ]);
    DailyAttendanceRecord::factory()->present()->create([
        'company_id' => $companyB->id, 'employee_id' => $employeeB->id, 'attendance_date' => '2026-10-06',
    ]);

    $this->actingAs($user)->withSession(['active_company_id' => $companyA->id])->get(route('employee.portal'))
        ->assertInertia(fn ($page) => $page
            ->where('employeePortal.employee.name', $employeeA->full_name)
            ->where('employeePortal.recentAttendance', [])
            ->where('employeePortal.today.attendance', null)
            ->where('employeePortal.recentLeaveRequests', [])
            ->where('employeePortal.leaveBalances', [])
            ->missing('employeePortal.companyB'));
});

test('same user sees the linked employee dashboard for the active company only', function () {
    [$companyA, $user, $employeeA] = employeeDashboardIdentity();
    $companyB = Company::factory()->create(['timezone' => 'Asia/Kolkata']);
    CompanyUser::query()->create(['company_id' => $companyB->id, 'user_id' => $user->id, 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($companyB);
    $registrar = app(PermissionRegistrar::class);
    $previousTeamId = $registrar->getPermissionsTeamId();
    $registrar->setPermissionsTeamId($companyB->id);
    $user->assignRole('employee');
    $registrar->setPermissionsTeamId($previousTeamId);
    $employeeB = Employee::factory()->create([
        'company_id' => $companyB->id, 'user_id' => $user->id, 'first_name' => 'Mira', 'last_name' => 'Sen',
    ]);

    $this->actingAs($user)->withSession(['active_company_id' => $companyA->id])->get(route('employee.portal'))
        ->assertInertia(fn ($page) => $page->where('employeePortal.employee.name', $employeeA->full_name));

    $this->withSession(['active_company_id' => $companyB->id])->get(route('employee.portal'))
        ->assertInertia(fn ($page) => $page
            ->where('employeePortal.employee.name', $employeeB->full_name)
            ->where('employeePortal.companyName', $companyB->name));
});

test('employee dashboard resolves the existing schedule without creating attendance records', function () {
    Carbon\Carbon::setTestNow(Carbon\Carbon::parse('2026-10-06 09:00:00', 'Asia/Kolkata'));
    [$company, $user, $employee] = employeeDashboardIdentity();
    $schedule = WorkSchedule::factory()->create(['company_id' => $company->id]);
    $shift = $company->shifts()->create([
        'name' => 'Day Shift', 'code' => 'DAY-01', 'start_time' => '09:00', 'end_time' => '18:00',
        'break_duration_minutes' => 60, 'grace_period_minutes' => 0, 'is_overnight' => false, 'status' => 'active',
    ]);
    WorkScheduleDay::query()->where('work_schedule_id', $schedule->id)->where('day_of_week', 2)->update([
        'day_type' => WorkScheduleDayType::Working->value,
        'shift_id' => $shift->id,
    ]);
    EmployeeWorkScheduleAssignment::factory()->create([
        'company_id' => $company->id, 'employee_id' => $employee->id, 'work_schedule_id' => $schedule->id,
        'effective_from' => '2026-01-01', 'effective_to' => null,
    ]);

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])->get(route('employee.portal'))
        ->assertInertia(fn ($page) => $page
            ->where('employeePortal.today.date', '2026-10-06')
            ->where('employeePortal.today.workStatus', 'Working day')
            ->where('employeePortal.today.shift.name', 'Day Shift')
            ->where('employeePortal.today.shift.start', '09:00')
            ->where('employeePortal.today.attendance', null));

    $this->assertDatabaseCount('daily_attendance_records', 0);
    Carbon\Carbon::setTestNow();
});

test('employee dashboard shows a holiday only from the assigned holiday calendar', function () {
    Carbon\Carbon::setTestNow(Carbon\Carbon::parse('2026-10-06 09:00:00', 'Asia/Kolkata'));
    [$company, $user, $employee] = employeeDashboardIdentity();
    $calendar = HolidayCalendar::factory()->create(['company_id' => $company->id, 'year' => 2026]);
    EmployeeHolidayCalendarAssignment::factory()->create([
        'company_id' => $company->id, 'employee_id' => $employee->id, 'holiday_calendar_id' => $calendar->id,
        'effective_from' => '2026-01-01', 'effective_to' => null,
    ]);
    Holiday::factory()->create([
        'company_id' => $company->id, 'holiday_calendar_id' => $calendar->id,
        'date' => '2026-10-06', 'name' => 'Founders Day',
    ]);

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])->get(route('employee.portal'))
        ->assertInertia(fn ($page) => $page
            ->where('employeePortal.today.workStatus', 'No schedule assigned')
            ->where('employeePortal.today.holidayName', 'Founders Day'));

    $this->assertDatabaseCount('daily_attendance_records', 0);
    Carbon\Carbon::setTestNow();
});

test('employee dashboard shows approved leave from the existing approved leave resolver', function () {
    Carbon\Carbon::setTestNow(Carbon\Carbon::parse('2026-10-06 09:00:00', 'Asia/Kolkata'));
    [$company, $user, $employee] = employeeDashboardIdentity();
    $leaveType = LeaveType::factory()->create(['company_id' => $company->id, 'name' => 'Sick Leave']);
    $policy = LeavePolicy::factory()->create(['company_id' => $company->id, 'leave_type_id' => $leaveType->id]);
    $assignment = EmployeeLeavePolicyAssignment::factory()->create([
        'company_id' => $company->id, 'employee_id' => $employee->id, 'leave_type_id' => $leaveType->id,
        'leave_policy_id' => $policy->id, 'effective_from' => '2026-01-01', 'effective_to' => null,
    ]);
    $account = EmployeeLeaveBalanceAccount::factory()->create([
        'company_id' => $company->id, 'employee_id' => $employee->id, 'leave_type_id' => $leaveType->id,
        'leave_policy_id' => $policy->id, 'employee_leave_policy_assignment_id' => $assignment->id,
        'period_start' => '2026-01-01', 'period_end' => '2026-12-31',
    ]);
    $schedule = WorkSchedule::factory()->create(['company_id' => $company->id]);
    $shift = $company->shifts()->create([
        'name' => 'Day Shift', 'code' => 'DAY-02', 'start_time' => '09:00', 'end_time' => '18:00',
        'break_duration_minutes' => 60, 'grace_period_minutes' => 0, 'is_overnight' => false, 'status' => 'active',
    ]);
    WorkScheduleDay::query()->where('work_schedule_id', $schedule->id)->where('day_of_week', 2)->update([
        'day_type' => WorkScheduleDayType::Working->value, 'shift_id' => $shift->id,
    ]);
    EmployeeWorkScheduleAssignment::factory()->create([
        'company_id' => $company->id, 'employee_id' => $employee->id, 'work_schedule_id' => $schedule->id,
        'effective_from' => '2026-01-01', 'effective_to' => null,
    ]);
    LeaveRequest::factory()->approved()->create([
        'company_id' => $company->id, 'employee_id' => $employee->id, 'leave_type_id' => $leaveType->id,
        'leave_policy_id' => $policy->id, 'employee_leave_policy_assignment_id' => $assignment->id,
        'leave_balance_account_id' => $account->id, 'start_date' => '2026-10-06', 'end_date' => '2026-10-06',
        'requested_days' => '1.00',
    ]);

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])->get(route('employee.portal'))
        ->assertInertia(fn ($page) => $page
            ->where('employeePortal.today.workStatus', 'On approved leave')
            ->where('employeePortal.today.approvedLeaveType', 'Sick Leave'));

    $this->assertDatabaseCount('daily_attendance_records', 0);
    Carbon\Carbon::setTestNow();
});

test('employee dashboard shows materialized attendance and bounded recent leave and attendance data', function () {
    Carbon\Carbon::setTestNow(Carbon\Carbon::parse('2026-10-06 09:00:00', 'Asia/Kolkata'));
    [$company, $user, $employee] = employeeDashboardIdentity();
    $leaveType = LeaveType::factory()->create(['company_id' => $company->id, 'name' => 'Annual Leave']);
    $policy = LeavePolicy::factory()->create(['company_id' => $company->id, 'leave_type_id' => $leaveType->id]);
    $assignment = EmployeeLeavePolicyAssignment::factory()->create([
        'company_id' => $company->id, 'employee_id' => $employee->id, 'leave_type_id' => $leaveType->id,
        'leave_policy_id' => $policy->id, 'effective_from' => '2026-01-01', 'effective_to' => null,
    ]);
    $account = EmployeeLeaveBalanceAccount::factory()->create([
        'company_id' => $company->id, 'employee_id' => $employee->id, 'leave_type_id' => $leaveType->id,
        'leave_policy_id' => $policy->id, 'employee_leave_policy_assignment_id' => $assignment->id,
        'period_start' => '2026-01-01', 'period_end' => '2026-12-31',
    ]);
    LeaveLedgerEntry::factory()->create([
        'leave_balance_account_id' => $account->id,
        'transaction_type' => LeaveLedgerTransactionType::OpeningBalance,
        'amount' => '4.50', 'effective_date' => '2026-01-01',
    ]);
    $secondLeaveType = LeaveType::factory()->create(['company_id' => $company->id, 'name' => 'Personal Leave']);
    $secondPolicy = LeavePolicy::factory()->create(['company_id' => $company->id, 'leave_type_id' => $secondLeaveType->id]);
    $secondAssignment = EmployeeLeavePolicyAssignment::factory()->create([
        'company_id' => $company->id, 'employee_id' => $employee->id, 'leave_type_id' => $secondLeaveType->id,
        'leave_policy_id' => $secondPolicy->id, 'effective_from' => '2026-01-01', 'effective_to' => null,
    ]);
    $secondAccount = EmployeeLeaveBalanceAccount::factory()->create([
        'company_id' => $company->id, 'employee_id' => $employee->id, 'leave_type_id' => $secondLeaveType->id,
        'leave_policy_id' => $secondPolicy->id, 'employee_leave_policy_assignment_id' => $secondAssignment->id,
        'period_start' => '2026-01-01', 'period_end' => '2026-12-31',
    ]);
    LeaveLedgerEntry::factory()->create([
        'leave_balance_account_id' => $secondAccount->id,
        'transaction_type' => LeaveLedgerTransactionType::OpeningBalance,
        'amount' => '2.25', 'effective_date' => '2026-01-01',
    ]);
    foreach (range(1, 4) as $day) {
        LeaveRequest::factory()->create([
            'company_id' => $company->id, 'employee_id' => $employee->id, 'leave_type_id' => $leaveType->id,
            'leave_policy_id' => $policy->id, 'employee_leave_policy_assignment_id' => $assignment->id,
            'leave_balance_account_id' => $account->id, 'start_date' => sprintf('2026-11-%02d', $day),
            'end_date' => sprintf('2026-11-%02d', $day), 'requested_days' => '0.50', 'status' => LeaveRequestStatus::Pending,
            'requested_at' => Carbon\Carbon::parse("2026-10-0{$day} 10:00:00", 'Asia/Kolkata')->utc(),
        ]);
    }
    foreach (range(1, 6) as $day) {
        DailyAttendanceRecord::factory()->present()->create([
            'company_id' => $company->id, 'employee_id' => $employee->id,
            'attendance_date' => sprintf('2026-10-%02d', $day),
            'first_check_in_at' => Carbon\Carbon::parse(sprintf('2026-10-%02d 09:04:00', $day), 'Asia/Kolkata')->utc(),
            'last_check_out_at' => Carbon\Carbon::parse(sprintf('2026-10-%02d 18:02:00', $day), 'Asia/Kolkata')->utc(),
        ]);
    }

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])->get(route('employee.portal'))
        ->assertInertia(fn ($page) => $page
            ->where('employeePortal.today.attendance.status', 'Present')
            ->where('employeePortal.today.attendance.checkIn', '9:04 AM')
            ->where('employeePortal.today.attendance.checkOut', '6:02 PM')
            ->where('employeePortal.leaveBalances.0.leaveType', 'Annual Leave')
            ->where('employeePortal.leaveBalances.0.balance', '4.50')
            ->where('employeePortal.leaveBalances.1.leaveType', 'Personal Leave')
            ->where('employeePortal.leaveBalances.1.balance', '2.25')
            ->where('employeePortal.pendingLeaveRequestCount', 4)
            ->has('employeePortal.recentLeaveRequests', 3)
            ->where('employeePortal.recentLeaveRequests.0.requestedDays', '0.50')
            ->where('employeePortal.recentLeaveRequests.0.status', 'pending')
            ->has('employeePortal.recentAttendance', 5)
            ->where('employeePortal.recentAttendance.0.date', '2026-10-06'));

    expect($account->fresh()->ledgerEntries()->count())->toBe(1)
        ->and(LeaveRequest::query()->where('employee_id', $employee->id)->count())->toBe(4)
        ->and(DailyAttendanceRecord::query()->where('employee_id', $employee->id)->count())->toBe(6);
    Carbon\Carbon::setTestNow();
});

test('employee dashboard GET does not create attendance, balances, or ledger rows for an empty employee', function () {
    [$company, $user, $employee] = employeeDashboardIdentity();

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])->get(route('employee.portal'))
        ->assertInertia(fn ($page) => $page
            ->where('employeePortal.today.attendance', null)
            ->where('employeePortal.today.workStatus', 'No schedule assigned')
            ->where('employeePortal.leaveBalances', [])
            ->where('employeePortal.pendingLeaveRequestCount', 0));

    $this->assertDatabaseCount('daily_attendance_records', 0);
    $this->assertDatabaseCount('employee_leave_balance_accounts', 0);
    $this->assertDatabaseCount('leave_ledger_entries', 0);
    $this->assertDatabaseCount('leave_requests', 0);
});
