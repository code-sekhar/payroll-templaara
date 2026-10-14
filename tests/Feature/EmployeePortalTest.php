<?php

use App\Actions\SeedCompanyRoles;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\PermissionRegistrar;

/** @return array{Company, User} */
function employeePortalMember(string $role = 'employee', ?Company $company = null): array
{
    $company ??= Company::factory()->create();
    $user = User::factory()->create();
    CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $user->id, 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($company);
    employeePortalAssignRole($user, $company, $role);

    return [$company, $user];
}

function employeePortalAssignRole(User $user, Company $company, string $role): void
{
    $registrar = app(PermissionRegistrar::class);
    $previousTeamId = $registrar->getPermissionsTeamId();
    $registrar->setPermissionsTeamId($company->id);
    $user->unsetRelation('roles')->unsetRelation('permissions')->assignRole($role);
    $registrar->setPermissionsTeamId($previousTeamId);
}

function employeePortalLink(User $user, Company $company, array $attributes = []): Employee
{
    return Employee::factory()->create(array_merge([
        'company_id' => $company->id,
        'user_id' => $user->id,
        'first_name' => 'Asha',
        'middle_name' => null,
        'last_name' => 'Rao',
    ], $attributes));
}

test('guest is redirected to login from the employee portal', function () {
    $this->get(route('employee.portal'))->assertRedirect('/login');
});

test('linked employee with active membership and company employee role can view the portal', function () {
    [$company, $user] = employeePortalMember();
    employeePortalLink($user, $company);

    $this->actingAs($user)->get(route('employee.portal'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('employee-portal/index', false)
            ->where('employeePortal.employeeName', 'Asha Rao')
            ->where('employeePortal.companyName', $company->name)
            ->where('employeePortal.canAccessManagement', false)
            ->where('auth.user', null)
            ->where('activeCompany', null)
            ->where('companyAccess', false)
            ->where('companies', [])
        );
});

test('employee role without an explicit employee link cannot access the portal or match by email', function () {
    [$company, $user] = employeePortalMember();
    Employee::factory()->create(['company_id' => $company->id, 'personal_email' => $user->email]);

    $this->actingAs($user)->get(route('employee.portal'))->assertForbidden();
    $this->assertFalse($user->fresh()->employees()->exists());
});

test('archived employee identity cannot access the portal', function () {
    [$company, $user] = employeePortalMember();
    employeePortalLink($user, $company)->delete();

    $this->actingAs($user)->get(route('employee.portal'))->assertForbidden();
    $this->get(route('employee.profile.show'))->assertForbidden();
});

test('employee portal requires an active company membership', function () {
    [$company, $user] = employeePortalMember();
    employeePortalLink($user, $company);
    CompanyUser::query()->where('company_id', $company->id)->where('user_id', $user->id)->delete();

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->get(route('employee.portal'))->assertForbidden();
});

test('employee portal denies users without a selected active company', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('employee.portal'))->assertForbidden();
});

test('linked employee without company-scoped employee role cannot access the portal', function () {
    [$company, $user] = employeePortalMember('manager');
    employeePortalLink($user, $company);

    $this->actingAs($user)->get(route('employee.portal'))->assertForbidden();
});

test('employee portal resolves the identity belonging to the selected company only', function () {
    [$companyA, $user] = employeePortalMember();
    $employeeA = employeePortalLink($user, $companyA);
    $companyB = Company::factory()->create();
    CompanyUser::query()->create(['company_id' => $companyB->id, 'user_id' => $user->id, 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($companyB);
    employeePortalAssignRole($user, $companyB, 'employee');
    $employeeB = employeePortalLink($user, $companyB, ['first_name' => 'Mira', 'last_name' => 'Sen']);

    $this->actingAs($user)->withSession(['active_company_id' => $companyA->id])
        ->get(route('employee.portal'))
        ->assertInertia(fn ($page) => $page->where('employeePortal.employeeName', $employeeA->full_name));

    $this->withSession(['active_company_id' => $companyB->id])
        ->get(route('employee.portal'))
        ->assertInertia(fn ($page) => $page
            ->where('employeePortal.employeeName', $employeeB->full_name)
            ->where('employeePortal.companyName', $companyB->name));
});

test('switching companies through a company route refreshes the portal identity and scoped role context', function () {
    [$companyA, $user] = employeePortalMember('company_owner');
    employeePortalAssignRole($user, $companyA, 'employee');
    $employeeA = employeePortalLink($user, $companyA, ['employee_code' => 'COMPANY-A']);

    $companyB = Company::factory()->create();
    CompanyUser::query()->create(['company_id' => $companyB->id, 'user_id' => $user->id, 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($companyB);
    employeePortalAssignRole($user, $companyB, 'company_owner');
    employeePortalAssignRole($user, $companyB, 'employee');
    $employeeB = employeePortalLink($user, $companyB, ['employee_code' => 'COMPANY-B']);

    $this->actingAs($user)->withSession(['active_company_id' => $companyA->id])
        ->get(route('employee.portal'))
        ->assertInertia(fn ($page) => $page
            ->where('employeePortal.employee.employeeCode', 'COMPANY-A')
            ->where('employeePortal.canAccessManagement', true));

    $this->get(route('companies.show', $companyB))->assertOk();

    $this->get(route('employee.portal'))
        ->assertInertia(fn ($page) => $page
            ->where('employeePortal.employee.employeeCode', 'COMPANY-B')
            ->where('employeePortal.companyName', $companyB->name)
            ->where('employeePortal.canAccessManagement', true));

    expect($employeeA->company_id)->not->toBe($employeeB->company_id);
});

test('switching to a management-only company does not retain the previous employee identity', function () {
    [$companyA, $user] = employeePortalMember();
    employeePortalLink($user, $companyA, ['employee_code' => 'EMPLOYEE-COMPANY-A']);

    $companyB = Company::factory()->create();
    CompanyUser::query()->create(['company_id' => $companyB->id, 'user_id' => $user->id, 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($companyB);
    employeePortalAssignRole($user, $companyB, 'company_owner');

    $this->actingAs($user)->withSession(['active_company_id' => $companyA->id])
        ->get(route('employee.portal'))
        ->assertInertia(fn ($page) => $page->where('employeePortal.employee.employeeCode', 'EMPLOYEE-COMPANY-A'));

    $this->get(route('companies.show', $companyB))->assertOk();
    $this->get(route('employee.portal'))->assertForbidden();
    $this->get(route('employee.profile.show'))->assertForbidden();
    $this->get(route('dashboard'))->assertOk();
});

test('identity linked in one company does not grant portal access in another company', function () {
    [$companyA, $user] = employeePortalMember();
    employeePortalLink($user, $companyA);
    $companyB = Company::factory()->create();
    CompanyUser::query()->create(['company_id' => $companyB->id, 'user_id' => $user->id, 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($companyB);
    employeePortalAssignRole($user, $companyB, 'employee');

    $this->actingAs($user)->withSession(['active_company_id' => $companyB->id])
        ->get(route('employee.portal'))->assertForbidden();
});

test('employee role in one company does not grant portal access in a different active company', function () {
    [$companyA, $user] = employeePortalMember();
    employeePortalLink($user, $companyA);
    $companyB = Company::factory()->create();
    CompanyUser::query()->create(['company_id' => $companyB->id, 'user_id' => $user->id, 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($companyB);

    $this->actingAs($user)->withSession(['active_company_id' => $companyB->id])
        ->get(route('employee.portal'))->assertForbidden();
});

test('super admin without an explicit employee role and link cannot use the portal', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create();
    CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $user->id, 'status' => 'active']);
    app(RolePermissionSeeder::class)->run();
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId(User::SYSTEM_TEAM_ID);
    $user->assignRole('super_admin');
    $registrar->setPermissionsTeamId(null);

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->get(route('employee.portal'))->assertForbidden();
});

test('super admin with an explicit company employee identity can use the portal without exposing system role data', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create();
    CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $user->id, 'status' => 'active']);
    app(RolePermissionSeeder::class)->run();
    app(SeedCompanyRoles::class)->handle($company);
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId(User::SYSTEM_TEAM_ID);
    $user->assignRole('super_admin');
    $registrar->setPermissionsTeamId($company->id);
    $user->assignRole('employee');
    $registrar->setPermissionsTeamId(null);
    employeePortalLink($user, $company);

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->get(route('employee.portal'))
        ->assertInertia(fn ($page) => $page
            ->where('employeePortal.canAccessManagement', true)
            ->missing('auth.user.roles')
            ->missing('auth.user.permissions'));
});

test('employee-only user is sent to the portal after login and dashboard does not show management page', function () {
    [$company, $user] = employeePortalMember();
    employeePortalLink($user, $company);

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('employee.portal', absolute: false));

    $this->get(route('dashboard'))->assertRedirect(route('employee.portal'));
});

test('employee-only role without a valid identity is denied at dashboard without a portal redirect loop', function () {
    [, $user] = employeePortalMember();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));

    $this->get(route('dashboard'))->assertForbidden();
});

test('manager and accountant roles without employee identity retain dashboard access but not portal access', function (string $role) {
    [$company, $user] = employeePortalMember($role);

    $this->actingAs($user)->get(route('dashboard'))->assertOk();
    $this->get(route('employee.portal'))->assertForbidden();
})->with(['manager', 'accountant']);

test('management roles are not redirected and retain valid portal access when also linked as an employee', function (string $managementRole) {
    [$company, $user] = employeePortalMember($managementRole);
    employeePortalAssignRole($user, $company, 'employee');
    employeePortalLink($user, $company);

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));

    $this->get(route('employee.portal'))
        ->assertInertia(fn ($page) => $page->where('employeePortal.canAccessManagement', true));

    $this->get(route('dashboard'))->assertOk();
})->with(['company_owner', 'hr_admin', 'hr_manager', 'manager', 'accountant']);

test('management navigation advertises the portal only for a valid linked employee identity', function () {
    [$company, $user] = employeePortalMember('manager');
    employeePortalAssignRole($user, $company, 'employee');
    employeePortalLink($user, $company);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('employeePortalAccess', true));

    [, $unlinked] = employeePortalMember('accountant');
    $this->actingAs($unlinked)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('employeePortalAccess', false));
});

test('employee-only role in one company does not redirect when another company is active', function () {
    [$companyA, $user] = employeePortalMember();
    employeePortalLink($user, $companyA);
    $companyB = Company::factory()->create();
    CompanyUser::query()->create(['company_id' => $companyB->id, 'user_id' => $user->id, 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($companyB);
    employeePortalAssignRole($user, $companyB, 'accountant');

    $this->actingAs($user)->withSession(['active_company_id' => $companyB->id])
        ->get(route('dashboard'))->assertOk();
});

test('both employee portal routes deny role-only and identity-only accounts', function () {
    [$company, $roleOnlyUser] = employeePortalMember();
    employeePortalLink($roleOnlyUser, $company);
    $registrar = app(PermissionRegistrar::class);
    $previousTeamId = $registrar->getPermissionsTeamId();
    $registrar->setPermissionsTeamId($company->id);
    $roleOnlyUser->unsetRelation('roles')->unsetRelation('permissions')->syncRoles([]);
    $registrar->setPermissionsTeamId($previousTeamId);

    $this->actingAs($roleOnlyUser)->withSession(['active_company_id' => $company->id])
        ->get(route('employee.portal'))->assertForbidden();
    $this->get(route('employee.profile.show'))->assertForbidden();

    [$secondCompany, $identityOnlyUser] = employeePortalMember('manager');
    employeePortalLink($identityOnlyUser, $secondCompany);

    $this->actingAs($identityOnlyUser)->withSession(['active_company_id' => $secondCompany->id])
        ->get(route('employee.portal'))->assertForbidden();
    $this->get(route('employee.profile.show'))->assertForbidden();
});

test('both employee portal routes deny an inactive company context', function () {
    [$company, $user] = employeePortalMember();
    employeePortalLink($user, $company);
    $company->update(['status' => 'inactive']);

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->get(route('employee.portal'))->assertForbidden();
    $this->get(route('employee.profile.show'))->assertForbidden();
});

test('logout invalidates employee portal access on both direct routes', function () {
    [$company, $user] = employeePortalMember();
    employeePortalLink($user, $company);

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->post(route('logout'))
        ->assertRedirect('/');

    $this->get(route('employee.portal'))->assertRedirect('/login');
    $this->get(route('employee.profile.show'))->assertRedirect('/login');
});
