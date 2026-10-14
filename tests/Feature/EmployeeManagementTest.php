<?php

use App\Actions\SeedCompanyRoles;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Department;
use App\Models\Designation;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** @return array{User, Company} */
function employeeModuleUser(string $role): array
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

/** @param array<string, mixed> $overrides */
function employeePayload(array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Maya', 'middle_name' => '', 'last_name' => 'Nair',
        'date_of_birth' => '1995-02-10', 'personal_email' => 'maya@example.test',
        'joining_date' => '2026-01-05', 'employment_type' => 'full_time', 'employment_status' => 'active',
    ], $overrides);
}

test('company members can manage branches, departments and designations inside their company', function () {
    [$hr, $company] = employeeModuleUser('hr_admin');

    $this->actingAs($hr)->get(route('branches.index'))->assertOk();
    $this->post(route('branches.store'), ['name' => 'Central', 'code' => 'CTR', 'city' => 'Pune', 'timezone' => 'UTC', 'status' => 'active', 'company_id' => 9999])
        ->assertRedirect(route('branches.index'));
    $this->assertDatabaseHas('branches', ['company_id' => $company->id, 'name' => 'Central', 'code' => 'CTR']);

    $this->post(route('departments.store'), ['name' => 'Engineering', 'code' => 'ENG', 'status' => 'active'])->assertRedirect(route('departments.index'));
    $this->post(route('designations.store'), ['name' => 'Developer', 'code' => 'DEV', 'level' => 2, 'status' => 'active'])->assertRedirect(route('designations.index'));
    $this->assertDatabaseHas('departments', ['company_id' => $company->id, 'code' => 'ENG']);
    $this->assertDatabaseHas('designations', ['company_id' => $company->id, 'code' => 'DEV', 'level' => 2]);
});

test('foreign company organizational records return 404 and cannot be updated', function () {
    [$hr] = employeeModuleUser('hr_admin');
    $otherCompany = Company::factory()->create();
    $branch = Branch::factory()->create(['company_id' => $otherCompany->id]);
    $department = Department::factory()->create(['company_id' => $otherCompany->id]);
    $designation = Designation::factory()->create(['company_id' => $otherCompany->id]);

    $this->actingAs($hr)->get(route('branches.edit', $branch))->assertNotFound();
    $this->put(route('branches.update', $branch), ['name' => 'Stolen', 'code' => 'STL', 'timezone' => 'UTC', 'status' => 'active'])->assertNotFound();
    $this->get(route('departments.edit', $department))->assertNotFound();
    $this->put(route('departments.update', $department), ['name' => 'Stolen', 'code' => 'STL', 'status' => 'active'])->assertNotFound();
    $this->get(route('designations.edit', $designation))->assertNotFound();
    $this->put(route('designations.update', $designation), ['name' => 'Stolen', 'code' => 'STL', 'status' => 'active'])->assertNotFound();
});

test('organizational codes and names are unique per company and reusable across companies', function () {
    [$hr, $company] = employeeModuleUser('hr_admin');
    $otherCompany = Company::factory()->create();
    $this->actingAs($hr)->post(route('branches.store'), ['name' => 'Central', 'code' => 'CTR', 'timezone' => 'UTC', 'status' => 'active'])->assertRedirect();
    $this->post(route('branches.store'), ['name' => 'Central', 'code' => 'CTR', 'timezone' => 'UTC', 'status' => 'active'])->assertSessionHasErrors(['name', 'code']);
    $otherCompany->branches()->create(['name' => 'Central', 'code' => 'CTR', 'timezone' => 'UTC', 'status' => 'active']);
    expect($company->branches()->count())->toBe(1)->and($otherCompany->branches()->count())->toBe(1);
});

test('employees are created with company scoped generated codes and can be updated and archived', function () {
    [$hr, $company] = employeeModuleUser('hr_admin');
    $branch = Branch::factory()->create(['company_id' => $company->id]);
    $department = Department::factory()->create(['company_id' => $company->id]);
    $designation = Designation::factory()->create(['company_id' => $company->id]);

    $this->actingAs($hr)->post(route('employees.store'), employeePayload([
        'branch_id' => $branch->id, 'department_id' => $department->id, 'designation_id' => $designation->id,
        'employee_code' => 'EVIL-001', 'company_id' => 9999,
    ]))->assertRedirect();
    $employee = $company->employees()->firstOrFail();
    expect($employee->employee_code)->toBe('EMP-000001')
        ->and($employee->branch_id)->toBe($branch->id)
        ->and($employee->department_id)->toBe($department->id);

    $this->put(route('employees.update', $employee), employeePayload(['first_name' => 'Mira']))->assertRedirect(route('employees.show', $employee));
    expect($employee->fresh()->first_name)->toBe('Mira');
    $this->delete(route('employees.destroy', $employee))->assertRedirect(route('employees.index'));
    expect($employee->fresh()->trashed())->toBeTrue();
});

test('employee listing supports search and company owned filters', function () {
    [$hr, $company] = employeeModuleUser('hr_admin');
    $department = Department::factory()->create(['company_id' => $company->id]);
    $match = $company->employees()->create(array_merge(employeePayload(), ['employee_code' => 'EMP-000042', 'department_id' => $department->id]));
    $company->employees()->create(array_merge(employeePayload(['first_name' => 'Alex']), ['employee_code' => 'EMP-000043']));

    $this->actingAs($hr)->get(route('employees.index', ['search' => $match->employee_code, 'department_id' => $department->id]))
        ->assertOk()->assertInertia(fn ($page) => $page->component('employees/index', false)->has('employees.data', 1)->where('employees.data.0.id', $match->id));
});

test('employees cannot access another company employee profile, update or archive it', function () {
    [$hr] = employeeModuleUser('hr_admin');
    $otherCompany = Company::factory()->create();
    $employee = $otherCompany->employees()->create(array_merge(employeePayload(), ['employee_code' => 'EMP-000001']));

    $this->actingAs($hr)->get(route('employees.show', $employee))->assertNotFound();
    $this->put(route('employees.update', $employee), employeePayload(['first_name' => 'Changed']))->assertNotFound();
    $this->delete(route('employees.destroy', $employee))->assertNotFound();
    expect($employee->fresh()->first_name)->toBe('Maya')->and($employee->fresh()->trashed())->toBeFalse();
});

test('employee assignment rejects branch department designation manager and user from another company', function () {
    [$hr, $company] = employeeModuleUser('hr_admin');
    $foreignCompany = Company::factory()->create();
    $foreignBranch = Branch::factory()->create(['company_id' => $foreignCompany->id]);
    $foreignDepartment = Department::factory()->create(['company_id' => $foreignCompany->id]);
    $foreignDesignation = Designation::factory()->create(['company_id' => $foreignCompany->id]);
    $foreignManager = $foreignCompany->employees()->create(array_merge(employeePayload(), ['employee_code' => 'EMP-000001']));
    $foreignUser = User::factory()->create();
    CompanyUser::create(['company_id' => $foreignCompany->id, 'user_id' => $foreignUser->id, 'status' => 'active']);
    $this->actingAs($hr);

    foreach ([
        ['branch_id', $foreignBranch->id],
        ['department_id', $foreignDepartment->id],
        ['designation_id', $foreignDesignation->id],
        ['manager_id', $foreignManager->id],
        ['user_id', $foreignUser->id],
    ] as [$field, $value]) {
        $this->post(route('employees.store'), employeePayload([$field => $value]))->assertSessionHasErrors($field);
    }

    expect($company->employees()->count())->toBe(0);
});

test('employee cannot be assigned as their own manager and reporting cycles are rejected', function () {
    [$hr, $company] = employeeModuleUser('hr_admin');
    $first = $company->employees()->create(array_merge(employeePayload(), ['employee_code' => 'EMP-000001']));
    $second = $company->employees()->create(array_merge(employeePayload(['first_name' => 'Arun']), ['employee_code' => 'EMP-000002', 'manager_id' => $first->id]));

    $this->actingAs($hr)->put(route('employees.update', $first), employeePayload(['manager_id' => $first->id]))->assertSessionHasErrors('manager_id');
    $this->put(route('employees.update', $first), employeePayload(['manager_id' => $second->id]))->assertSessionHasErrors('manager_id');
});

test('duplicate employee codes are rejected within a company and allowed across companies', function () {
    [, $company] = employeeModuleUser('hr_admin');
    $otherCompany = Company::factory()->create();
    $company->employees()->create(array_merge(employeePayload(), ['employee_code' => 'EMP-000001']));
    expect(fn () => $company->employees()->create(array_merge(employeePayload(), ['employee_code' => 'EMP-000001'])))->toThrow(QueryException::class);
    $otherCompany->employees()->create(array_merge(employeePayload(), ['employee_code' => 'EMP-000001']));
    expect($otherCompany->employees()->where('employee_code', 'EMP-000001')->exists())->toBeTrue();
});

test('role permissions limit employee management to HR and owners while managers see team members only', function () {
    [$employeeUser] = employeeModuleUser('employee');
    [$managerUser, $managerCompany] = employeeModuleUser('manager');
    [$hrManagerUser] = employeeModuleUser('hr_manager');
    [$ownerUser] = employeeModuleUser('company_owner');
    $manager = $managerCompany->employees()->create(array_merge(employeePayload(), ['employee_code' => 'EMP-000001', 'user_id' => $managerUser->id]));
    $report = $managerCompany->employees()->create(array_merge(employeePayload(['first_name' => 'Report']), ['employee_code' => 'EMP-000002', 'manager_id' => $manager->id]));

    $this->actingAs($employeeUser)->get(route('employees.index'))->assertForbidden();
    $this->actingAs($employeeUser)->post(route('employees.store'), employeePayload())->assertForbidden();
    $this->actingAs($managerUser)->get(route('employees.index'))->assertOk()->assertInertia(fn ($page) => $page->has('employees.data', 1)->where('employees.data.0.id', $report->id));
    $this->actingAs($managerUser)->get(route('employees.show', $report))->assertOk();
    $this->actingAs($managerUser)->post(route('employees.store'), employeePayload())->assertForbidden();
    $this->actingAs($hrManagerUser)->post(route('employees.store'), employeePayload())->assertRedirect();
    $this->actingAs($ownerUser)->post(route('branches.store'), ['name' => 'HQ', 'code' => 'HQ', 'timezone' => 'UTC', 'status' => 'active'])->assertRedirect();
});

test('employees can see only their own profile and cannot manage organizational data', function () {
    [$employeeUser, $company] = employeeModuleUser('employee');
    $own = $company->employees()->create(array_merge(employeePayload(), ['employee_code' => 'EMP-000001', 'user_id' => $employeeUser->id]));
    $other = $company->employees()->create(array_merge(employeePayload(['first_name' => 'Other']), ['employee_code' => 'EMP-000002']));

    $this->actingAs($employeeUser)->get(route('employees.show', $own))->assertOk();
    $this->get(route('employees.show', $other))->assertForbidden();
    $this->get(route('departments.index'))->assertForbidden();
});

test('super admins can manage records after selecting a company and unauthenticated users are redirected', function () {
    $company = Company::factory()->create();
    app(RolePermissionSeeder::class)->run();
    $admin = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId(User::SYSTEM_TEAM_ID);
    $admin->assignRole(Role::query()->where('team_id', User::SYSTEM_TEAM_ID)->where('name', 'super_admin')->firstOrFail());
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $this->actingAs($admin)->get(route('companies.show', $company))->assertOk();
    $this->get(route('employees.index'))->assertOk();
    auth()->logout();
    $this->get(route('employees.index'))->assertRedirect('/login');
});
