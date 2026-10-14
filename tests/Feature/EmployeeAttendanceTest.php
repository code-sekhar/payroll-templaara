<?php

use App\Actions\SeedCompanyRoles;
use App\Enums\CalculatedAttendanceStatus;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\DailyAttendanceRecord;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveType;
use App\Models\Shift;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

/** @return array{Company, User, Employee} */
function employeeAttendanceIdentity(?Company $company = null, array $employeeAttributes = []): array
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

function employeeAttendanceUrl(array $query = []): string
{
    return route('employee.attendance.index', $query);
}

test('employee attendance renders safe saved business-date records including overnight punches', function () {
    [$company, $user, $employee] = employeeAttendanceIdentity();
    $shift = Shift::factory()->create(['company_id' => $company->id, 'name' => 'Night Operations']);
    $record = DailyAttendanceRecord::factory()->present()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_date' => '2026-10-06',
        'shift_id' => $shift->id,
        'expected_start_at' => '2026-10-06 18:30:00',
        'expected_end_at' => '2026-10-07 01:00:00',
        'first_check_in_at' => '2026-10-06 19:35:00',
        'last_check_out_at' => '2026-10-07 00:31:00',
        'worked_minutes' => 476,
        'late_minutes' => 14,
        'is_late' => true,
        'early_leave_minutes' => 29,
        'is_early_leave' => true,
    ]);

    $this->travelTo('2026-10-06 09:00:00 Asia/Kolkata');
    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->get(employeeAttendanceUrl(['month' => '2026-10']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('employee-portal/attendance', false)
            ->where('employeeAttendance.month', '2026-10')
            ->where('employeeAttendance.timezone', 'Asia/Kolkata')
            ->where('employeeAttendance.recordedDays', 1)
            ->where('employeeAttendance.days.5.date', '2026-10-06')
            ->where('employeeAttendance.days.5.recorded', true)
            ->where('employeeAttendance.days.5.attendanceStatus', 'present')
            ->where('employeeAttendance.days.5.statusLabel', 'Present')
            ->where('employeeAttendance.days.5.shiftName', 'Night Operations')
            ->where('employeeAttendance.days.5.scheduledStart', '12:00 AM (+1 day)')
            ->where('employeeAttendance.days.5.checkIn', '1:05 AM (+1 day)')
            ->where('employeeAttendance.days.5.checkOut', '6:01 AM (+1 day)')
            ->where('employeeAttendance.days.5.workedDuration', '7h 56m')
            ->where('employeeAttendance.days.5.exceptions', ['Late arrival · 14m', 'Early departure · 29m'])
            ->where('employeeAttendance.days.5.isToday', true)
            ->where('employeePortal.employeeName', $employee->full_name)
            ->where('employeePortal.companyName', $company->name)
            ->where('auth.user', null)
            ->where('activeCompany', null)
            ->where('companyAccess', false)
            ->where('companies', [])
            ->where('employeePortalAccess', false)
            ->missing('employeeAttendance.employee_id')
            ->missing('employeeAttendance.company_id')
            ->missing('employeeAttendance.days.5.id')
            ->missing('employeeAttendance.days.5.employee_id')
            ->missing('employeeAttendance.days.5.company_id'));

    expect($record->exists)->toBeTrue();
});

test('attendance history distinguishes missing materialization from an explicit absent record', function () {
    [$company, $user, $employee] = employeeAttendanceIdentity();
    DailyAttendanceRecord::factory()->absent()->create([
        'company_id' => $company->id, 'employee_id' => $employee->id, 'attendance_date' => '2026-10-05',
    ]);
    $this->travelTo('2026-10-06 12:00:00 Asia/Kolkata');

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->get(employeeAttendanceUrl(['month' => '2026-10']))
        ->assertInertia(fn ($page) => $page
            ->where('employeeAttendance.days.4.recorded', true)
            ->where('employeeAttendance.days.4.statusLabel', 'Absent')
            ->where('employeeAttendance.days.5.recorded', false)
            ->where('employeeAttendance.days.5.statusLabel', null)
            ->where('employeeAttendance.days.5.exceptions', [])
            ->where('employeeAttendance.days.5.isToday', true)
            ->where('employeeAttendance.days.6.isFuture', true)
            ->where('employeeAttendance.recordedDays', 1));
});

test('incomplete status is described safely and future attendance never presents policy exceptions', function () {
    [$company, $user, $employee] = employeeAttendanceIdentity();
    DailyAttendanceRecord::factory()->incomplete()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_date' => '2026-10-05',
    ]);
    DailyAttendanceRecord::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_date' => '2026-10-07',
        'status' => CalculatedAttendanceStatus::NotYetDue,
        'late_minutes' => 12,
        'is_late' => true,
        'early_leave_minutes' => 8,
        'is_early_leave' => true,
    ]);
    $this->travelTo('2026-10-06 12:00:00 Asia/Kolkata');

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->get(employeeAttendanceUrl(['month' => '2026-10']))
        ->assertInertia(fn ($page) => $page
            ->where('employeeAttendance.days.4.statusLabel', 'Incomplete')
            ->where('employeeAttendance.days.4.exceptions', ['Attendance details are incomplete.'])
            ->where('employeeAttendance.days.6.isFuture', true)
            ->where('employeeAttendance.days.6.exceptions', []));
});

test('attendance history is scoped to the linked employee and active company', function () {
    [$companyA, $user, $employeeA] = employeeAttendanceIdentity();
    $employeeB = Employee::factory()->create(['company_id' => $companyA->id, 'employee_code' => 'COWORKER-SECRET']);
    $companyB = Company::factory()->create();
    $employeeOtherCompany = Employee::factory()->create(['company_id' => $companyB->id, 'employee_code' => 'TENANT-SECRET']);
    DailyAttendanceRecord::factory()->present()->create([
        'company_id' => $companyA->id, 'employee_id' => $employeeB->id, 'attendance_date' => '2026-10-06',
    ]);
    DailyAttendanceRecord::factory()->present()->create([
        'company_id' => $companyB->id, 'employee_id' => $employeeOtherCompany->id, 'attendance_date' => '2026-10-06',
    ]);
    DailyAttendanceRecord::factory()->present()->create([
        'company_id' => $companyA->id, 'employee_id' => $employeeA->id, 'attendance_date' => '2026-10-06',
    ]);

    $this->actingAs($user)->withSession(['active_company_id' => $companyA->id])
        ->get(employeeAttendanceUrl(['month' => '2026-10', 'employee_id' => $employeeB->id]))
        ->assertSessionHasErrors('employee_id');

    $this->get(employeeAttendanceUrl(['month' => '2026-10']))
        ->assertInertia(fn ($page) => $page
            ->where('employeeAttendance.recordedDays', 1)
            ->where('employeeAttendance.days.5.statusLabel', 'Present')
            ->missing('employeeAttendance.days.5.employeeCode')
            ->missing('employeeAttendance.days.5.id'));
});

test('same user sees attendance for the employee linked in the selected company', function () {
    [$companyA, $user, $employeeA] = employeeAttendanceIdentity();
    $companyB = Company::factory()->create(['timezone' => 'Asia/Dhaka']);
    CompanyUser::query()->create(['company_id' => $companyB->id, 'user_id' => $user->id, 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($companyB);
    $registrar = app(PermissionRegistrar::class);
    $previousTeamId = $registrar->getPermissionsTeamId();
    $registrar->setPermissionsTeamId($companyB->id);
    $user->unsetRelation('roles')->unsetRelation('permissions')->assignRole('employee');
    $registrar->setPermissionsTeamId($previousTeamId);
    $employeeB = Employee::factory()->create([
        'company_id' => $companyB->id, 'user_id' => $user->id, 'employee_code' => 'B-001',
    ]);
    DailyAttendanceRecord::factory()->present()->create([
        'company_id' => $companyA->id, 'employee_id' => $employeeA->id, 'attendance_date' => '2026-10-06',
    ]);
    DailyAttendanceRecord::factory()->absent()->create([
        'company_id' => $companyB->id, 'employee_id' => $employeeB->id, 'attendance_date' => '2026-10-06',
    ]);

    $this->actingAs($user)->withSession(['active_company_id' => $companyA->id])
        ->get(employeeAttendanceUrl(['month' => '2026-10']))
        ->assertInertia(fn ($page) => $page->where('employeeAttendance.days.5.statusLabel', 'Present'));
    $this->withSession(['active_company_id' => $companyB->id])
        ->get(employeeAttendanceUrl(['month' => '2026-10']))
        ->assertInertia(fn ($page) => $page
            ->where('employeeAttendance.days.5.statusLabel', 'Absent')
            ->where('employeePortal.companyName', $companyB->name));
});

test('attendance month defaults to company local month when UTC is on the prior date', function () {
    [$company, $user] = employeeAttendanceIdentity();
    $this->travelTo('2026-09-30 18:45:00 UTC');

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->get(employeeAttendanceUrl())
        ->assertInertia(fn ($page) => $page
            ->where('employeeAttendance.month', '2026-10')
            ->where('employeeAttendance.today', '2026-10-01')
            ->where('employeeAttendance.days.0.isToday', true));
});

test('attendance month selection rejects malformed dates and client identity fields', function () {
    [$company, $user] = employeeAttendanceIdentity();
    $this->actingAs($user)->withSession(['active_company_id' => $company->id]);

    foreach (['2026-13', '2026-00', '0000-01', '2026-1'] as $month) {
        $this->getJson(employeeAttendanceUrl(['month' => $month]))->assertUnprocessable()->assertJsonValidationErrors('month');
    }

    $this->getJson(employeeAttendanceUrl(['company_id' => $company->id]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('company_id');
});

test('attendance month contains only its selected date range and validates month boundary navigation', function () {
    [$company, $user, $employee] = employeeAttendanceIdentity();
    foreach (['2026-09-30', '2026-10-01', '2026-10-31', '2026-11-01'] as $date) {
        DailyAttendanceRecord::factory()->present()->create([
            'company_id' => $company->id, 'employee_id' => $employee->id, 'attendance_date' => $date,
        ]);
    }
    $this->travelTo('2026-10-06 10:00:00 Asia/Kolkata');

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->get(employeeAttendanceUrl(['month' => '2026-10']))
        ->assertInertia(fn ($page) => $page
            ->where('employeeAttendance.recordedDays', 2)
            ->where('employeeAttendance.days.0.statusLabel', 'Present')
            ->where('employeeAttendance.days.30.statusLabel', 'Present')
            ->where('employeeAttendance.previousMonth', '2026-09')
            ->where('employeeAttendance.nextMonth', '2026-11'));
});

test('saved holiday and approved leave records expose only their saved safe labels', function () {
    [$company, $user, $employee] = employeeAttendanceIdentity();
    $holiday = Holiday::factory()->create(['company_id' => $company->id, 'name' => 'Company Foundation Day']);
    $leaveType = LeaveType::factory()->create(['company_id' => $company->id, 'name' => 'Personal Leave']);
    DailyAttendanceRecord::factory()->holiday()->create([
        'company_id' => $company->id, 'employee_id' => $employee->id,
        'attendance_date' => '2026-10-05', 'holiday_id' => $holiday->id,
    ]);
    DailyAttendanceRecord::factory()->create([
        'company_id' => $company->id, 'employee_id' => $employee->id,
        'attendance_date' => '2026-10-06', 'status' => CalculatedAttendanceStatus::OnLeave,
        'leave_type_id' => $leaveType->id,
    ]);

    $this->travelTo('2026-10-06 12:00:00 Asia/Kolkata');
    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->get(employeeAttendanceUrl(['month' => '2026-10']))
        ->assertInertia(fn ($page) => $page
            ->where('employeeAttendance.days.4.holiday', true)
            ->where('employeeAttendance.days.4.holidayName', 'Company Foundation Day')
            ->where('employeeAttendance.days.4.exceptions', [])
            ->where('employeeAttendance.days.5.approvedLeave', true)
            ->where('employeeAttendance.days.5.leaveType', 'Personal Leave')
            ->where('employeeAttendance.days.5.exceptions', []));
});

test('attendance route preserves the employee portal identity boundary', function () {
    $this->get(employeeAttendanceUrl())->assertRedirect('/login');

    $company = Company::factory()->create();
    $user = User::factory()->create();
    CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $user->id, 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($company);
    $registrar = app(PermissionRegistrar::class);
    $previousTeamId = $registrar->getPermissionsTeamId();
    $registrar->setPermissionsTeamId($company->id);
    $user->assignRole('employee');
    $registrar->setPermissionsTeamId($previousTeamId);

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->get(employeeAttendanceUrl())
        ->assertForbidden();
});

test('attendance history GET does not create or modify attendance records', function () {
    [$company, $user, $employee] = employeeAttendanceIdentity();
    $record = DailyAttendanceRecord::factory()->present()->create([
        'company_id' => $company->id, 'employee_id' => $employee->id, 'attendance_date' => '2026-10-06',
    ]);
    $updatedAt = $record->updated_at;
    $this->travelTo('2026-10-06 12:00:00 Asia/Kolkata');

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->get(employeeAttendanceUrl(['month' => '2026-10']))->assertOk();
    $this->get(employeeAttendanceUrl(['month' => '2026-10']))->assertOk();

    expect(DailyAttendanceRecord::query()->count())->toBe(1)
        ->and(DailyAttendanceRecord::query()->findOrFail($record->id)->updated_at->equalTo($updatedAt))->toBeTrue();
});
