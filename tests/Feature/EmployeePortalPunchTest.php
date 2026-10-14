<?php

use App\Actions\SeedCompanyRoles;
use App\Enums\AttendancePunchSource;
use App\Enums\AttendancePunchType;
use App\Models\AttendancePunch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\DailyAttendanceRecord;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** @return array{Company, User, Employee} */
function portalPunchEmployee(string $timezone = 'Asia/Kolkata'): array
{
    $company = Company::factory()->create(['timezone' => $timezone]);
    $user = User::factory()->create();
    CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $user->id, 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($company);
    portalPunchAssignRole($user, $company, 'employee');
    $employee = Employee::factory()->create(['company_id' => $company->id, 'user_id' => $user->id]);

    return [$company, $user, $employee];
}

function portalPunchAssignRole(User $user, Company $company, string $role): void
{
    $registrar = app(PermissionRegistrar::class);
    $previousTeamId = $registrar->getPermissionsTeamId();
    $registrar->setPermissionsTeamId($company->id);
    $user->unsetRelation('roles')->unsetRelation('permissions')->assignRole($role);
    $registrar->setPermissionsTeamId($previousTeamId);
}

function portalPunchStateUrl(): string
{
    return route('employee.attendance.punch-state');
}

function portalPunchInUrl(): string
{
    return route('employee.attendance.punch-in');
}

function portalPunchOutUrl(): string
{
    return route('employee.attendance.punch-out');
}

test('employee portal punch state is explicit bounded read only data', function () {
    [$company, $user] = portalPunchEmployee();

    $response = $this->actingAs($user)->withSession(['active_company_id' => $company->id])->get(portalPunchStateUrl());

    $response->assertOk()->assertExactJson([
        'state' => 'not_started',
        'canPunchIn' => true,
        'canPunchOut' => false,
        'reasonCode' => null,
        'lastPunchType' => null,
        'lastPunchedAt' => null,
        'timezone' => 'Asia/Kolkata',
        'locationMode' => 'disabled',
        'locationAvailable' => true,
        'lastLocationResult' => null,
    ]);
    expect(AttendancePunch::query()->count())->toBe(0)
        ->and(DailyAttendanceRecord::query()->count())->toBe(0);
});

test('portal punch in and out reuse immutable server-timestamped attendance events without materializing a second truth', function () {
    [$company, $user, $employee] = portalPunchEmployee();
    $this->travelTo('2026-10-05 09:01:12 Asia/Kolkata');

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->post(portalPunchInUrl())->assertRedirect();

    $in = AttendancePunch::query()->firstOrFail();
    expect($in->company_id)->toBe($company->id)
        ->and($in->employee_id)->toBe($employee->id)
        ->and($in->created_by)->toBe($user->id)
        ->and($in->punch_type)->toBe(AttendancePunchType::CheckIn)
        ->and($in->source)->toBe(AttendancePunchSource::Web)
        ->and($in->punched_at->utc()->toISOString())->toBe('2026-10-05T03:31:12.000000Z');

    $this->get(portalPunchStateUrl())->assertExactJson([
        'state' => 'clocked_in',
        'canPunchIn' => false,
        'canPunchOut' => true,
        'reasonCode' => 'ALREADY_PUNCHED_IN',
        'lastPunchType' => 'check_in',
        'lastPunchedAt' => '2026-10-05T09:01:12+05:30',
        'timezone' => 'Asia/Kolkata',
        'locationMode' => 'disabled',
        'locationAvailable' => true,
        'lastLocationResult' => null,
    ]);

    $this->travelTo('2026-10-05 18:02:00 Asia/Kolkata');
    $this->post(portalPunchOutUrl())->assertRedirect();
    $this->get(portalPunchStateUrl())->assertJsonPath('state', 'clocked_out')
        ->assertJsonPath('canPunchIn', true)
        ->assertJsonPath('canPunchOut', false);

    expect(AttendancePunch::query()->count())->toBe(2)
        ->and(DailyAttendanceRecord::query()->count())->toBe(0);
});

test('portal punch transition rules reject duplicate in and out and out without a prior in', function () {
    [$company, $user] = portalPunchEmployee();
    $this->actingAs($user)->withSession(['active_company_id' => $company->id]);
    $this->travelTo('2026-10-05 09:00 Asia/Kolkata');

    $this->post(portalPunchOutUrl())->assertSessionHasErrors();
    $this->post(portalPunchInUrl())->assertRedirect();
    $this->post(portalPunchInUrl())->assertSessionHasErrors();
    $this->post(portalPunchOutUrl())->assertRedirect();
    $this->post(portalPunchOutUrl())->assertSessionHasErrors();

    expect(AttendancePunch::query()->count())->toBe(2);
});

test('portal punch request cannot choose employee company timestamp date type or calculated status', function () {
    [$company, $user, $employee] = portalPunchEmployee();
    $coworker = Employee::factory()->create(['company_id' => $company->id]);

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->post(portalPunchInUrl(), [
            'employee_id' => $coworker->id,
            'user_id' => User::factory()->create()->id,
            'company_id' => Company::factory()->create()->id,
            'attendance_date' => '2001-01-01',
            'punch_type' => 'check_out',
            'check_in' => '2001-01-01 08:00:00',
            'check_out' => '2001-01-01 09:00:00',
            'check_in_time' => '2001-01-01 08:00:00',
            'check_out_time' => '2001-01-01 09:00:00',
            'punched_at' => '2001-01-01 08:00:00',
            'timestamp' => '2001-01-01 08:00:00',
            'status' => 'present',
        ])->assertSessionHasErrors([
            'employee_id', 'user_id', 'company_id', 'attendance_date', 'punch_type', 'check_in', 'check_out',
            'check_in_time', 'check_out_time', 'punched_at', 'timestamp', 'status',
        ]);

    expect($employee->attendancePunches()->count())->toBe(0)
        ->and($coworker->attendancePunches()->count())->toBe(0);
});

test('portal punch requires active employee portal identity and membership', function () {
    [$company, $user, $employee] = portalPunchEmployee();
    $this->actingAs($user)->withSession(['active_company_id' => $company->id]);

    $this->post(portalPunchInUrl())->assertRedirect();
    $user->companies()->updateExistingPivot($company->id, ['status' => 'inactive']);

    $this->post(portalPunchOutUrl())->assertForbidden();
    expect(AttendancePunch::query()->count())->toBe(1);

    $employee->delete();
    $this->get(portalPunchStateUrl())->assertForbidden();
});

test('portal punch requires employee role as well as employee identity', function () {
    [$company, $user, $employee] = portalPunchEmployee();
    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $user->syncRoles([]);
    portalPunchAssignRole($user, $company, 'manager');

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->post(portalPunchInUrl())->assertForbidden();

    expect($employee->attendancePunches()->count())->toBe(0);
});

test('portal punch also respects the existing self punch permission', function () {
    [$company, $user, $employee] = portalPunchEmployee();
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($company->id);
    Role::query()->where('name', 'employee')->where('team_id', $company->id)->firstOrFail()
        ->revokePermissionTo('attendance_punch.self_punch');
    $registrar->forgetCachedPermissions();
    $registrar->setPermissionsTeamId(null);

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->post(portalPunchInUrl())->assertForbidden();

    expect($employee->attendancePunches()->count())->toBe(0);
});

test('valid multi role employee can punch only their own linked identity', function () {
    [$company, $user, $employee] = portalPunchEmployee();
    $coworker = Employee::factory()->create(['company_id' => $company->id]);
    portalPunchAssignRole($user, $company, 'manager');
    $this->travelTo('2026-10-05 09:00 Asia/Kolkata');

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->post(portalPunchInUrl())->assertRedirect();

    expect($employee->attendancePunches()->count())->toBe(1)
        ->and($coworker->attendancePunches()->count())->toBe(0);
});

test('same user uses the selected company employee identity and company timezone', function () {
    [$companyA, $user, $employeeA] = portalPunchEmployee('America/Los_Angeles');
    $companyB = Company::factory()->create(['timezone' => 'Asia/Kolkata']);
    CompanyUser::query()->create(['company_id' => $companyB->id, 'user_id' => $user->id, 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($companyB);
    portalPunchAssignRole($user, $companyB, 'employee');
    $employeeB = Employee::factory()->create(['company_id' => $companyB->id, 'user_id' => $user->id]);
    $this->travelTo('2026-10-06 00:30:00 UTC');
    $this->actingAs($user)->withSession(['active_company_id' => $companyA->id]);

    $this->get(portalPunchStateUrl())->assertJsonPath('timezone', 'America/Los_Angeles');
    $this->post(portalPunchInUrl())->assertRedirect();
    $this->get(portalPunchStateUrl())->assertJsonPath('lastPunchedAt', '2026-10-05T17:30:00-07:00');
    $this->withSession(['active_company_id' => $companyB->id])->get(portalPunchStateUrl())
        ->assertJsonPath('state', 'not_started')
        ->assertJsonPath('timezone', 'Asia/Kolkata');
    $this->post(portalPunchInUrl())->assertRedirect();

    expect($employeeA->attendancePunches()->count())->toBe(1)
        ->and($employeeB->attendancePunches()->count())->toBe(1)
        ->and($employeeA->attendancePunches()->firstOrFail()->punched_at->utc()->toISOString())->toBe('2026-10-06T00:30:00.000000Z')
        ->and($employeeB->attendancePunches()->firstOrFail()->punched_at->utc()->toISOString())->toBe('2026-10-06T00:30:00.000000Z');
});

test('overnight check out remains in the raw event stream and state follows its actual local instant', function () {
    [$company, $user, $employee] = portalPunchEmployee();
    $this->actingAs($user)->withSession(['active_company_id' => $company->id]);
    $this->travelTo('2026-10-05 22:00:00 Asia/Kolkata');
    $this->post(portalPunchInUrl())->assertRedirect();
    $this->travelTo('2026-10-06 01:00:00 Asia/Kolkata');
    $this->post(portalPunchOutUrl())->assertRedirect();

    $this->get(portalPunchStateUrl())->assertJsonPath('state', 'clocked_out')
        ->assertJsonPath('lastPunchedAt', '2026-10-06T01:00:00+05:30');

    expect($employee->attendancePunches()->orderBy('punched_at')->pluck('punch_type')->map(fn (AttendancePunchType $type): string => $type->value)->all())
        ->toBe(['check_in', 'check_out']);
});

test('guests role only users and unaffiliated system administrators cannot use employee punch routes', function () {
    $this->get(portalPunchStateUrl())->assertRedirect('/login');
    $this->post(portalPunchInUrl())->assertRedirect('/login');

    [$company, $user] = portalPunchEmployee();
    $user->employees()->delete();
    $this->actingAs($user)->withSession(['active_company_id' => $company->id])->get(portalPunchStateUrl())->assertForbidden();

    $admin = User::factory()->create();
    app(RolePermissionSeeder::class)->run();
    app(PermissionRegistrar::class)->setPermissionsTeamId(User::SYSTEM_TEAM_ID);
    $admin->assignRole('super_admin');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $this->actingAs($admin)->withSession(['active_company_id' => $company->id])->post(portalPunchInUrl())->assertForbidden();
});
