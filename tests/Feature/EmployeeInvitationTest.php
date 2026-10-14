<?php

use App\Actions\Employees\IssueEmployeeInvitation;
use App\Actions\SeedCompanyRoles;
use App\Data\Employees\IssuedEmployeeInvitation;
use App\Exceptions\Employees\EmployeeInvitationException;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\EmployeeInvitation;
use App\Models\User;
use App\Services\Employees\EmployeeInvitationResolver;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/** @return array{Company, User} */
function ep2InvitationCompanyActor(string $role = 'hr_admin'): array
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

function issueInvitation(Company $company, Employee $employee, User $actor): IssuedEmployeeInvitation
{
    return app(IssueEmployeeInvitation::class)->execute($company, $employee, $actor);
}

test('owner and HR invitation permission holders can issue a secure email snapshot without creating or changing a user', function (string $role) {
    $this->freezeTime();
    [$company, $actor] = ep2InvitationCompanyActor($role);
    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'personal_email' => '  Rahul.Das@Example.com  ',
    ]);
    $existingUser = User::factory()->create(['email' => 'rahul.das@example.com']);
    $passwordHash = $existingUser->password;

    $issued = issueInvitation($company, $employee, $actor);

    expect($issued->invitation->email)->toBe('rahul.das@example.com')
        ->and($issued->invitation->company_id)->toBe($company->id)
        ->and($issued->invitation->employee_id)->toBe($employee->id)
        ->and($issued->invitation->invited_by)->toBe($actor->id)
        ->and($issued->invitation->token_hash)->not->toBe($issued->rawToken)
        ->and($issued->invitation->token_hash)->toBe(hash('sha256', $issued->rawToken))
        ->and($issued->invitation->expires_at->toDateTimeString())->toBe(now()->addHours(48)->toDateTimeString())
        ->and($issued->invitation->status()->value)->toBe('pending')
        ->and($employee->fresh()->user_id)->toBeNull()
        ->and($existingUser->fresh()->password)->toBe($passwordHash)
        ->and($existingUser->fresh()->email)->toBe('rahul.das@example.com')
        ->and($issued->invitation->toArray())->not->toHaveKey('token_hash');
})->with(['company_owner', 'hr_admin', 'hr_manager']);

test('invitation issuance allows an existing same-company user email without linking or mutating that user', function () {
    [$company, $actor] = ep2InvitationCompanyActor();
    $existingUser = User::factory()->create(['email' => 'person@example.test']);
    CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $existingUser->id, 'status' => 'active']);
    $employee = Employee::factory()->create(['company_id' => $company->id, 'personal_email' => 'PERSON@example.test']);
    $passwordHash = $existingUser->password;

    $issued = issueInvitation($company, $employee, $actor);

    expect($issued->invitation->email)->toBe('person@example.test')
        ->and($employee->fresh()->user_id)->toBeNull()
        ->and($existingUser->fresh()->password)->toBe($passwordHash)
        ->and($existingUser->fresh()->email)->toBe('person@example.test');
});

test('invitation issuance allows a same-email user in another company without linking across tenants', function () {
    [$company, $actor] = ep2InvitationCompanyActor();
    $otherCompany = Company::factory()->create();
    $existingUser = User::factory()->create(['email' => 'person@example.test']);
    CompanyUser::query()->create(['company_id' => $otherCompany->id, 'user_id' => $existingUser->id, 'status' => 'active']);
    $employee = Employee::factory()->create(['company_id' => $company->id, 'personal_email' => 'person@example.test']);

    $issued = issueInvitation($company, $employee, $actor);

    expect($issued->invitation->company_id)->toBe($company->id)
        ->and($employee->fresh()->user_id)->toBeNull()
        ->and($existingUser->fresh()->companies()->count())->toBe(1);
});

test('ordinary employee and cross-company actors cannot issue invitations', function () {
    [$company] = ep2InvitationCompanyActor();
    $employeeActor = User::factory()->create();
    CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $employeeActor->id, 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($company);
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($company->id);
    $employeeActor->assignRole('employee');
    $registrar->setPermissionsTeamId(null);
    $employee = Employee::factory()->create(['company_id' => $company->id, 'personal_email' => 'person@example.test']);

    expect(fn () => issueInvitation($company, $employee, $employeeActor))
        ->toThrow(EmployeeInvitationException::class);

    $foreignCompany = Company::factory()->create();
    $foreignActor = User::factory()->create();
    CompanyUser::query()->create(['company_id' => $foreignCompany->id, 'user_id' => $foreignActor->id, 'status' => 'active']);

    expect(fn () => issueInvitation($company, $employee, $foreignActor))
        ->toThrow(EmployeeInvitationException::class);

    expect(EmployeeInvitation::query()->count())->toBe(0);
});

test('invitation rejects cross-company, archived, ineligible, linked, and missing-email employees', function () {
    [$company, $actor] = ep2InvitationCompanyActor();
    $employee = Employee::factory()->create(['company_id' => Company::factory()->create()->id, 'personal_email' => 'person@example.test']);

    expect(fn () => issueInvitation($company, $employee, $actor))->toThrow(EmployeeInvitationException::class);

    $employee->delete();
    $archivedEmployee = Employee::factory()->create(['company_id' => $company->id, 'personal_email' => 'archived@example.test']);
    $archivedEmployee->delete();
    expect(fn () => issueInvitation($company, $archivedEmployee, $actor))->toThrow(EmployeeInvitationException::class);

    $inactiveEmployee = Employee::factory()->create([
        'company_id' => $company->id,
        'personal_email' => 'inactive@example.test',
        'employment_status' => 'terminated',
    ]);
    expect(fn () => issueInvitation($company, $inactiveEmployee, $actor))->toThrow(EmployeeInvitationException::class);

    $linkedEmployee = Employee::factory()->create([
        'company_id' => $company->id,
        'personal_email' => 'linked@example.test',
        'user_id' => User::factory()->create()->id,
    ]);
    expect(fn () => issueInvitation($company, $linkedEmployee, $actor))->toThrow(EmployeeInvitationException::class);

    $noEmailEmployee = Employee::factory()->create(['company_id' => $company->id, 'personal_email' => null]);
    expect(fn () => issueInvitation($company, $noEmailEmployee, $actor))->toThrow(EmployeeInvitationException::class);
});

test('reissuing revokes the old pending token while preserving invitation history', function () {
    [$company, $actor] = ep2InvitationCompanyActor();
    $employee = Employee::factory()->create(['company_id' => $company->id, 'personal_email' => 'person@example.test']);

    $first = issueInvitation($company, $employee, $actor);
    $second = issueInvitation($company, $employee, $actor);

    expect($second->rawToken)->not->toBe($first->rawToken)
        ->and($first->invitation->fresh()->revoked_at)->not->toBeNull()
        ->and($first->invitation->fresh()->status()->value)->toBe('revoked')
        ->and($employee->invitations()->count())->toBe(2)
        ->and(app(EmployeeInvitationResolver::class)->resolve($second->rawToken)->is($second->invitation))->toBeTrue();

    expect(fn () => app(EmployeeInvitationResolver::class)->resolve($first->rawToken))
        ->toThrow(EmployeeInvitationException::class);
});

test('an invitation keeps the email address snapshot after employee email changes', function () {
    [$company, $actor] = ep2InvitationCompanyActor();
    $employee = Employee::factory()->create(['company_id' => $company->id, 'personal_email' => 'old@example.test']);

    $issued = issueInvitation($company, $employee, $actor);
    $employee->update(['personal_email' => 'new@example.test']);

    expect($issued->invitation->fresh()->email)->toBe('old@example.test');
});

test('issuance does not alter user sessions or send email', function () {
    [$company, $actor] = ep2InvitationCompanyActor();
    $employee = Employee::factory()->create(['company_id' => $company->id, 'personal_email' => 'person@example.test']);
    $userCount = User::query()->count();

    $issued = issueInvitation($company, $employee, $actor);

    expect($issued->rawToken)->toHaveLength(64)
        ->and(User::query()->count())->toBe($userCount)
        ->and(User::query()->where('email', 'person@example.test')->exists())->toBeFalse()
        ->and(DB::table('sessions')->count())->toBe(0);
});

test('invitation foreign keys and token hash uniqueness are enforced by the database', function () {
    [$company, $actor] = ep2InvitationCompanyActor();
    $employee = Employee::factory()->create(['company_id' => $company->id, 'personal_email' => 'person@example.test']);
    $issued = issueInvitation($company, $employee, $actor);
    $attributes = [
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'email' => 'person@example.test',
        'token_hash' => $issued->invitation->token_hash,
        'expires_at' => now()->addHours(48),
        'invited_by' => $actor->id,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    expect(fn () => DB::table('employee_invitations')->insert($attributes))->toThrow(QueryException::class);

    $attributes['token_hash'] = str_repeat('a', 64);
    $attributes['employee_id'] = 999999;
    expect(fn () => DB::table('employee_invitations')->insert($attributes))->toThrow(QueryException::class);

    $attributes['employee_id'] = $employee->id;
    $attributes['token_hash'] = str_repeat('b', 64);
    $attributes['invited_by'] = 999999;
    expect(fn () => DB::table('employee_invitations')->insert($attributes))->toThrow(QueryException::class);
});

test('deleting the inviter preserves invitation history and clears only the inviter reference', function () {
    [$company, $actor] = ep2InvitationCompanyActor();
    $employee = Employee::factory()->create(['company_id' => $company->id, 'personal_email' => 'person@example.test']);
    $issued = issueInvitation($company, $employee, $actor);

    $actor->delete();

    expect(EmployeeInvitation::query()->find($issued->invitation->id))->not->toBeNull()
        ->and($issued->invitation->fresh()->invited_by)->toBeNull();
});

test('hard deletion of an employee with invitation history is restricted', function () {
    [$company, $actor] = ep2InvitationCompanyActor();
    $employee = Employee::factory()->create(['company_id' => $company->id, 'personal_email' => 'person@example.test']);
    issueInvitation($company, $employee, $actor);

    expect(fn () => $employee->forceDelete())->toThrow(QueryException::class)
        ->and(EmployeeInvitation::query()->count())->toBe(1);
});

test('reissuing after expiry preserves the expired invitation as history', function () {
    $this->freezeTime();
    [$company, $actor] = ep2InvitationCompanyActor();
    $employee = Employee::factory()->create(['company_id' => $company->id, 'personal_email' => 'person@example.test']);
    $first = issueInvitation($company, $employee, $actor);
    $this->travel(49)->hours();

    $second = issueInvitation($company, $employee, $actor);

    expect($first->invitation->fresh()->status()->value)->toBe('expired')
        ->and($first->invitation->fresh()->revoked_at)->toBeNull()
        ->and($second->invitation->status()->value)->toBe('pending')
        ->and($employee->invitations()->count())->toBe(2);
});
