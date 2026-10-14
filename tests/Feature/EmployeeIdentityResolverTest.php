<?php

use App\Actions\SeedCompanyRoles;
use App\Exceptions\Employees\EmployeeIdentityException;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\User;
use App\Services\Employees\EmployeeIdentityResolver;
use Spatie\Permission\PermissionRegistrar;

function resolverCompanyMember(Company $company, ?string $role = null): User
{
    $user = User::factory()->create();
    CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $user->id, 'status' => 'active']);

    if ($role !== null) {
        app(SeedCompanyRoles::class)->handle($company);
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($company->id);
        $user->assignRole($role);
        $registrar->setPermissionsTeamId(null);
    }

    return $user;
}

test('resolver returns the linked employee only for an active member in the requested company', function () {
    $company = Company::factory()->create();
    $user = resolverCompanyMember($company);
    $employee = Employee::factory()->create(['company_id' => $company->id, 'user_id' => $user->id]);

    $resolved = app(EmployeeIdentityResolver::class)->resolve($user, $company);

    expect($resolved->is($employee))->toBeTrue();
});

test('resolver rejects missing links without matching by email or role', function () {
    $company = Company::factory()->create();
    $user = resolverCompanyMember($company, 'employee');
    Employee::factory()->create(['company_id' => $company->id, 'personal_email' => $user->email]);

    try {
        app(EmployeeIdentityResolver::class)->resolve($user, $company);
        test()->fail('Expected a missing explicit link to be rejected.');
    } catch (EmployeeIdentityException $exception) {
        expect($exception->reason)->toBe('EMPLOYEE_IDENTITY_NOT_LINKED');
    }
});

test('resolver rejects a linked user after company membership is deactivated or removed', function (string $membershipState) {
    $company = Company::factory()->create();
    $user = resolverCompanyMember($company);
    Employee::factory()->create(['company_id' => $company->id, 'user_id' => $user->id]);
    $membership = CompanyUser::query()->where('company_id', $company->id)->where('user_id', $user->id)->firstOrFail();

    if ($membershipState === 'removed') {
        $membership->delete();
    } else {
        $membership->update(['status' => 'inactive']);
    }

    try {
        app(EmployeeIdentityResolver::class)->resolve($user, $company);
        test()->fail('Expected inactive or missing membership to block identity resolution.');
    } catch (EmployeeIdentityException $exception) {
        expect($exception->reason)->toBe('USER_NOT_ACTIVE_COMPANY_MEMBER');
    }
})->with(['inactive', 'removed']);

test('same user linked in another company does not resolve across company boundaries', function () {
    $firstCompany = Company::factory()->create();
    $secondCompany = Company::factory()->create();
    $user = resolverCompanyMember($firstCompany);
    CompanyUser::query()->create(['company_id' => $secondCompany->id, 'user_id' => $user->id, 'status' => 'active']);
    $firstEmployee = Employee::factory()->create(['company_id' => $firstCompany->id, 'user_id' => $user->id]);
    $secondEmployee = Employee::factory()->create(['company_id' => $secondCompany->id]);

    try {
        app(EmployeeIdentityResolver::class)->resolve($user, $secondCompany);
        test()->fail('Expected the first company employee not to leak into the second company.');
    } catch (EmployeeIdentityException $exception) {
        expect($exception->reason)->toBe('EMPLOYEE_IDENTITY_NOT_LINKED');
    }

    expect(app(EmployeeIdentityResolver::class)->resolve($user, $firstCompany)->is($firstEmployee))->toBeTrue()
        ->and($secondEmployee->fresh()->user_id)->toBeNull();
});

test('resolver does not return a soft deleted employee identity', function () {
    $company = Company::factory()->create();
    $user = resolverCompanyMember($company);
    $employee = Employee::factory()->create(['company_id' => $company->id, 'user_id' => $user->id]);
    $employee->delete();

    try {
        app(EmployeeIdentityResolver::class)->resolve($user, $company);
        test()->fail('Expected an archived employee identity to be unavailable.');
    } catch (EmployeeIdentityException $exception) {
        expect($exception->reason)->toBe('EMPLOYEE_IDENTITY_NOT_LINKED');
    }
});
