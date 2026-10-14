<?php

use App\Actions\SeedCompanyRoles;
use App\Enums\ShiftStatus;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Shift;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** @return array{User, Company} */
function shiftCompanyUser(string $role): array
{
    $user = User::factory()->create();
    $company = Company::factory()->create();
    CompanyUser::create(['company_id' => $company->id, 'user_id' => $user->id, 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($company);
    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    return [$user, $company];
}

/** @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function shiftPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'General Shift',
        'code' => 'GEN',
        'start_time' => '09:00',
        'end_time' => '18:00',
        'break_duration_minutes' => 60,
        'grace_period_minutes' => 15,
        'status' => 'active',
        'description' => 'Standard daytime hours',
    ], $overrides);
}

test('authorized company users can create a normal shift with derived duration settings', function () {
    [$owner, $company] = shiftCompanyUser('company_owner');

    $this->actingAs($owner)->post(route('companies.shifts.store', $company), shiftPayload())
        ->assertRedirect(route('companies.shifts.index', $company));

    $shift = $company->shifts()->firstOrFail();
    expect($shift->is_overnight)->toBeFalse()
        ->and($shift->duration_minutes)->toBe(540)
        ->and($shift->net_duration_minutes)->toBe(480)
        ->and($shift->status)->toBe(ShiftStatus::Active);
});

test('overnight shifts are accepted and calculate scheduled duration across midnight', function () {
    [$owner, $company] = shiftCompanyUser('company_owner');

    $this->actingAs($owner)->post(route('companies.shifts.store', $company), shiftPayload([
        'name' => 'Night Shift', 'code' => 'NIGHT', 'start_time' => '22:00', 'end_time' => '06:00', 'break_duration_minutes' => 30,
    ]))->assertRedirect(route('companies.shifts.index', $company));

    $shift = $company->shifts()->firstOrFail();
    expect($shift->is_overnight)->toBeTrue()
        ->and($shift->duration_minutes)->toBe(480)
        ->and($shift->net_duration_minutes)->toBe(450);
});

test('equal shift start and end times are rejected', function () {
    [$owner, $company] = shiftCompanyUser('company_owner');

    $this->actingAs($owner)->post(route('companies.shifts.store', $company), shiftPayload(['start_time' => '09:00', 'end_time' => '09:00']))
        ->assertSessionHasErrors('end_time');

    expect($company->shifts()->exists())->toBeFalse();
});

test('break duration cannot exceed a normal or overnight shift duration', function () {
    [$owner, $company] = shiftCompanyUser('company_owner');

    $this->actingAs($owner)->post(route('companies.shifts.store', $company), shiftPayload(['break_duration_minutes' => 541]))
        ->assertSessionHasErrors('break_duration_minutes');
    $this->post(route('companies.shifts.store', $company), shiftPayload([
        'name' => 'Night Shift', 'code' => 'NIGHT', 'start_time' => '22:00', 'end_time' => '06:00', 'break_duration_minutes' => 481,
    ]))->assertSessionHasErrors('break_duration_minutes');

    expect($company->shifts()->exists())->toBeFalse();
});

test('negative break duration and grace period values are rejected', function () {
    [$owner, $company] = shiftCompanyUser('company_owner');

    $this->actingAs($owner)->post(route('companies.shifts.store', $company), shiftPayload(['break_duration_minutes' => -1]))
        ->assertSessionHasErrors('break_duration_minutes');
    $this->post(route('companies.shifts.store', $company), shiftPayload(['grace_period_minutes' => -1]))
        ->assertSessionHasErrors('grace_period_minutes');
});

test('grace periods above the PostgreSQL smallint range are stored as integers', function () {
    [$owner, $company] = shiftCompanyUser('company_owner');

    $this->actingAs($owner)->post(route('companies.shifts.store', $company), shiftPayload(['grace_period_minutes' => 32768]))
        ->assertRedirect();

    expect($company->shifts()->firstOrFail()->grace_period_minutes)->toBe(32768);
});

test('shift names and codes are unique per company but may repeat in another company', function () {
    [$owner, $company] = shiftCompanyUser('company_owner');
    $otherCompany = Company::factory()->create();

    $this->actingAs($owner)->post(route('companies.shifts.store', $company), shiftPayload())->assertRedirect();
    $this->post(route('companies.shifts.store', $company), shiftPayload(['name' => 'Second Shift']))
        ->assertSessionHasErrors('code');
    $this->post(route('companies.shifts.store', $company), shiftPayload(['code' => 'SECOND']))
        ->assertSessionHasErrors('name');
    $otherCompany->shifts()->create(shiftPayload());

    expect($company->shifts()->count())->toBe(1)->and($otherCompany->shifts()->count())->toBe(1);
});

test('the company selected in the route owns the shift and client company ids are rejected', function () {
    [$owner, $company] = shiftCompanyUser('company_owner');
    $otherCompany = Company::factory()->create();

    $this->actingAs($owner)->post(route('companies.shifts.store', $company), shiftPayload(['company_id' => $otherCompany->id]))
        ->assertSessionHasErrors('company_id');

    expect($company->shifts()->exists())->toBeFalse()
        ->and($otherCompany->shifts()->exists())->toBeFalse();
});

test('company members can update their own shifts and cannot update or delete another company shift', function () {
    [$owner, $company] = shiftCompanyUser('company_owner');
    $ownShift = $company->shifts()->create(shiftPayload());
    $otherCompany = Company::factory()->create();
    $foreignShift = $otherCompany->shifts()->create(shiftPayload(['code' => 'GEN2']));

    $this->actingAs($owner)->put(route('companies.shifts.update', [$company, $ownShift]), shiftPayload(['name' => 'Updated Shift']))
        ->assertRedirect(route('companies.shifts.index', $company));
    expect($ownShift->fresh()->name)->toBe('Updated Shift');

    $this->put(route('companies.shifts.update', [$company, $foreignShift]), shiftPayload(['name' => 'Stolen']))->assertNotFound();
    $this->delete(route('companies.shifts.destroy', [$company, $foreignShift]))->assertNotFound();
    expect($foreignShift->fresh())->not->toBeNull();
});

test('users cannot open another company shift routes', function () {
    [$owner] = shiftCompanyUser('company_owner');
    $otherCompany = Company::factory()->create();

    $this->actingAs($owner)->get(route('companies.shifts.index', $otherCompany))->assertNotFound();
});

test('managers may view shifts while employees and accountants cannot administer them', function () {
    [$manager, $managerCompany] = shiftCompanyUser('manager');
    [$employee, $employeeCompany] = shiftCompanyUser('employee');
    [$accountant, $accountantCompany] = shiftCompanyUser('accountant');
    $managerCompany->shifts()->create(shiftPayload());

    $this->actingAs($manager)->get(route('companies.shifts.index', $managerCompany))->assertOk();
    $this->post(route('companies.shifts.store', $managerCompany), shiftPayload(['code' => 'NEW']))->assertForbidden();
    $this->actingAs($employee)->get(route('companies.shifts.index', $employeeCompany))->assertForbidden();
    $this->actingAs($accountant)->get(route('companies.shifts.index', $accountantCompany))->assertForbidden();
});

test('HR managers can create and update shifts but cannot delete or change their status', function () {
    [$hrManager, $company] = shiftCompanyUser('hr_manager');
    $shift = $company->shifts()->create(shiftPayload());

    $this->actingAs($hrManager)->post(route('companies.shifts.store', $company), shiftPayload(['code' => 'EARLY']))->assertRedirect();
    $this->put(route('companies.shifts.update', [$company, $shift]), shiftPayload(['name' => 'Updated General']))->assertRedirect();
    $this->patch(route('companies.shifts.deactivate', [$company, $shift]))->assertForbidden();
    $this->delete(route('companies.shifts.destroy', [$company, $shift]))->assertForbidden();
});

test('authorized users can activate and deactivate a shift without deleting it', function () {
    [$owner, $company] = shiftCompanyUser('company_owner');
    $shift = $company->shifts()->create(shiftPayload(['status' => 'inactive']));

    $this->actingAs($owner)->get(route('companies.shifts.index', ['company' => $company, 'status' => 'inactive']))
        ->assertInertia(fn (Assert $page) => $page->component('shifts/index', false)->has('shifts.data', 1)->where('shifts.data.0.status', 'inactive'));
    $this->patch(route('companies.shifts.activate', [$company, $shift]))->assertRedirect();
    expect($shift->fresh()->status)->toBe(ShiftStatus::Active);
    $this->patch(route('companies.shifts.deactivate', [$company, $shift]))->assertRedirect();
    expect($shift->fresh()->status)->toBe(ShiftStatus::Inactive);
});

test('shift list is permission protected and displays paginated shift details', function () {
    [$hrAdmin, $company] = shiftCompanyUser('hr_admin');
    $company->shifts()->create(shiftPayload());

    $this->actingAs($hrAdmin)->get(route('companies.shifts.index', $company))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('shifts/index', false)
            ->has('shifts.data', 1)
            ->where('shifts.data.0.name', 'General Shift')
            ->where('shifts.data.0.start_time', '09:00')
            ->where('shifts.data.0.duration_minutes', 540)
            ->where('company.timezone', 'UTC'));
});

test('super admins may access a company shift route without a company membership', function () {
    $company = Company::factory()->create();
    $company->shifts()->create(shiftPayload());
    app(RolePermissionSeeder::class)->run();
    $superAdmin = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId(User::SYSTEM_TEAM_ID);
    $superAdmin->assignRole(Role::query()->where('team_id', User::SYSTEM_TEAM_ID)->where('name', 'super_admin')->firstOrFail());
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $this->actingAs($superAdmin)->get(route('companies.shifts.index', $company))->assertOk();
});

test('authorized company users can delete an unreferenced shift', function () {
    [$owner, $company] = shiftCompanyUser('company_owner');
    $shift = $company->shifts()->create(shiftPayload());

    $this->actingAs($owner)->delete(route('companies.shifts.destroy', [$company, $shift]))
        ->assertRedirect(route('companies.shifts.index', $company));

    expect(Shift::query()->find($shift->id))->toBeNull();
});
