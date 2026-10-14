<?php

use App\Actions\SeedCompanyRoles;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\EmployeeWorkScheduleAssignment;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Employees\EmployeeProfileQuery;
use Spatie\Permission\PermissionRegistrar;

/** @return array{Company, User, Employee} */
function employeeProfileIdentity(array $employeeAttributes = []): array
{
    $company = Company::factory()->create();
    $user = User::factory()->create(['email' => 'login@example.test']);
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
        'personal_email' => 'personal@example.test',
        'personal_phone' => '+91 9876543210',
        'joining_date' => '2026-01-12',
        'employment_type' => 'full_time',
        'employment_status' => 'active',
    ], $employeeAttributes));

    return [$company, $user, $employee];
}

test('employee can view their own explicit profile with only safe fields', function () {
    [$company, $user, $employee] = employeeProfileIdentity([
        'date_of_birth' => '1990-01-02',
        'address' => 'Private home address',
        'emergency_contact_name' => 'Private contact',
        'notes' => 'Private HR note',
    ]);
    $department = Department::factory()->create(['company_id' => $company->id, 'name' => 'Engineering']);
    $designation = Designation::factory()->create(['company_id' => $company->id, 'name' => 'Developer']);
    $branch = Branch::factory()->create(['company_id' => $company->id, 'name' => 'Dhaka']);
    $employee->update(['department_id' => $department->id, 'designation_id' => $designation->id, 'branch_id' => $branch->id]);

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->get(route('employee.profile.show'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('employee-portal/profile', false)
            ->where('employeeProfile.identity.name', 'Rahul Das')
            ->where('employeeProfile.identity.employeeCode', 'EMP-001')
            ->where('employeeProfile.personal.email', 'personal@example.test')
            ->where('employeeProfile.personal.phone', '+91 9876543210')
            ->where('employeeProfile.employment.department', 'Engineering')
            ->where('employeeProfile.employment.designation', 'Developer')
            ->where('employeeProfile.employment.branch', 'Dhaka')
            ->where('employeeProfile.employment.type', 'Full Time')
            ->where('employeeProfile.employment.status', 'Active')
            ->where('employeeProfile.employment.joiningDate', '2026-01-12')
            ->where('employeeProfile.account.loginEmail', 'login@example.test')
            ->where('employeeProfile.account.connected', true)
            ->where('companyName', $company->name)
            ->where('canAccessManagement', false)
            ->where('auth.user', null)
            ->where('activeCompany', null)
            ->where('companyAccess', false)
            ->where('companies', [])
            ->where('employeePortalAccess', false)
            ->where('employeeProfile.workAssignment.shift', null)
            ->where('employeeProfile.workAssignment.workSchedule', null)
            ->missing('employeeProfile.id')
            ->missing('employeeProfile.employment.user_id')
            ->missing('employeeProfile.employment.company_id')
            ->missing('employeeProfile.personal.salary')
            ->missing('employeeProfile.personal.address')
            ->missing('employeeProfile.personal.date_of_birth')
            ->missing('employeeProfile.personal.emergency_contact_name')
            ->missing('employeeProfile.personal.notes')
            ->missing('employeeProfile.account.password')
            ->missing('employeeProfile.account.invitationHistory')
        );

    expect(app(EmployeeProfileQuery::class)->forEmployee($employee, $company)->props['identity']['name'])->toBe('Rahul Das');
});

test('profile identity cannot be changed through a query employee id and excludes coworkers', function () {
    [$company, $user] = employeeProfileIdentity();
    $coworker = Employee::factory()->create([
        'company_id' => $company->id,
        'first_name' => 'Other',
        'last_name' => 'Person',
        'employee_code' => 'SECRET-COWORKER',
        'personal_email' => 'coworker@example.test',
    ]);

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->get(route('employee.profile.show', ['employee_id' => $coworker->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('employeeProfile.identity.name', 'Rahul Das')
            ->where('employeeProfile.identity.employeeCode', 'EMP-001')
            ->missing('employeeProfile.coworker')
        );
});

test('profile uses only relations owned by the active company', function () {
    [$company, $user, $employee] = employeeProfileIdentity();
    $otherCompany = Company::factory()->create();
    $otherDepartment = Department::factory()->create(['company_id' => $otherCompany->id, 'name' => 'Other Company Secret']);
    $employee->update(['department_id' => $otherDepartment->id]);

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->get(route('employee.profile.show'))
        ->assertInertia(fn ($page) => $page
            ->where('employeeProfile.employment.department', null)
            ->missing('employeeProfile.otherCompany')
        );
});

test('the same user receives the selected company employee profile', function () {
    [$companyA, $user, $employeeA] = employeeProfileIdentity(['employee_code' => 'A-001']);
    $companyB = Company::factory()->create();
    CompanyUser::query()->create(['company_id' => $companyB->id, 'user_id' => $user->id, 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($companyB);
    $registrar = app(PermissionRegistrar::class);
    $previousTeamId = $registrar->getPermissionsTeamId();
    $registrar->setPermissionsTeamId($companyB->id);
    $user->unsetRelation('roles')->unsetRelation('permissions')->assignRole('employee');
    $registrar->setPermissionsTeamId($previousTeamId);
    $employeeB = Employee::factory()->create([
        'company_id' => $companyB->id,
        'user_id' => $user->id,
        'first_name' => 'Mira',
        'last_name' => 'Sen',
        'employee_code' => 'B-001',
    ]);

    $this->actingAs($user)->withSession(['active_company_id' => $companyA->id])
        ->get(route('employee.profile.show'))->assertInertia(fn ($page) => $page->where('employeeProfile.identity.employeeCode', 'A-001'));
    $this->withSession(['active_company_id' => $companyB->id])
        ->get(route('employee.profile.show'))->assertInertia(fn ($page) => $page
        ->where('employeeProfile.identity.name', 'Mira Sen')
        ->where('employeeProfile.identity.employeeCode', 'B-001'));
    expect($employeeA->id)->not->toBe($employeeB->id);
});

test('profile route requires the existing portal identity boundary', function () {
    $this->get(route('employee.profile.show'))->assertRedirect('/login');

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
        ->get(route('employee.profile.show'))->assertForbidden();
});

test('management-capable employees retain the management dashboard capability on profile', function () {
    [$company, $user] = employeeProfileIdentity();
    $registrar = app(PermissionRegistrar::class);
    $previousTeamId = $registrar->getPermissionsTeamId();
    $registrar->setPermissionsTeamId($company->id);
    $user->unsetRelation('roles')->unsetRelation('permissions')->assignRole('manager');
    $registrar->setPermissionsTeamId($previousTeamId);

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->get(route('employee.profile.show'))
        ->assertInertia(fn ($page) => $page
            ->where('canAccessManagement', true)
            ->where('companyName', $company->name)
            ->where('auth.user', null)
            ->where('activeCompany', null)
            ->where('companyAccess', false)
            ->where('companies', [])
            ->where('employeePortalAccess', false)
        );
});

test('profile get does not update the employee or create domain records', function () {
    [$company, $user, $employee] = employeeProfileIdentity();
    $updatedAt = $employee->updated_at;
    $attendanceCount = $company->dailyAttendanceRecords()->count();
    $leaveRequestCount = $company->leaveRequests()->count();
    $ledgerCount = $company->leaveLedgerEntries()->count();
    $balanceCount = $company->leaveBalanceAccounts()->count();

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->get(route('employee.profile.show'))->assertOk();

    expect($employee->fresh()->updated_at->equalTo($updatedAt))->toBeTrue()
        ->and($company->dailyAttendanceRecords()->count())->toBe($attendanceCount)
        ->and($company->leaveRequests()->count())->toBe($leaveRequestCount)
        ->and($company->leaveLedgerEntries()->count())->toBe($ledgerCount)
        ->and($company->leaveBalanceAccounts()->count())->toBe($balanceCount)
        ->and(EmployeeShiftAssignment::query()->where('employee_id', $employee->id)->count())->toBe(0)
        ->and(EmployeeWorkScheduleAssignment::query()->where('employee_id', $employee->id)->count())->toBe(0);
});

test('profile shows only current effective work assignments', function () {
    [$company, $user, $employee] = employeeProfileIdentity();
    $currentShift = $company->shifts()->create([
        'name' => 'Current Shift', 'code' => 'CUR', 'start_time' => '09:00', 'end_time' => '17:00',
        'break_duration_minutes' => 30, 'grace_period_minutes' => 0, 'is_overnight' => false, 'status' => 'active',
    ]);
    $oldShift = $company->shifts()->create([
        'name' => 'Old Shift', 'code' => 'OLD', 'start_time' => '08:00', 'end_time' => '16:00',
        'break_duration_minutes' => 30, 'grace_period_minutes' => 0, 'is_overnight' => false, 'status' => 'active',
    ]);
    EmployeeShiftAssignment::factory()->create([
        'company_id' => $company->id, 'employee_id' => $employee->id, 'shift_id' => $currentShift->id,
        'effective_from' => now()->subMonth()->toDateString(), 'effective_to' => null,
    ]);
    EmployeeShiftAssignment::factory()->create([
        'company_id' => $company->id, 'employee_id' => $employee->id, 'shift_id' => $oldShift->id,
        'effective_from' => now()->subMonths(3)->toDateString(), 'effective_to' => now()->subMonths(2)->toDateString(),
    ]);
    $schedule = WorkSchedule::factory()->create(['company_id' => $company->id, 'name' => 'Office Week']);
    EmployeeWorkScheduleAssignment::factory()->create([
        'company_id' => $company->id, 'employee_id' => $employee->id, 'work_schedule_id' => $schedule->id,
        'effective_from' => now()->subMonth()->toDateString(), 'effective_to' => null,
    ]);

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->get(route('employee.profile.show'))
        ->assertInertia(fn ($page) => $page
            ->where('employeeProfile.workAssignment.shift', 'Current Shift')
            ->where('employeeProfile.workAssignment.workSchedule', 'Office Week')
        );
});
