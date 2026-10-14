<?php

use App\Actions\Employees\LinkEmployeeToUser;
use App\Actions\SeedCompanyRoles;
use App\Exceptions\Employees\EmployeeIdentityException;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\QueryException;
use Spatie\Permission\PermissionRegistrar;

/** @return array{Company, User} */
function employeeIdentityCompanyActor(string $role = 'hr_admin'): array
{
    $company = Company::factory()->create();
    $actor = User::factory()->create();
    CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($company);

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($company->id);
    $actor->assignRole($role);
    $registrar->setPermissionsTeamId(null);

    return [$company, $actor];
}

function makeEmployeeIdentityMember(Company $company): User
{
    $user = User::factory()->create();
    CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $user->id, 'status' => 'active']);

    return $user;
}

test('authorized HR explicitly links an existing employee and company member without changing identity data', function () {
    [$company, $actor] = employeeIdentityCompanyActor();
    $employee = Employee::factory()->create(['company_id' => $company->id, 'personal_email' => 'person@example.test']);
    $user = makeEmployeeIdentityMember($company);
    $userEmail = $user->email;
    $passwordHash = $user->password;
    $employeeEmail = $employee->personal_email;

    $linked = app(LinkEmployeeToUser::class)->execute($company, $employee, $user, $actor);

    expect($linked->user_id)->toBe($user->id)
        ->and($employee->fresh()->user()->is($user))->toBeTrue()
        ->and($user->fresh()->employees()->where('company_id', $company->id)->whereKey($employee->id)->exists())->toBeTrue()
        ->and($user->fresh()->email)->toBe($userEmail)
        ->and($user->fresh()->password)->toBe($passwordHash)
        ->and($employee->fresh()->personal_email)->toBe($employeeEmail);
});

test('same employee and user link retry is idempotent', function () {
    [$company, $actor] = employeeIdentityCompanyActor();
    $employee = Employee::factory()->create(['company_id' => $company->id]);
    $user = makeEmployeeIdentityMember($company);
    $first = app(LinkEmployeeToUser::class)->execute($company, $employee, $user, $actor);
    $updatedAt = $first->updated_at->toDateTimeString();

    $second = app(LinkEmployeeToUser::class)->execute($company, $employee->fresh(), $user, $actor);

    expect($second->user_id)->toBe($user->id)
        ->and($second->updated_at->toDateTimeString())->toBe($updatedAt)
        ->and($company->employees()->where('user_id', $user->id)->count())->toBe(1);
});

test('link action rejects a different user for an already linked employee', function () {
    [$company, $actor] = employeeIdentityCompanyActor();
    $employee = Employee::factory()->create(['company_id' => $company->id]);
    $firstUser = makeEmployeeIdentityMember($company);
    $secondUser = makeEmployeeIdentityMember($company);
    app(LinkEmployeeToUser::class)->execute($company, $employee, $firstUser, $actor);

    try {
        app(LinkEmployeeToUser::class)->execute($company, $employee->fresh(), $secondUser, $actor);
        test()->fail('Expected the existing employee identity link to remain fixed.');
    } catch (EmployeeIdentityException $exception) {
        expect($exception->reason)->toBe('EMPLOYEE_ALREADY_LINKED');
    }
});

test('link action rejects a user already linked to another employee in the same company', function () {
    [$company, $actor] = employeeIdentityCompanyActor();
    $firstEmployee = Employee::factory()->create(['company_id' => $company->id]);
    $secondEmployee = Employee::factory()->create(['company_id' => $company->id]);
    $user = makeEmployeeIdentityMember($company);
    app(LinkEmployeeToUser::class)->execute($company, $firstEmployee, $user, $actor);

    try {
        app(LinkEmployeeToUser::class)->execute($company, $secondEmployee, $user, $actor);
        test()->fail('Expected one employee per company and user.');
    } catch (EmployeeIdentityException $exception) {
        expect($exception->reason)->toBe('USER_ALREADY_LINKED_IN_COMPANY');
    }
});

test('one user may be linked to an employee in each of two companies', function () {
    [$firstCompany, $firstActor] = employeeIdentityCompanyActor();
    [$secondCompany, $secondActor] = employeeIdentityCompanyActor();
    $user = makeEmployeeIdentityMember($firstCompany);
    CompanyUser::query()->create(['company_id' => $secondCompany->id, 'user_id' => $user->id, 'status' => 'active']);
    $firstEmployee = Employee::factory()->create(['company_id' => $firstCompany->id]);
    $secondEmployee = Employee::factory()->create(['company_id' => $secondCompany->id]);

    app(LinkEmployeeToUser::class)->execute($firstCompany, $firstEmployee, $user, $firstActor);
    app(LinkEmployeeToUser::class)->execute($secondCompany, $secondEmployee, $user, $secondActor);

    expect($firstEmployee->fresh()->user_id)->toBe($user->id)
        ->and($secondEmployee->fresh()->user_id)->toBe($user->id);
});

test('link action rejects cross-company employees and users without active membership', function () {
    [$company, $actor] = employeeIdentityCompanyActor();
    $foreignCompany = Company::factory()->create();
    $employee = Employee::factory()->create(['company_id' => $foreignCompany->id]);
    $user = User::factory()->create();

    try {
        app(LinkEmployeeToUser::class)->execute($company, $employee, $user, $actor);
        test()->fail('Expected a cross-company employee to be rejected.');
    } catch (EmployeeIdentityException $exception) {
        expect($exception->reason)->toBe('EMPLOYEE_COMPANY_MISMATCH');
    }

    $employee->delete();
    $localEmployee = Employee::factory()->create(['company_id' => $company->id]);

    try {
        app(LinkEmployeeToUser::class)->execute($company, $localEmployee, $user, $actor);
        test()->fail('Expected a user without company membership to be rejected.');
    } catch (EmployeeIdentityException $exception) {
        expect($exception->reason)->toBe('USER_NOT_ACTIVE_COMPANY_MEMBER');
    }
});

test('link action requires an authorized company actor', function () {
    [$company] = employeeIdentityCompanyActor();
    $ordinaryUser = makeEmployeeIdentityMember($company);
    $employee = Employee::factory()->create(['company_id' => $company->id]);
    $target = makeEmployeeIdentityMember($company);

    try {
        app(LinkEmployeeToUser::class)->execute($company, $employee, $target, $ordinaryUser);
        test()->fail('Expected an actor without employee update permission to be rejected.');
    } catch (EmployeeIdentityException $exception) {
        expect($exception->reason)->toBe('LINK_ACTOR_NOT_AUTHORIZED');
    }
});

test('employee creation and update requests cannot set or replace user identity', function () {
    [$company, $actor] = employeeIdentityCompanyActor();
    $user = makeEmployeeIdentityMember($company);
    $employee = Employee::factory()->create(['company_id' => $company->id]);

    $this->actingAs($actor)
        ->post(route('employees.store'), [
            'first_name' => 'New', 'last_name' => 'Person', 'joining_date' => '2026-01-01',
            'employment_type' => 'full_time', 'employment_status' => 'active', 'user_id' => $user->id,
        ])
        ->assertSessionHasErrors('user_id');

    $this->put(route('employees.update', $employee), [
        'first_name' => 'Updated', 'last_name' => 'Person', 'joining_date' => '2026-01-01',
        'employment_type' => 'full_time', 'employment_status' => 'active', 'user_id' => $user->id,
    ])->assertSessionHasErrors('user_id');

    expect($company->employees()->where('user_id', $user->id)->exists())->toBeFalse()
        ->and($employee->fresh()->user_id)->toBeNull();
});

test('company and user unique constraint and nullable identity foreign key are enforced by the database', function () {
    [$company] = employeeIdentityCompanyActor();
    $first = Employee::factory()->create(['company_id' => $company->id]);
    $second = Employee::factory()->create(['company_id' => $company->id]);
    $user = makeEmployeeIdentityMember($company);
    DB::table('employees')->where('id', $first->id)->update(['user_id' => $user->id]);

    expect(fn () => DB::table('employees')->where('id', $second->id)->update(['user_id' => $user->id]))
        ->toThrow(QueryException::class);

    expect(fn () => DB::table('employees')->where('id', $second->id)->update(['user_id' => 999999]))
        ->toThrow(QueryException::class);
});

test('multiple unlinked employees are allowed and deleting a user preserves employee history', function () {
    [$company] = employeeIdentityCompanyActor();
    $employees = Employee::factory()->count(2)->create(['company_id' => $company->id]);
    $user = makeEmployeeIdentityMember($company);
    DB::table('employees')->where('id', $employees[0]->id)->update(['user_id' => $user->id]);

    $user->delete();

    expect(Employee::query()->find($employees[0]->id))->not->toBeNull()
        ->and($employees[0]->fresh()->user_id)->toBeNull()
        ->and($employees[1]->fresh()->user_id)->toBeNull();
});
