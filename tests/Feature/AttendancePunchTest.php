<?php

use App\Actions\SeedCompanyRoles;
use App\Enums\AttendancePunchSource;
use App\Enums\AttendancePunchType;
use App\Models\AttendancePunch;
use App\Models\AttendanceWorksite;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

/** @return array{User, Company} */
function attendancePunchRoleFixture(string $role, string $timezone = 'Asia/Kolkata'): array
{
    $user = User::factory()->create();
    $company = Company::factory()->create(['timezone' => $timezone]);
    CompanyUser::create(['company_id' => $company->id, 'user_id' => $user->id, 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($company);
    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    return [$user, $company];
}

function attendancePunchEmployee(Company $company, ?User $user = null, ?Employee $manager = null): Employee
{
    return Employee::factory()->create([
        'company_id' => $company->id,
        'user_id' => $user?->id,
        'manager_id' => $manager?->id,
    ]);
}

/** @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function attendanceManualPunchPayload(Employee $employee, array $overrides = []): array
{
    return array_merge([
        'employee_id' => $employee->id,
        'punch_type' => 'check_in',
        'punched_at' => '2026-10-05T09:01',
        'notes' => 'Entered from signed register',
    ], $overrides);
}

test('manual punch records an immutable attributed event with company local time and location metadata', function () {
    [$hr, $company] = attendancePunchRoleFixture('hr_admin');
    $employee = attendancePunchEmployee($company);
    $url = route('companies.attendance-punches.manual.store', $company);

    $this->actingAs($hr)->withHeader('User-Agent', 'HR workstation')->post($url, attendanceManualPunchPayload($employee, [
        'latitude' => '22.5726000',
        'longitude' => '88.3639000',
        'location_accuracy_meters' => '12.50',
        'device_identifier' => 'front-desk-kiosk',
        'external_reference' => 'register-row-001',
    ]))->assertRedirect(route('companies.attendance-punches.index', $company));

    $punch = AttendancePunch::query()->firstOrFail();
    expect($punch->company_id)->toBe($company->id)
        ->and($punch->employee_id)->toBe($employee->id)
        ->and($punch->punch_type)->toBe(AttendancePunchType::CheckIn)
        ->and($punch->source)->toBe(AttendancePunchSource::Manual)
        ->and($punch->timezone)->toBe('Asia/Kolkata')
        ->and($punch->punched_at->utc()->toISOString())->toBe('2026-10-05T03:31:00.000000Z')
        ->and($punch->created_by)->toBe($hr->id)
        ->and($punch->notes)->toBe('Entered from signed register')
        ->and((float) $punch->latitude)->toBe(22.5726)
        ->and((float) $punch->longitude)->toBe(88.3639)
        ->and((float) $punch->location_accuracy_meters)->toBe(12.5)
        ->and($punch->device_identifier)->toBe('front-desk-kiosk')
        ->and($punch->external_reference)->toBe('register-row-001')
        ->and($punch->ip_address)->not->toBeNull()
        ->and($punch->user_agent)->toBe('HR workstation');
});

test('manual entry requires an explanatory note and valid coordinates', function () {
    [$hr, $company] = attendancePunchRoleFixture('hr_admin');
    $employee = attendancePunchEmployee($company);

    $this->actingAs($hr)->post(route('companies.attendance-punches.manual.store', $company), attendanceManualPunchPayload($employee, [
        'notes' => '',
        'latitude' => 91,
        'longitude' => -181,
        'location_accuracy_meters' => -0.1,
    ]))->assertSessionHasErrors(['notes', 'latitude', 'longitude', 'location_accuracy_meters']);

    expect(AttendancePunch::query()->count())->toBe(0);
});

test('manual punch cannot select an employee from another company', function () {
    [$hr, $company] = attendancePunchRoleFixture('hr_admin');
    [, $foreignCompany] = attendancePunchRoleFixture('company_owner');
    $foreignEmployee = attendancePunchEmployee($foreignCompany);

    $this->actingAs($hr)->post(route('companies.attendance-punches.manual.store', $company), attendanceManualPunchPayload($foreignEmployee))
        ->assertSessionHasErrors('employee_id');

    expect(AttendancePunch::query()->count())->toBe(0);
});

test('a company member cannot switch to another company through the route', function () {
    [$hr, $company] = attendancePunchRoleFixture('hr_admin');
    [, $foreignCompany] = attendancePunchRoleFixture('company_owner');

    $this->actingAs($hr)->get(route('companies.attendance-punches.index', $foreignCompany))->assertNotFound();
});

test('manual punch timestamp and tenant fields cannot be forged in the payload', function () {
    [$hr, $company] = attendancePunchRoleFixture('hr_admin');
    $employee = attendancePunchEmployee($company);
    $foreignCompany = Company::factory()->create();

    $this->actingAs($hr)->post(route('companies.attendance-punches.manual.store', $company), attendanceManualPunchPayload($employee, [
        'company_id' => $foreignCompany->id,
        'source' => 'web',
        'created_by' => User::factory()->create()->id,
        'timezone' => 'America/New_York',
        'ip_address' => '203.0.113.10',
        'user_agent' => 'forged',
    ]))->assertSessionHasErrors(['company_id', 'source', 'created_by', 'timezone', 'ip_address', 'user_agent']);

    expect(AttendancePunch::query()->count())->toBe(0);
});

test('manual check out is accepted without applying the web self-punch sequence rule', function () {
    [$hr, $company] = attendancePunchRoleFixture('hr_admin');
    $employee = attendancePunchEmployee($company);

    $this->actingAs($hr)->post(route('companies.attendance-punches.manual.store', $company), attendanceManualPunchPayload($employee, [
        'punch_type' => 'check_out',
    ]))->assertRedirect();

    expect(AttendancePunch::query()->firstOrFail()->punch_type)->toBe(AttendancePunchType::CheckOut);
});

test('manual external references are idempotency keys within the company and source', function () {
    [$hr, $company] = attendancePunchRoleFixture('hr_admin');
    $firstEmployee = attendancePunchEmployee($company);
    $secondEmployee = attendancePunchEmployee($company);
    $url = route('companies.attendance-punches.manual.store', $company);

    $this->actingAs($hr)->post($url, attendanceManualPunchPayload($firstEmployee, ['external_reference' => 'source-123']))->assertRedirect();
    $this->post($url, attendanceManualPunchPayload($secondEmployee, ['external_reference' => 'source-123']))
        ->assertSessionHasErrors('external_reference');

    expect(AttendancePunch::query()->count())->toBe(1);
});

test('employee self punch records a server timestamp and derives actor employee company source and timezone', function () {
    [$employeeUser, $company] = attendancePunchRoleFixture('employee');
    $employee = attendancePunchEmployee($company, $employeeUser);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:01:12', 'Asia/Kolkata'));

    $this->actingAs($employeeUser)->post(route('companies.attendance-punches.self', $company), ['punch_type' => 'check_in'])
        ->assertRedirect();

    $punch = AttendancePunch::query()->firstOrFail();
    expect($punch->employee_id)->toBe($employee->id)
        ->and($punch->company_id)->toBe($company->id)
        ->and($punch->created_by)->toBe($employeeUser->id)
        ->and($punch->source)->toBe(AttendancePunchSource::Web)
        ->and($punch->punch_type)->toBe(AttendancePunchType::CheckIn)
        ->and($punch->timezone)->toBe('Asia/Kolkata')
        ->and($punch->punched_at->utc()->toISOString())->toBe('2026-10-05T03:31:12.000000Z')
        ->and($punch->ip_address)->not->toBeNull();
});

test('self punch rejects a first check out and duplicate consecutive punch types', function () {
    [$employeeUser, $company] = attendancePunchRoleFixture('employee');
    attendancePunchEmployee($company, $employeeUser);
    $url = route('companies.attendance-punches.self', $company);
    $this->actingAs($employeeUser)->post($url, ['punch_type' => 'check_out'])->assertSessionHasErrors('punch_type');

    $this->post($url, ['punch_type' => 'check_in'])->assertRedirect();
    $this->post($url, ['punch_type' => 'check_in'])->assertSessionHasErrors('punch_type');
    $this->post($url, ['punch_type' => 'check_out'])->assertRedirect();
    $this->post($url, ['punch_type' => 'check_out'])->assertSessionHasErrors('punch_type');

    expect(AttendancePunch::query()->count())->toBe(2);
});

test('self punch supports multiple chronological pairs across midnight', function () {
    [$employeeUser, $company] = attendancePunchRoleFixture('employee');
    attendancePunchEmployee($company, $employeeUser);
    $url = route('companies.attendance-punches.self', $company);
    $this->actingAs($employeeUser);

    foreach ([
        ['2026-10-05 23:55:00', 'check_in'],
        ['2026-10-06 00:10:00', 'check_out'],
        ['2026-10-06 12:00:00', 'check_in'],
        ['2026-10-06 12:30:00', 'check_out'],
    ] as [$time, $type]) {
        $this->travelTo(CarbonImmutable::parse($time, 'Asia/Kolkata'));
        $this->post($url, ['punch_type' => $type])->assertRedirect();
    }

    expect(AttendancePunch::query()->orderBy('punched_at')->get()->pluck('punch_type')->map(fn (AttendancePunchType $type): string => $type->value)->all())
        ->toBe(['check_in', 'check_out', 'check_in', 'check_out']);
});

test('web self punch rejects forged actor source timestamp employee and company fields', function () {
    [$employeeUser, $company] = attendancePunchRoleFixture('employee');
    $employee = attendancePunchEmployee($company, $employeeUser);
    $foreignCompany = Company::factory()->create();

    $this->actingAs($employeeUser)->post(route('companies.attendance-punches.self', $company), [
        'punch_type' => 'check_in',
        'company_id' => $foreignCompany->id,
        'employee_id' => Employee::factory()->create(['company_id' => $company->id])->id,
        'source' => 'manual',
        'created_by' => User::factory()->create()->id,
        'punched_at' => '2001-01-01T00:00',
        'timezone' => 'Pacific/Honolulu',
        'ip_address' => '203.0.113.20',
        'user_agent' => 'forged',
    ])->assertSessionHasErrors(['company_id', 'employee_id', 'source', 'created_by', 'punched_at', 'timezone', 'ip_address', 'user_agent']);

    expect($employee->attendancePunches()->count())->toBe(0);
});

test('a user without a linked employee record cannot self punch', function () {
    [$employeeUser, $company] = attendancePunchRoleFixture('employee');

    $this->actingAs($employeeUser)->post(route('companies.attendance-punches.self', $company), ['punch_type' => 'check_in'])
        ->assertForbidden();

    expect(AttendancePunch::query()->count())->toBe(0);
});

test('self punch does not require schedule holiday calendar or attendance policy assignments', function () {
    [$employeeUser, $company] = attendancePunchRoleFixture('employee');
    $employee = attendancePunchEmployee($company, $employeeUser);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00', 'Asia/Kolkata'));

    $this->actingAs($employeeUser)->post(route('companies.attendance-punches.self', $company), ['punch_type' => 'check_in'])
        ->assertRedirect();

    expect($employee->attendancePunches()->count())->toBe(1);
});

test('employees cannot list company punches but authorized HR can', function () {
    [$hr, $company] = attendancePunchRoleFixture('hr_admin');
    $employee = attendancePunchEmployee($company);
    AttendancePunch::factory()->create(['company_id' => $company->id, 'employee_id' => $employee->id]);
    $employeeUser = User::factory()->create();
    CompanyUser::create(['company_id' => $company->id, 'user_id' => $employeeUser->id, 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($company);
    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $employeeUser->assignRole('employee');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $this->actingAs($employeeUser)->get(route('companies.attendance-punches.index', $company))->assertForbidden();
    $this->actingAs($hr)->get(route('companies.attendance-punches.index', $company))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('attendance-punches/index', false)
            ->has('punches.data', 1)
            ->where('punches.data.0.source', 'web')
            ->where('punches.data.0.employee_code', $employee->employee_code));
});

test('manager punch listing includes own employees and direct reports only', function () {
    [$managerUser, $company] = attendancePunchRoleFixture('manager');
    $managerEmployee = attendancePunchEmployee($company, $managerUser);
    $directReport = attendancePunchEmployee($company, null, $managerEmployee);
    $outsideTeam = attendancePunchEmployee($company);
    AttendancePunch::factory()->count(1)->create(['company_id' => $company->id, 'employee_id' => $managerEmployee->id]);
    AttendancePunch::factory()->count(1)->create(['company_id' => $company->id, 'employee_id' => $directReport->id]);
    AttendancePunch::factory()->count(1)->create(['company_id' => $company->id, 'employee_id' => $outsideTeam->id]);

    $this->actingAs($managerUser)->get(route('companies.attendance-punches.index', $company))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('attendance-punches/index', false)
            ->has('punches.data', 2)
            ->where('punches.total', 2));
});

test('accountants cannot view or manually create attendance punches', function () {
    [$accountant, $company] = attendancePunchRoleFixture('accountant');
    $employee = attendancePunchEmployee($company);

    $this->actingAs($accountant)->get(route('companies.attendance-punches.index', $company))->assertForbidden();
    $this->post(route('companies.attendance-punches.manual.store', $company), attendanceManualPunchPayload($employee))->assertForbidden();

    expect(AttendancePunch::query()->count())->toBe(0);
});

test('attendance punch list filters by search type source and company-local date range', function () {
    [$hr, $company] = attendancePunchRoleFixture('hr_admin');
    $employee = attendancePunchEmployee($company);
    $selected = AttendancePunch::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'punch_type' => AttendancePunchType::CheckOut,
        'source' => AttendancePunchSource::Manual,
        'punched_at' => CarbonImmutable::parse('2026-10-05 23:00:00', 'Asia/Kolkata')->utc(),
    ]);
    AttendancePunch::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'punched_at' => CarbonImmutable::parse('2026-10-06 00:00:00', 'Asia/Kolkata')->utc(),
    ]);

    $this->actingAs($hr)->get(route('companies.attendance-punches.index', [
        'company' => $company,
        'search' => $employee->employee_code,
        'punch_type' => 'check_out',
        'source' => 'manual',
        'date_from' => '2026-10-05',
        'date_to' => '2026-10-05',
    ]))->assertOk()->assertInertia(fn (Assert $page) => $page->has('punches.data', 1)->where('punches.data.0.id', $selected->id));
});

test('normal punch update and delete endpoints are not registered and model mutation is blocked', function () {
    [$hr, $company] = attendancePunchRoleFixture('hr_admin');
    $employee = attendancePunchEmployee($company);
    $punch = AttendancePunch::factory()->create(['company_id' => $company->id, 'employee_id' => $employee->id]);

    expect(Route::has('companies.attendance-punches.update'))->toBeFalse()
        ->and(Route::has('companies.attendance-punches.destroy'))->toBeFalse();
    expect(fn () => $punch->update(['notes' => 'edited']))->toThrow(LogicException::class)
        ->and(fn () => $punch->delete())->toThrow(LogicException::class);
    expect(AttendancePunch::query()->find($punch->id))->not->toBeNull();
});

test('external reference uniqueness is scoped by company and source while null references repeat', function () {
    [, $company] = attendancePunchRoleFixture('hr_admin');
    $employee = attendancePunchEmployee($company);
    AttendancePunch::factory()->create(['company_id' => $company->id, 'employee_id' => $employee->id]);
    AttendancePunch::factory()->create(['company_id' => $company->id, 'employee_id' => $employee->id]);
    AttendancePunch::factory()->create(['company_id' => $company->id, 'employee_id' => $employee->id, 'external_reference' => 'device-event-1']);

    expect(fn () => AttendancePunch::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'external_reference' => 'device-event-1',
    ]))->toThrow(QueryException::class);
});

test('punch schema preserves raw fields without derived attendance or soft delete columns', function () {
    expect(Schema::hasTable('attendance_punches'))->toBeTrue()
        ->and(Schema::hasColumns('attendance_punches', [
            'company_id', 'employee_id', 'punch_type', 'punched_at', 'timezone', 'source',
            'latitude', 'longitude', 'location_accuracy_meters', 'created_by',
        ]))->toBeTrue()
        ->and(Schema::hasColumn('attendance_punches', 'deleted_at'))->toBeFalse()
        ->and(Schema::hasColumn('attendance_punches', 'attendance_status'))->toBeFalse()
        ->and(Schema::hasColumn('attendance_punches', 'worked_minutes'))->toBeFalse()
        ->and(Schema::hasColumn('attendance_punches', 'overtime_minutes'))->toBeFalse();
});

test('attendance punch permissions are seeded with the intended role access and remain repeatable', function () {
    foreach ([
        'company_owner' => ['attendance_punch.view', 'attendance_punch.create_manual'],
        'hr_admin' => ['attendance_punch.view', 'attendance_punch.create_manual'],
        'hr_manager' => ['attendance_punch.view', 'attendance_punch.create_manual'],
        'manager' => ['attendance_punch.view'],
        'employee' => ['attendance_punch.self_punch'],
    ] as $role => $expectedPermissions) {
        [$user, $company] = attendancePunchRoleFixture($role);
        app(SeedCompanyRoles::class)->handle($company);
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
        $permissions = $user->getAllPermissions()->pluck('name')->all();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        foreach ($expectedPermissions as $expectedPermission) {
            expect($permissions)->toContain($expectedPermission);
        }
    }
});

test('authorized management punch list shows verification audit without exposing worksite database ids', function () {
    [$hr, $company] = attendancePunchRoleFixture('hr_admin');
    $employee = attendancePunchEmployee($company);
    $worksite = AttendanceWorksite::factory()->create(['company_id' => $company->id, 'name' => 'North Office']);
    AttendancePunch::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'latitude' => '28.6000000',
        'longitude' => '77.2000000',
        'location_accuracy_meters' => '8.00',
        'location_mode' => 'geofence_required',
        'location_result' => 'verified',
        'worksite_id' => $worksite->id,
        'worksite_name_snapshot' => 'North Office',
        'location_distance_meters' => '72.50',
        'worksite_radius_meters_snapshot' => 100,
    ]);

    $this->actingAs($hr)->get(route('companies.attendance-punches.index', $company))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('punches.data.0.location_result', 'verified')
            ->where('punches.data.0.worksite_name', 'North Office')
            ->where('punches.data.0.coordinates', '28.6000000, 77.2000000')
            ->where('punches.data.0.distance_meters', '72.50')
            ->where('punches.data.0.radius_meters', 100)
            ->missing('punches.data.0.worksite_id'));
});
