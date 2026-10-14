<?php

use App\Actions\Employees\IssueEmployeeInvitation;
use App\Actions\SeedCompanyRoles;
use App\Enums\EmployeeInvitationStatus;
use App\Exceptions\Employees\EmployeeIdentityException;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\EmployeeInvitation;
use App\Models\User;
use App\Services\Employees\EmployeeUserLinker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** @return array{Company, User} */
function invitationAcceptanceCompanyActor(): array
{
    $company = Company::factory()->create(['status' => 'active']);
    $actor = User::factory()->create();
    CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($company);

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($company->id);
    $actor->assignRole('hr_admin');
    $registrar->setPermissionsTeamId(null);

    return [$company, $actor];
}

/** @return array{Company, Employee, string, EmployeeInvitation} */
function issueAcceptanceInvitation(?string $email = 'invitee@example.test'): array
{
    [$company, $actor] = invitationAcceptanceCompanyActor();
    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'personal_email' => $email,
        'first_name' => 'Rahul',
        'middle_name' => null,
        'last_name' => 'Das',
    ]);
    $issued = app(IssueEmployeeInvitation::class)->execute($company, $employee, $actor);

    return [$company, $employee, $issued->rawToken, $issued->invitation];
}

function assignAcceptanceRole(User $user, Company $company, string $roleName): void
{
    $registrar = app(PermissionRegistrar::class);
    $previousTeamId = $registrar->getPermissionsTeamId();
    $registrar->setPermissionsTeamId($company->id);
    $user->unsetRelation('roles')->unsetRelation('permissions')->assignRole($roleName);
    $registrar->setPermissionsTeamId($previousTeamId);
}

test('new employee can create an account through the public invitation flow', function () {
    [$company, $employee, $token, $invitation] = issueAcceptanceInvitation();

    $this->get(route('employee-invitations.show', $token))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('employee-invitations/show', false)
            ->where('state', 'pending')
            ->where('accountState', 'new_account')
            ->where('invitation.company', $company->name)
            ->where('invitation.employee', 'Rahul Das')
            ->where('invitation.email', 'invitee@example.test')
            ->missing('invitation.token_hash')
            ->missing('invitation.password')
            ->where('auth.user', null)
            ->where('companies', []));

    $response = $this->post(route('employee-invitations.accept', $token), [
        'password' => 'Secure-password-123!',
        'password_confirmation' => 'Secure-password-123!',
        'email' => 'attacker@example.test',
        'company_id' => 99999,
        'employee_id' => 99999,
        'role' => 'company_owner',
    ]);

    $response->assertRedirect(route('login', absolute: false));
    $user = User::query()->where('email', 'invitee@example.test')->firstOrFail();
    $membership = CompanyUser::query()->where('company_id', $company->id)->where('user_id', $user->id)->firstOrFail();

    expect(Hash::check('Secure-password-123!', $user->password))->toBeTrue()
        ->and($user->name)->toBe('Rahul Das')
        ->and($user->email_verified_at)->toBeNull()
        ->and($employee->fresh()->user_id)->toBe($user->id)
        ->and($membership->status)->toBe('active')
        ->and($invitation->fresh()->status())->toBe(EmployeeInvitationStatus::Accepted)
        ->and(auth()->guest())->toBeTrue();

    $this->assertDatabaseCount('company_users', 2);
    $this->assertDatabaseHas('model_has_roles', ['model_id' => $user->id, 'model_type' => User::class]);
});

test('existing account must authenticate and is never given a new password', function () {
    [$company, $employee, $token, $invitation] = issueAcceptanceInvitation('existing@example.test');
    $user = User::factory()->create(['email' => 'existing@example.test', 'password' => 'old-password']);
    $originalHash = $user->password;

    $this->get(route('employee-invitations.show', $token))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('accountState', 'existing_account_sign_in'));

    $this->post(route('employee-invitations.accept', $token), [])->assertRedirect(route('login', absolute: false));
    expect($invitation->fresh()->accepted_at)->toBeNull()
        ->and($employee->fresh()->user_id)->toBeNull()
        ->and($user->fresh()->password)->toBe($originalHash);

    $this->post('/login', ['email' => 'existing@example.test', 'password' => 'old-password'])
        ->assertRedirect(route('employee-invitations.show', ['token' => $token], absolute: false));
    $this->get(route('employee-invitations.show', $token))
        ->assertInertia(fn ($page) => $page->where('accountState', 'existing_account_authenticated'));
    $this->post(route('employee-invitations.accept', $token), [])->assertRedirect(route('dashboard', absolute: false));

    expect($invitation->fresh()->status())->toBe(EmployeeInvitationStatus::Accepted)
        ->and($employee->fresh()->user_id)->toBe($user->id)
        ->and($user->fresh()->password)->toBe($originalHash)
        ->and(CompanyUser::query()->where('company_id', $company->id)->where('user_id', $user->id)->count())->toBe(1);
});

test('existing account in another company retains access and receives only the invited company employee role', function () {
    [$company, $employee, $token] = issueAcceptanceInvitation('multi@example.test');
    $otherCompany = Company::factory()->create(['status' => 'active']);
    app(SeedCompanyRoles::class)->handle($otherCompany);
    $user = User::factory()->create(['email' => 'multi@example.test']);
    CompanyUser::query()->create(['company_id' => $otherCompany->id, 'user_id' => $user->id, 'status' => 'active']);
    assignAcceptanceRole($user, $otherCompany, 'manager');
    $originalPassword = $user->password;

    $this->actingAs($user)->post(route('employee-invitations.accept', $token), [])->assertRedirect(route('dashboard', absolute: false));

    expect($employee->fresh()->user_id)->toBe($user->id)
        ->and(CompanyUser::query()->where('company_id', $otherCompany->id)->where('user_id', $user->id)->count())->toBe(1)
        ->and(CompanyUser::query()->where('company_id', $company->id)->where('user_id', $user->id)->count())->toBe(1)
        ->and($user->fresh()->password)->toBe($originalPassword);

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($otherCompany->id);
    expect($user->fresh()->hasRole('manager'))->toBeTrue()
        ->and($user->fresh()->hasRole('employee'))->toBeFalse();
    $registrar->setPermissionsTeamId($company->id);
    expect($user->fresh()->hasRole('employee'))->toBeTrue();
    $registrar->setPermissionsTeamId(null);
});

test('same-company membership and roles are reused and preserved', function () {
    [$company, $employee, $token] = issueAcceptanceInvitation('member@example.test');
    $user = User::factory()->create(['email' => 'member@example.test']);
    CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $user->id, 'status' => 'active']);
    assignAcceptanceRole($user, $company, 'hr_manager');

    $this->actingAs($user)->post(route('employee-invitations.accept', $token), [])->assertRedirect(route('dashboard', absolute: false));

    expect(CompanyUser::query()->where('company_id', $company->id)->where('user_id', $user->id)->count())->toBe(1)
        ->and($employee->fresh()->user_id)->toBe($user->id);
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($company->id);
    expect($user->fresh()->hasRole('hr_manager'))->toBeTrue()
        ->and($user->fresh()->hasRole('employee'))->toBeTrue();
    $registrar->setPermissionsTeamId(null);
});

test('wrong authenticated account and a different employee identity cannot accept the invitation', function () {
    [$company, $employee, $token, $invitation] = issueAcceptanceInvitation('right@example.test');
    $wrongUser = User::factory()->create(['email' => 'wrong@example.test']);

    $this->actingAs($wrongUser)->post(route('employee-invitations.accept', $token), [])->assertSessionHasErrors('invitation');

    $alreadyLinked = User::factory()->create(['email' => 'right@example.test']);
    $otherEmployee = Employee::factory()->create(['company_id' => $company->id, 'user_id' => $alreadyLinked->id]);
    CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $alreadyLinked->id, 'status' => 'active']);
    $this->actingAs($alreadyLinked)->post(route('employee-invitations.accept', $token), [])->assertSessionHasErrors('invitation');

    expect($employee->fresh()->user_id)->toBeNull()
        ->and($invitation->fresh()->accepted_at)->toBeNull()
        ->and($otherEmployee->fresh()->user_id)->toBe($alreadyLinked->id);
});

test('activation rejects changed employee email, inactive membership, and inactive company', function () {
    [$company, $employee, $token, $invitation] = issueAcceptanceInvitation('before@example.test');
    $employee->update(['personal_email' => 'after@example.test']);
    $this->post(route('employee-invitations.accept', $token), [
        'password' => 'Secure-password-123!',
        'password_confirmation' => 'Secure-password-123!',
    ])->assertSessionHasErrors('invitation');
    expect($invitation->fresh()->accepted_at)->toBeNull()
        ->and(User::query()->where('email', 'before@example.test')->exists())->toBeFalse();

    [$company2, $employee2, $token2] = issueAcceptanceInvitation('disabled@example.test');
    $existing = User::factory()->create(['email' => 'disabled@example.test']);
    CompanyUser::query()->create(['company_id' => $company2->id, 'user_id' => $existing->id, 'status' => 'inactive']);
    $this->actingAs($existing)->post(route('employee-invitations.accept', $token2), [])->assertSessionHasErrors('invitation');
    expect($employee2->fresh()->user_id)->toBeNull();

    [$company3, $employee3, $token3, $invitation3] = issueAcceptanceInvitation('inactivecompany@example.test');
    $company3->update(['status' => 'inactive']);
    $this->post(route('employee-invitations.accept', $token3), [
        'password' => 'Secure-password-123!',
        'password_confirmation' => 'Secure-password-123!',
    ])->assertSessionHasErrors('invitation');
    expect($employee3->fresh()->user_id)->toBeNull()
        ->and($invitation3->fresh()->accepted_at)->toBeNull()
        ->and($company->fresh()->status)->toBe('active');
});

test('archived, ineligible, expired, revoked, and unknown invitations are rejected safely', function () {
    [, $employee, $token, $invitation] = issueAcceptanceInvitation();
    $employee->update(['employment_status' => 'terminated']);
    $this->post(route('employee-invitations.accept', $token), ['password' => 'Secure-password-123!', 'password_confirmation' => 'Secure-password-123!'])
        ->assertSessionHasErrors('invitation');
    expect($invitation->fresh()->accepted_at)->toBeNull();

    [, $archivedEmployee, $archivedToken, $archivedInvitation] = issueAcceptanceInvitation('archived@example.test');
    $archivedEmployee->delete();
    $this->post(route('employee-invitations.accept', $archivedToken), [
        'password' => 'Secure-password-123!',
        'password_confirmation' => 'Secure-password-123!',
    ])->assertSessionHasErrors('invitation');
    expect($archivedEmployee->fresh()->user_id)->toBeNull()
        ->and($archivedInvitation->fresh()->accepted_at)->toBeNull();

    [$company2, $employee2, $token2, $expired] = issueAcceptanceInvitation('expired@example.test');
    $expired->forceFill(['expires_at' => now()->subMinute()])->save();
    $this->get(route('employee-invitations.show', $token2))->assertInertia(fn ($page) => $page->where('state', 'expired')->where('invitation', null));
    $this->post(route('employee-invitations.accept', $token2), ['password' => 'Secure-password-123!', 'password_confirmation' => 'Secure-password-123!'])
        ->assertRedirect(route('employee-invitations.show', ['token' => $token2], absolute: false));

    [$company3, $employee3, $token3, $revoked] = issueAcceptanceInvitation('revoked@example.test');
    $revoked->forceFill(['revoked_at' => now()])->save();
    $this->get(route('employee-invitations.show', $token3))->assertInertia(fn ($page) => $page->where('state', 'revoked'));
    $this->get(route('employee-invitations.show', str_repeat('z', 64)))->assertInertia(fn ($page) => $page->where('state', 'invalid'));
});

test('acceptance is idempotent and does not duplicate users memberships roles or identity links', function () {
    [$company, $employee, $token, $invitation] = issueAcceptanceInvitation();
    $payload = ['password' => 'Secure-password-123!', 'password_confirmation' => 'Secure-password-123!'];

    $this->post(route('employee-invitations.accept', $token), $payload)->assertRedirect(route('login', absolute: false));
    $this->get(route('employee-invitations.show', $token))
        ->assertInertia(fn ($page) => $page->where('state', 'accepted')->where('invitation', null));
    $user = User::query()->where('email', 'invitee@example.test')->firstOrFail();
    $membershipCount = CompanyUser::query()->where('company_id', $company->id)->where('user_id', $user->id)->count();
    $this->post(route('employee-invitations.accept', $token), $payload)->assertRedirect(route('login', absolute: false));

    expect(User::query()->where('email', 'invitee@example.test')->count())->toBe(1)
        ->and(CompanyUser::query()->where('company_id', $company->id)->where('user_id', $user->id)->count())->toBe($membershipCount)
        ->and($company->employees()->where('user_id', $user->id)->count())->toBe(1)
        ->and($invitation->fresh()->status())->toBe(EmployeeInvitationStatus::Accepted);
});

test('link integrity failure rolls back newly created membership role and invitation acceptance', function () {
    [$company, $employee, $token, $invitation] = issueAcceptanceInvitation();
    $user = User::factory()->create(['email' => 'invitee@example.test']);
    Employee::factory()->create(['company_id' => $company->id, 'user_id' => $user->id]);

    $this->actingAs($user)->post(route('employee-invitations.accept', $token), [])->assertSessionHasErrors('invitation');

    expect(CompanyUser::query()->where('company_id', $company->id)->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(Role::query()->where('team_id', $company->id)->where('name', 'employee')->exists())->toBeTrue()
        ->and($employee->fresh()->user_id)->toBeNull()
        ->and($invitation->fresh()->accepted_at)->toBeNull();
});

test('downstream link failure rolls back newly created user membership role and invitation acceptance', function () {
    [$company, $employee, $token, $invitation] = issueAcceptanceInvitation();
    $linker = Mockery::mock(EmployeeUserLinker::class);
    $linker->shouldReceive('link')->once()->andThrow(new EmployeeIdentityException('USER_ALREADY_LINKED_IN_COMPANY'));
    $this->app->instance(EmployeeUserLinker::class, $linker);

    $this->post(route('employee-invitations.accept', $token), [
        'password' => 'Secure-password-123!',
        'password_confirmation' => 'Secure-password-123!',
    ])->assertSessionHasErrors('invitation');

    $user = User::query()->where('email', 'invitee@example.test')->first();
    $employeeRoleId = Role::query()->where('team_id', $company->id)->where('name', 'employee')->value('id');

    expect($user)->toBeNull()
        ->and($employee->fresh()->user_id)->toBeNull()
        ->and($invitation->fresh()->accepted_at)->toBeNull()
        ->and(CompanyUser::query()->where('company_id', $company->id)->count())->toBe(1)
        ->and(DB::table('model_has_roles')->where('role_id', $employeeRoleId)->exists())->toBeFalse();
});

test('password fields are confirmed and the new account cannot choose invitation identity values', function () {
    [, $employee, $token, $invitation] = issueAcceptanceInvitation();

    $this->post(route('employee-invitations.accept', $token), [
        'password' => 'Secure-password-123!',
        'password_confirmation' => 'different-password',
    ])->assertSessionHasErrors('password');

    expect(User::query()->where('email', 'invitee@example.test')->exists())->toBeFalse()
        ->and($employee->fresh()->user_id)->toBeNull()
        ->and($invitation->fresh()->accepted_at)->toBeNull();
});

test('authenticated unrelated user cannot use a new-account invitation without signing out', function () {
    [, $employee, $token, $invitation] = issueAcceptanceInvitation();
    $currentUser = User::factory()->create();

    $this->actingAs($currentUser)->get(route('employee-invitations.show', $token))
        ->assertInertia(fn ($page) => $page->where('accountState', 'sign_out_required'));
    $this->post(route('employee-invitations.accept', $token), [
        'password' => 'Secure-password-123!',
        'password_confirmation' => 'Secure-password-123!',
    ])->assertSessionHasErrors('invitation');

    expect($employee->fresh()->user_id)->toBeNull()
        ->and($invitation->fresh()->accepted_at)->toBeNull()
        ->and(User::query()->where('email', 'invitee@example.test')->exists())->toBeFalse();
});

test('if an account is created after the invitation page loads acceptance requires sign in instead of changing its password', function () {
    [, $employee, $token, $invitation] = issueAcceptanceInvitation();
    $this->get(route('employee-invitations.show', $token))
        ->assertInertia(fn ($page) => $page->where('accountState', 'new_account'));
    $user = User::factory()->create(['email' => 'invitee@example.test', 'password' => 'existing-password']);
    $originalPassword = $user->password;

    $this->post(route('employee-invitations.accept', $token), [
        'password' => 'Attacker-password-123!',
        'password_confirmation' => 'Attacker-password-123!',
    ])->assertRedirect(route('login', absolute: false));

    expect($user->fresh()->password)->toBe($originalPassword)
        ->and($employee->fresh()->user_id)->toBeNull()
        ->and($invitation->fresh()->accepted_at)->toBeNull();
});

test('invitation status accepted can be retried without changing accepted audit data', function () {
    [$company, $employee, $token, $invitation] = issueAcceptanceInvitation();
    $this->post(route('employee-invitations.accept', $token), [
        'password' => 'Secure-password-123!',
        'password_confirmation' => 'Secure-password-123!',
    ]);
    $acceptedAt = $invitation->fresh()->accepted_at->toDateTimeString();

    $this->post(route('employee-invitations.accept', $token), [])->assertRedirect(route('login', absolute: false));

    expect($invitation->fresh()->accepted_at->toDateTimeString())->toBe($acceptedAt)
        ->and($employee->fresh()->user_id)->not->toBeNull()
        ->and(CompanyUser::query()->where('company_id', $company->id)->count())->toBe(2);
});
