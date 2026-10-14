<?php

use App\Actions\SeedCompanyRoles;
use App\Enums\AttendancePolicyStatus;
use App\Models\AttendancePolicy;
use App\Models\AttendanceWorksite;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** @return array{User, Company} */
function attendancePolicyUser(string $role): array
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
function attendancePolicyPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Standard Attendance',
        'code' => 'STD-ATT',
        'description' => 'Standard attendance thresholds',
        'status' => 'active',
        'grace_in_minutes' => 5,
        'grace_out_minutes' => 5,
        'late_after_minutes' => 15,
        'early_leave_after_minutes' => 15,
        'half_day_before_minutes' => 240,
        'absent_before_minutes' => 480,
        'overtime_enabled' => false,
        'overtime_after_minutes' => 30,
    ], $overrides);
}

function attendancePolicyRecord(Company $company, array $overrides = []): AttendancePolicy
{
    return $company->attendancePolicies()->create(array_merge([
        'name' => 'Standard Attendance',
        'code' => 'STD-ATT',
        'description' => null,
    ], $overrides));
}

test('company HR can create a policy with audit attribution and neutral defaults', function () {
    [$hr, $company] = attendancePolicyUser('hr_admin');

    $this->actingAs($hr)->post(route('companies.attendance-policies.store', $company), [
        'name' => 'Neutral Policy', 'code' => 'NEUTRAL',
    ])->assertRedirect(route('companies.attendance-policies.index', $company));

    $policy = $company->attendancePolicies()->firstOrFail();
    expect($policy->status)->toBe(AttendancePolicyStatus::Active)
        ->and($policy->created_by)->toBe($hr->id)
        ->and($policy->updated_by)->toBe($hr->id)
        ->and($policy->grace_in_minutes)->toBe(0)
        ->and($policy->overtime_enabled)->toBeFalse()
        ->and($policy->overtime_after_minutes)->toBe(0)
        ->and($policy->grace_out_minutes)->toBe(0)
        ->and($policy->late_after_minutes)->toBe(0)
        ->and($policy->early_leave_after_minutes)->toBe(0)
        ->and($policy->half_day_before_minutes)->toBe(0)
        ->and($policy->absent_before_minutes)->toBe(0);
});

test('company HR can update and soft delete a policy', function () {
    [$owner, $company] = attendancePolicyUser('company_owner');
    $policy = attendancePolicyRecord($company);
    $this->actingAs($owner);

    $this->put(route('companies.attendance-policies.update', [$company, $policy]), attendancePolicyPayload(['name' => 'Updated Policy']))
        ->assertRedirect(route('companies.attendance-policies.index', $company));
    expect($policy->fresh()->name)->toBe('Updated Policy')
        ->and($policy->fresh()->updated_by)->toBe($owner->id);

    $this->delete(route('companies.attendance-policies.destroy', [$company, $policy]))->assertRedirect();
    expect(AttendancePolicy::query()->find($policy->id))->toBeNull()
        ->and(AttendancePolicy::withTrashed()->findOrFail($policy->id)->trashed())->toBeTrue();
});

test('policy names and codes are unique in a company but may repeat in another company', function () {
    [$owner, $company] = attendancePolicyUser('company_owner');
    $otherCompany = Company::factory()->create();
    attendancePolicyRecord($company);
    $this->actingAs($owner);

    $this->post(route('companies.attendance-policies.store', $company), attendancePolicyPayload())
        ->assertSessionHasErrors(['name', 'code']);
    $otherPolicy = attendancePolicyRecord($otherCompany);

    expect($otherPolicy->name)->toBe('Standard Attendance')
        ->and($otherPolicy->code)->toBe('STD-ATT');
});

test('tenant routes do not reveal or mutate another company policy', function () {
    [$owner, $company] = attendancePolicyUser('company_owner');
    $foreignCompany = Company::factory()->create();
    $foreignPolicy = attendancePolicyRecord($foreignCompany);
    $this->actingAs($owner);

    $this->get(route('companies.attendance-policies.index', $foreignCompany))->assertNotFound();
    $this->put(route('companies.attendance-policies.update', [$company, $foreignPolicy]), attendancePolicyPayload(['name' => 'Stolen']))->assertNotFound();
    $this->delete(route('companies.attendance-policies.destroy', [$company, $foreignPolicy]))->assertNotFound();
    $this->patch(route('companies.attendance-policies.deactivate', [$company, $foreignPolicy]))->assertNotFound();

    expect($foreignPolicy->fresh()->name)->toBe('Standard Attendance')
        ->and($foreignPolicy->fresh()->status)->toBe(AttendancePolicyStatus::Active);
});

test('company users cannot forge tenant or audit ownership fields', function () {
    [$owner, $company] = attendancePolicyUser('company_owner');
    $otherCompany = Company::factory()->create();

    $this->actingAs($owner)->post(route('companies.attendance-policies.store', $company), attendancePolicyPayload([
        'company_id' => $otherCompany->id, 'created_by' => 42, 'updated_by' => 42,
    ]))->assertSessionHasErrors(['company_id', 'created_by', 'updated_by']);

    expect($company->attendancePolicies()->exists())->toBeFalse()
        ->and($otherCompany->attendancePolicies()->exists())->toBeFalse();
});

test('minute values are bounded nonnegative integers and related thresholds are consistent', function () {
    [$owner, $company] = attendancePolicyUser('company_owner');
    $url = route('companies.attendance-policies.store', $company);
    $this->actingAs($owner);

    $this->post($url, attendancePolicyPayload(['grace_in_minutes' => -1]))->assertSessionHasErrors('grace_in_minutes');
    $this->post($url, attendancePolicyPayload(['grace_out_minutes' => 1441]))->assertSessionHasErrors('grace_out_minutes');
    $this->post($url, attendancePolicyPayload(['late_after_minutes' => 4]))->assertSessionHasErrors('late_after_minutes');
    $this->post($url, attendancePolicyPayload(['early_leave_after_minutes' => 4]))->assertSessionHasErrors('early_leave_after_minutes');
    $this->post($url, attendancePolicyPayload(['half_day_before_minutes' => '2.5']))->assertSessionHasErrors('half_day_before_minutes');
    $this->post($url, attendancePolicyPayload(['absent_before_minutes' => 1441]))->assertSessionHasErrors('absent_before_minutes');
    $this->post($url, attendancePolicyPayload(['overtime_after_minutes' => -1]))->assertSessionHasErrors('overtime_after_minutes');
    $this->post($url, attendancePolicyPayload(['overtime_enabled' => 'perhaps']))->assertSessionHasErrors('overtime_enabled');
    $this->post($url, attendancePolicyPayload(['status' => 'paused']))->assertSessionHasErrors('status');

    expect($company->attendancePolicies()->count())->toBe(0);
});

test('policy list supports search and status filtering and excludes soft deleted rows', function () {
    [$hr, $company] = attendancePolicyUser('hr_admin');
    attendancePolicyRecord($company);
    attendancePolicyRecord($company, ['name' => 'Inactive Policy', 'code' => 'INACTIVE', 'status' => AttendancePolicyStatus::Inactive]);
    attendancePolicyRecord($company, ['name' => 'Archived Policy', 'code' => 'ARCHIVE'])->delete();

    $this->actingAs($hr)->get(route('companies.attendance-policies.index', ['company' => $company, 'search' => 'STD', 'status' => 'active']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('attendance-policies/index', false)
            ->has('policies.data', 1)
            ->where('policies.data.0.code', 'STD-ATT'));

    expect(AttendancePolicy::withTrashed()->where('company_id', $company->id)->count())->toBe(3);
});

test('authorized company owners and HR admins can activate and deactivate policies', function () {
    foreach (['company_owner', 'hr_admin'] as $role) {
        [$user, $company] = attendancePolicyUser($role);
        $policy = attendancePolicyRecord($company, ['status' => AttendancePolicyStatus::Inactive]);
        $this->actingAs($user)->patch(route('companies.attendance-policies.activate', [$company, $policy]))->assertRedirect();
        expect($policy->fresh()->status)->toBe(AttendancePolicyStatus::Active);
        $this->patch(route('companies.attendance-policies.deactivate', [$company, $policy]))->assertRedirect();
        expect($policy->fresh()->status)->toBe(AttendancePolicyStatus::Inactive);
    }
});

test('role permissions follow the attendance policy access matrix', function () {
    foreach (['company_owner', 'hr_admin', 'hr_manager', 'manager', 'accountant', 'employee'] as $role) {
        [$user, $company] = attendancePolicyUser($role);
        $this->actingAs($user)->get(route('companies.attendance-policies.index', $company))
            ->assertStatus(in_array($role, ['accountant', 'employee'], true) ? 403 : 200);

        if ($role === 'manager') {
            $this->post(route('companies.attendance-policies.store', $company), attendancePolicyPayload())->assertForbidden();
            $policy = attendancePolicyRecord($company);
            $this->put(route('companies.attendance-policies.update', [$company, $policy]), attendancePolicyPayload(['name' => 'Manager Edit']))->assertForbidden();
            $this->delete(route('companies.attendance-policies.destroy', [$company, $policy]))->assertForbidden();
            $this->patch(route('companies.attendance-policies.deactivate', [$company, $policy]))->assertForbidden();
        }

        if ($role === 'hr_manager') {
            $this->post(route('companies.attendance-policies.store', $company), attendancePolicyPayload(['name' => 'HR Manager Created', 'code' => 'HRM-CREATED']))->assertRedirect();
            $policy = attendancePolicyRecord($company, ['name' => 'HR Manager Existing', 'code' => 'HRM-EXISTING']);
            $this->put(route('companies.attendance-policies.update', [$company, $policy]), attendancePolicyPayload(['name' => 'HR Manager Edit']))->assertRedirect();
            $this->put(route('companies.attendance-policies.update', [$company, $policy]), attendancePolicyPayload(['name' => 'HR Manager Status Edit', 'status' => 'inactive']))->assertForbidden();
            $this->delete(route('companies.attendance-policies.destroy', [$company, $policy]))->assertForbidden();
            $this->patch(route('companies.attendance-policies.deactivate', [$company, $policy]))->assertForbidden();
        }
    }
});

test('the index paginates and searches only inside the selected company', function () {
    [$owner, $company] = attendancePolicyUser('company_owner');
    $otherCompany = Company::factory()->create();

    foreach (range(1, 22) as $number) {
        attendancePolicyRecord($company, ['name' => "Policy {$number}", 'code' => "POL-{$number}"]);
    }
    attendancePolicyRecord($otherCompany, ['name' => 'Foreign Policy', 'code' => 'FOREIGN']);

    $this->actingAs($owner)->get(route('companies.attendance-policies.index', $company))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('attendance-policies/index', false)
            ->has('policies.data', 20)
            ->where('policies.total', 22)
            ->where('policies.data.0.name', 'Policy 1'));
});

test('super admins may manage attendance policies without company membership', function () {
    $company = Company::factory()->create();
    attendancePolicyRecord($company);
    app(RolePermissionSeeder::class)->run();
    $superAdmin = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId(User::SYSTEM_TEAM_ID);
    $superAdmin->assignRole(Role::query()->where('team_id', User::SYSTEM_TEAM_ID)->where('name', 'super_admin')->firstOrFail());
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $this->actingAs($superAdmin)->get(route('companies.attendance-policies.index', $company))->assertOk();
});

test('seeding adds attendance policy permissions to the intended company roles', function () {
    [, $company] = attendancePolicyUser('hr_admin');
    app(SeedCompanyRoles::class)->handle($company);
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($company->id);

    expect(Role::query()->where('team_id', $company->id)->where('name', 'hr_admin')->firstOrFail()->hasPermissionTo('attendance_policy.activate'))->toBeTrue()
        ->and(Role::query()->where('team_id', $company->id)->where('name', 'hr_manager')->firstOrFail()->hasPermissionTo('attendance_policy.create'))->toBeTrue()
        ->and(Role::query()->where('team_id', $company->id)->where('name', 'manager')->firstOrFail()->hasPermissionTo('attendance_policy.view'))->toBeTrue()
        ->and(Role::query()->where('team_id', $company->id)->where('name', 'manager')->firstOrFail()->hasPermissionTo('attendance_policy.create'))->toBeFalse()
        ->and(Role::query()->where('team_id', $company->id)->where('name', 'accountant')->firstOrFail()->hasPermissionTo('attendance_policy.view'))->toBeFalse();

    $registrar->setPermissionsTeamId(null);
});

test('geofence policy requires at least one active worksite from the same company', function () {
    [$owner, $company] = attendancePolicyUser('company_owner');
    $foreignCompany = Company::factory()->create();
    $foreignWorksite = AttendanceWorksite::factory()->create(['company_id' => $foreignCompany->id]);
    $payload = attendancePolicyPayload(['location_mode' => 'geofence_required', 'worksite_ids' => [$foreignWorksite->id]]);

    $this->actingAs($owner)->post(route('companies.attendance-policies.store', $company), $payload)
        ->assertSessionHasErrors(['worksite_ids.0']);

    expect($company->attendancePolicies()->count())->toBe(0);
});
