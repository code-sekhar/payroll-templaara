<?php

use App\Actions\SeedCompanyRoles;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** @return array{User, Company} */
function createCompanyMembershipWithRole(string $roleName): array
{
    $user = User::factory()->create();
    $company = Company::factory()->create();
    CompanyUser::create(['company_id' => $company->id, 'user_id' => $user->id, 'status' => 'active']);

    if ($roleName === 'super_admin') {
        app(RolePermissionSeeder::class)->run();
        app(PermissionRegistrar::class)->setPermissionsTeamId(User::SYSTEM_TEAM_ID);
        $user->assignRole(Role::where('team_id', User::SYSTEM_TEAM_ID)->where('name', 'super_admin')->firstOrFail());

        return [$user, $company];
    }

    app(SeedCompanyRoles::class)->handle($company);
    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $user->assignRole($roleName);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    return [$user, $company];
}

test('super admins can view companies without company membership', function () {
    [$superAdmin, $company] = createCompanyMembershipWithRole('super_admin');
    $company->users()->detach($superAdmin);

    $this->actingAs($superAdmin)->get(route('companies.show', $company))->assertOk();
});

test('the super admin role can be granted to a verified account by console command', function () {
    $user = User::factory()->create();
    app(RolePermissionSeeder::class)->run();

    $this->artisan('app:grant-super-admin', ['email' => $user->email])
        ->assertSuccessful();

    expect($user->fresh()->isSuperAdmin())->toBeTrue();
});

test('company owners can view their company and receive 404 for another company', function () {
    [$owner, $company] = createCompanyMembershipWithRole('company_owner');
    $otherCompany = Company::factory()->create();

    $this->actingAs($owner)->get(route('companies.show', $company))->assertOk();
    $this->get(route('companies.show', $otherCompany))->assertNotFound();
});

test('company owners can view their scoped roles and permissions', function () {
    [$owner, $company] = createCompanyMembershipWithRole('company_owner');

    $this->actingAs($owner)->get(route('companies.roles', $company))->assertInertia(fn (Assert $page) => $page
        ->component('companies/roles', false)
        ->has('roles', 6)
    );
});

test('company members page returns the member role scoped to the selected company', function () {
    [$owner, $company] = createCompanyMembershipWithRole('company_owner');
    $member = User::factory()->create();
    CompanyUser::create(['company_id' => $company->id, 'user_id' => $member->id, 'status' => 'active']);
    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $member->assignRole('manager');

    $otherCompany = Company::factory()->create();
    CompanyUser::create(['company_id' => $otherCompany->id, 'user_id' => $member->id, 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($otherCompany);
    app(PermissionRegistrar::class)->setPermissionsTeamId($otherCompany->id);
    $member->assignRole('accountant');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $this->actingAs($owner)->get(route('companies.members', $company))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('companies/members', false)
            ->where('members.1.id', $member->id)
            ->where('members.1.role', 'manager')
        );
});

test('users with multiple memberships see only companies where they have company view permission', function () {
    [$user, $visibleCompany] = createCompanyMembershipWithRole('company_owner');
    $otherCompany = Company::factory()->create();
    CompanyUser::create(['company_id' => $otherCompany->id, 'user_id' => $user->id, 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($otherCompany);
    app(PermissionRegistrar::class)->setPermissionsTeamId($otherCompany->id);
    $user->assignRole('employee');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $this->actingAs($user)->get(route('companies.index'))->assertInertia(fn (Assert $page) => $page
        ->component('companies/index', false)
        ->has('companies', 1)
        ->where('companies.0.id', $visibleCompany->id)
    );
});

test('hr admins receive 404 when requesting another company', function () {
    [$hrAdmin] = createCompanyMembershipWithRole('hr_admin');
    $otherCompany = Company::factory()->create();

    $this->actingAs($hrAdmin)->get(route('companies.show', $otherCompany))->assertNotFound();
});

test('employees cannot view company member information or payroll administration permissions', function () {
    [$employee, $company] = createCompanyMembershipWithRole('employee');

    $this->actingAs($employee)->get(route('companies.members', $company))->assertForbidden();

    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    expect($employee->can('payroll.process'))->toBeFalse();
});

test('accountants have payroll processing permission', function () {
    [$accountant, $company] = createCompanyMembershipWithRole('accountant');
    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);

    expect($accountant->can('payroll.process'))->toBeTrue();
});

test('managers cannot update company settings or administer roles', function () {
    [$manager, $company] = createCompanyMembershipWithRole('manager');
    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);

    expect($manager->can('company.update'))->toBeFalse()
        ->and($manager->can('role.delete'))->toBeFalse()
        ->and($manager->can('system.admin'))->toBeFalse();
});

test('company owners cannot demote another company owner through member role updates', function () {
    [$owner, $company] = createCompanyMembershipWithRole('company_owner');
    $otherOwner = User::factory()->create();
    CompanyUser::create(['company_id' => $company->id, 'user_id' => $otherOwner->id, 'status' => 'active']);
    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $otherOwner->assignRole('company_owner');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $this->actingAs($owner)->patch(route('companies.members.update', [$company, $otherOwner]), ['role' => 'employee'])->assertForbidden();

    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    expect($otherOwner->hasRole('company_owner'))->toBeTrue();
});

test('company owners can assign an allowed role to an existing company member', function () {
    [$owner, $company] = createCompanyMembershipWithRole('company_owner');
    $member = User::factory()->create();
    CompanyUser::create(['company_id' => $company->id, 'user_id' => $member->id, 'status' => 'active']);
    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $member->assignRole('employee');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $this->actingAs($owner)->patch(route('companies.members.update', [$company, $member]), ['role' => 'accountant'])
        ->assertRedirect(route('companies.members', $company));

    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    expect($member->fresh()->hasRole('accountant'))->toBeTrue()
        ->and($member->fresh()->hasRole('employee'))->toBeFalse();
});

test('company owners can add an existing verified user to their company', function () {
    [$owner, $company] = createCompanyMembershipWithRole('company_owner');
    $newMember = User::factory()->create();

    $this->actingAs($owner)->post(route('companies.members.store', $company), [
        'email' => $newMember->email,
        'role' => 'accountant',
    ])->assertRedirect(route('companies.members', $company));

    $this->assertDatabaseHas('company_users', [
        'company_id' => $company->id,
        'user_id' => $newMember->id,
        'status' => 'active',
    ]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    expect($newMember->fresh()->hasRole('accountant'))->toBeTrue();
});

test('company owners cannot add unverified accounts as members', function () {
    [$owner, $company] = createCompanyMembershipWithRole('company_owner');
    $unverifiedUser = User::factory()->unverified()->create();

    $this->actingAs($owner)->post(route('companies.members.store', $company), [
        'email' => $unverifiedUser->email,
        'role' => 'employee',
    ])->assertSessionHasErrors('email');

    $this->assertDatabaseMissing('company_users', ['company_id' => $company->id, 'user_id' => $unverifiedUser->id]);
});

test('company owners cannot add an account twice to the same company', function () {
    [$owner, $company] = createCompanyMembershipWithRole('company_owner');
    $member = User::factory()->create();
    CompanyUser::create(['company_id' => $company->id, 'user_id' => $member->id, 'status' => 'active']);

    $this->actingAs($owner)->post(route('companies.members.store', $company), [
        'email' => $member->email,
        'role' => 'employee',
    ])->assertSessionHasErrors('email');

    $this->assertDatabaseCount('company_users', 2);
});

test('managers cannot assign company roles to members', function () {
    [$manager, $company] = createCompanyMembershipWithRole('manager');
    $member = User::factory()->create();
    CompanyUser::create(['company_id' => $company->id, 'user_id' => $member->id, 'status' => 'active']);
    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $member->assignRole('employee');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $this->actingAs($manager)->patch(route('companies.members.update', [$company, $member]), ['role' => 'accountant'])->assertForbidden();

    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    expect($member->hasRole('employee'))->toBeTrue();
});

test('unauthenticated users are redirected from company administration', function () {
    $company = Company::factory()->create();

    $this->get(route('companies.show', $company))->assertRedirect('/login');
});

test('duplicate company memberships are rejected by the database', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    CompanyUser::create(['company_id' => $company->id, 'user_id' => $user->id, 'status' => 'active']);

    expect(fn () => CompanyUser::create(['company_id' => $company->id, 'user_id' => $user->id, 'status' => 'active']))
        ->toThrow(QueryException::class);
});

test('role and permission seeding can be repeated without duplicate records', function () {
    $company = Company::factory()->create();
    $seeder = app(RolePermissionSeeder::class);
    $seeder->run();
    app(SeedCompanyRoles::class)->handle($company);
    $permissionCount = Permission::count();
    $roleCount = Role::count();

    $seeder->run();

    expect(Permission::count())->toBe($permissionCount)
        ->and(Role::count())->toBe($roleCount);
});
