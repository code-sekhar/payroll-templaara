<?php

use App\Actions\SeedCompanyRoles;
use App\Enums\PunchLocationMode;
use App\Models\AttendancePolicy;
use App\Models\AttendancePunch;
use App\Models\AttendanceWorksite;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\EmployeeAttendancePolicyAssignment;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

/** @return array{Company, User, Employee} */
function locationPunchIdentity(): array
{
    $company = Company::factory()->create(['timezone' => 'Asia/Kolkata']);
    $user = User::factory()->create();
    CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $user->id, 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($company);
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($company->id);
    $user->assignRole('employee');
    $registrar->setPermissionsTeamId(null);
    $employee = Employee::factory()->create(['company_id' => $company->id, 'user_id' => $user->id]);

    return [$company, $user, $employee];
}

function assignPunchLocationPolicy(Company $company, Employee $employee, PunchLocationMode $mode, array $worksites = []): AttendancePolicy
{
    $policy = AttendancePolicy::factory()->create(['company_id' => $company->id, 'location_mode' => $mode]);
    EmployeeAttendancePolicyAssignment::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_policy_id' => $policy->id,
        'effective_from' => '2026-10-01',
        'effective_to' => null,
    ]);

    foreach ($worksites as $worksite) {
        $policy->worksites()->attach($worksite->id, ['company_id' => $company->id]);
    }

    return $policy;
}

function locationPunchInUrl(): string
{
    return route('employee.attendance.punch-in');
}

function locationPunchOutUrl(): string
{
    return route('employee.attendance.punch-out');
}

test('disabled location policy preserves normal punch behavior and stores no submitted coordinates', function () {
    [$company, $user] = locationPunchIdentity();
    $this->travelTo('2026-10-07 09:00:00 Asia/Kolkata');
    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->post(locationPunchInUrl(), ['latitude' => 28.6, 'longitude' => 77.2, 'location_accuracy_meters' => 8])->assertRedirect();

    $punch = AttendancePunch::query()->sole();
    expect($punch->latitude)->toBeNull()
        ->and($punch->longitude)->toBeNull()
        ->and($punch->location_mode)->toBe('disabled')
        ->and($punch->location_result)->toBe('not_requested');
});

test('required location rejects direct punch without coordinates and persists a valid location reading', function () {
    [$company, $user, $employee] = locationPunchIdentity();
    assignPunchLocationPolicy($company, $employee, PunchLocationMode::Required);
    $this->travelTo('2026-10-07 09:00:00 Asia/Kolkata');
    $this->actingAs($user)->withSession(['active_company_id' => $company->id]);

    $this->post(locationPunchInUrl())->assertSessionHasErrors(['location']);
    expect(AttendancePunch::query()->count())->toBe(0);

    $this->post(locationPunchInUrl(), ['latitude' => 28.6139, 'longitude' => 77.2090, 'location_accuracy_meters' => 12.4])->assertRedirect();
    $punch = AttendancePunch::query()->sole();
    expect($punch->latitude)->toBe('28.6139000')
        ->and($punch->longitude)->toBe('77.2090000')
        ->and($punch->location_accuracy_meters)->toBe('12.40')
        ->and($punch->location_mode)->toBe('required')
        ->and($punch->location_result)->toBe('provided');
});

test('geofence accepts inside and inclusive boundary coordinates and snapshots matching worksite context', function () {
    [$company, $user, $employee] = locationPunchIdentity();
    $worksite = AttendanceWorksite::factory()->create(['company_id' => $company->id, 'name' => 'Central Office', 'latitude' => 0, 'longitude' => 0, 'radius_meters' => 100]);
    assignPunchLocationPolicy($company, $employee, PunchLocationMode::GeofenceRequired, [$worksite]);
    $this->travelTo('2026-10-07 09:00:00 Asia/Kolkata');
    $this->actingAs($user)->withSession(['active_company_id' => $company->id]);

    $this->post(locationPunchInUrl(), ['latitude' => 0, 'longitude' => 0, 'location_accuracy_meters' => 4])->assertRedirect();
    $punch = AttendancePunch::query()->sole();
    expect($punch->location_result)->toBe('verified')
        ->and($punch->worksite_id)->toBe($worksite->id)
        ->and($punch->worksite_name_snapshot)->toBe('Central Office')
        ->and($punch->worksite_radius_meters_snapshot)->toBe(100)
        ->and((float) $punch->location_distance_meters)->toBe(0.0);

    $worksite->update(['name' => 'Renamed Office', 'radius_meters' => 250]);
    expect($punch->fresh()->worksite_name_snapshot)->toBe('Central Office')
        ->and($punch->fresh()->worksite_radius_meters_snapshot)->toBe(100);
});

test('geofence rejects outside and inactive worksites without recording a punch', function () {
    [$company, $user, $employee] = locationPunchIdentity();
    $worksite = AttendanceWorksite::factory()->create(['company_id' => $company->id, 'latitude' => 0, 'longitude' => 0, 'radius_meters' => 100]);
    assignPunchLocationPolicy($company, $employee, PunchLocationMode::GeofenceRequired, [$worksite]);
    $this->travelTo('2026-10-07 09:00:00 Asia/Kolkata');
    $this->actingAs($user)->withSession(['active_company_id' => $company->id]);

    $this->post(locationPunchInUrl(), ['latitude' => 0, 'longitude' => 0.01])->assertSessionHasErrors(['location']);
    expect(AttendancePunch::query()->count())->toBe(0);

    $worksite->update(['is_active' => false]);
    $this->post(locationPunchInUrl(), ['latitude' => 0, 'longitude' => 0])->assertSessionHasErrors(['location']);
    expect(AttendancePunch::query()->count())->toBe(0);
});

test('geofence accepts a punch at the radius boundary and searches all policy worksites', function () {
    [$company, $user, $employee] = locationPunchIdentity();
    $first = AttendanceWorksite::factory()->create(['company_id' => $company->id, 'name' => 'First Site', 'latitude' => 0, 'longitude' => 0, 'radius_meters' => 20]);
    $second = AttendanceWorksite::factory()->create(['company_id' => $company->id, 'name' => 'Second Site', 'latitude' => 0, 'longitude' => 0.01, 'radius_meters' => 100]);
    assignPunchLocationPolicy($company, $employee, PunchLocationMode::GeofenceRequired, [$first, $second]);
    $this->travelTo('2026-10-07 09:00:00 Asia/Kolkata');
    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->post(locationPunchInUrl(), ['latitude' => 0, 'longitude' => 0.0108983153])->assertRedirect();

    expect(AttendancePunch::query()->sole()->worksite_name_snapshot)->toBe('Second Site');
});

test('invalid coordinates and client supplied verification or tenant fields cannot create a punch', function (array $payload, string $field) {
    [$company, $user, $employee] = locationPunchIdentity();
    assignPunchLocationPolicy($company, $employee, PunchLocationMode::Required);
    $this->travelTo('2026-10-07 09:00:00 Asia/Kolkata');
    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->post(locationPunchInUrl(), $payload)->assertSessionHasErrors([$field]);

    expect(AttendancePunch::query()->count())->toBe(0);
})->with([
    'latitude outside range' => [['latitude' => 999, 'longitude' => 0], 'latitude'],
    'longitude outside range' => [['latitude' => 0, 'longitude' => -999], 'longitude'],
    'not a numeric latitude' => [['latitude' => 'NaN', 'longitude' => 0], 'latitude'],
    'worksite id' => [['latitude' => 0, 'longitude' => 0, 'worksite_id' => 1], 'worksite_id'],
    'fake geofence result' => [['latitude' => 0, 'longitude' => 0, 'geofence_passed' => true], 'geofence_passed'],
    'fake distance' => [['latitude' => 0, 'longitude' => 0, 'distance_meters' => 0], 'distance_meters'],
    'policy mode' => [['latitude' => 0, 'longitude' => 0, 'location_mode' => 'disabled'], 'location_mode'],
    'employee id' => [['latitude' => 0, 'longitude' => 0, 'employee_id' => 1], 'employee_id'],
    'company id' => [['latitude' => 0, 'longitude' => 0, 'company_id' => 1], 'company_id'],
    'punch timestamp' => [['latitude' => 0, 'longitude' => 0, 'timestamp' => '2000-01-01'], 'timestamp'],
    'punch type' => [['latitude' => 0, 'longitude' => 0, 'punch_type' => 'check_out'], 'punch_type'],
    'attendance status' => [['latitude' => 0, 'longitude' => 0, 'status' => 'present'], 'status'],
]);

test('state response exposes location requirement without policy or worksite internals', function () {
    [$company, $user, $employee] = locationPunchIdentity();
    $worksite = AttendanceWorksite::factory()->create(['company_id' => $company->id]);
    assignPunchLocationPolicy($company, $employee, PunchLocationMode::GeofenceRequired, [$worksite]);

    $response = $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->get(route('employee.attendance.punch-state'));

    $response->assertOk()->assertJsonPath('locationMode', 'geofence_required')->assertJsonPath('locationAvailable', true);
    expect(array_intersect(['employee_id', 'company_id', 'worksite_id', 'latitude', 'longitude'], array_keys($response->json())))->toBe([]);
});

test('optional capture allows a punch without location and records a supplied location only when provided', function () {
    [$company, $user, $employee] = locationPunchIdentity();
    assignPunchLocationPolicy($company, $employee, PunchLocationMode::Capture);
    $this->travelTo('2026-10-07 09:00:00 Asia/Kolkata');
    $this->actingAs($user)->withSession(['active_company_id' => $company->id]);

    $this->post(locationPunchInUrl())->assertRedirect();
    $punch = AttendancePunch::query()->sole();
    expect($punch->location_result)->toBe('not_captured')->and($punch->latitude)->toBeNull();
});

test('a worksite owned by another company cannot satisfy the selected employee policy', function () {
    [$company, $user, $employee] = locationPunchIdentity();
    $foreignCompany = Company::factory()->create();
    AttendanceWorksite::factory()->create(['company_id' => $foreignCompany->id, 'latitude' => 0, 'longitude' => 0]);
    assignPunchLocationPolicy($company, $employee, PunchLocationMode::GeofenceRequired);
    $this->travelTo('2026-10-07 09:00:00 Asia/Kolkata');
    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->post(locationPunchInUrl(), ['latitude' => 0, 'longitude' => 0])->assertSessionHasErrors(['location']);

    expect(AttendancePunch::query()->count())->toBe(0);
});

test('company HR can configure a worksite through the scoped management routes', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create();
    CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $user->id, 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($company);
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($company->id);
    $user->assignRole('hr_admin');
    $registrar->setPermissionsTeamId(null);

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->post(route('companies.attendance-worksites.store', $company), [
            'name' => 'North Office', 'latitude' => 28.6139, 'longitude' => 77.2090, 'radius_meters' => 120,
        ])->assertRedirect(route('companies.attendance-worksites.index', $company));

    $this->assertDatabaseHas('attendance_worksites', [
        'company_id' => $company->id, 'name' => 'North Office', 'radius_meters' => 120, 'is_active' => true,
    ]);
});

test('worksite management route hides a worksite from another company', function () {
    [$company, $user] = locationPunchIdentity();
    $otherCompany = Company::factory()->create();
    $foreignWorksite = AttendanceWorksite::factory()->create(['company_id' => $otherCompany->id]);

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->get(route('companies.attendance-worksites.edit', [$company, $foreignWorksite]))->assertNotFound();
});

test('location requirements apply to both punch directions', function () {
    [$company, $user, $employee] = locationPunchIdentity();
    assignPunchLocationPolicy($company, $employee, PunchLocationMode::Required);
    $this->travelTo('2026-10-07 09:00:00 Asia/Kolkata');
    $this->actingAs($user)->withSession(['active_company_id' => $company->id]);

    $this->post(locationPunchInUrl(), ['latitude' => 28.6, 'longitude' => 77.2])->assertRedirect();
    $this->post(locationPunchOutUrl())->assertSessionHasErrors(['location']);
    $this->travelTo('2026-10-07 18:00:00 Asia/Kolkata');
    $this->post(locationPunchOutUrl(), ['latitude' => 28.6, 'longitude' => 77.2])->assertRedirect();

    expect($employee->attendancePunches()->count())->toBe(2);
});

test('HR cannot deactivate the only active worksite required by a geofence policy', function () {
    [$company, $user, $employee] = locationPunchIdentity();
    $worksite = AttendanceWorksite::factory()->create(['company_id' => $company->id]);
    assignPunchLocationPolicy($company, $employee, PunchLocationMode::GeofenceRequired, [$worksite]);
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($company->id);
    $user->assignRole('hr_admin');
    $registrar->setPermissionsTeamId(null);

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->patch(route('companies.attendance-worksites.deactivate', [$company, $worksite]))
        ->assertSessionHasErrors(['worksite']);

    expect($worksite->fresh()->is_active)->toBeTrue();
});

test('HR may deactivate a worksite when another linked active geofence worksite remains', function () {
    [$company, $user, $employee] = locationPunchIdentity();
    $worksite = AttendanceWorksite::factory()->create(['company_id' => $company->id]);
    $backup = AttendanceWorksite::factory()->create(['company_id' => $company->id]);
    assignPunchLocationPolicy($company, $employee, PunchLocationMode::GeofenceRequired, [$worksite, $backup]);
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($company->id);
    $user->assignRole('hr_admin');
    $registrar->setPermissionsTeamId(null);

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->patch(route('companies.attendance-worksites.deactivate', [$company, $worksite]))->assertRedirect();

    expect($worksite->fresh()->is_active)->toBeFalse()->and($backup->fresh()->is_active)->toBeTrue();
});

test('database composite keys reject attaching a cross-company worksite to an attendance policy', function () {
    [$company, , $employee] = locationPunchIdentity();
    $policy = assignPunchLocationPolicy($company, $employee, PunchLocationMode::GeofenceRequired);
    $foreignCompany = Company::factory()->create();
    $foreignWorksite = AttendanceWorksite::factory()->create(['company_id' => $foreignCompany->id]);

    expect(fn () => DB::table('attendance_policy_worksites')->insert([
        'company_id' => $company->id,
        'attendance_policy_id' => $policy->id,
        'attendance_worksite_id' => $foreignWorksite->id,
    ]))->toThrow(PDOException::class);
});
