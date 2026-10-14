<?php

use App\Actions\Employees\IssueEmployeeInvitation;
use App\Actions\SeedCompanyRoles;
use App\Data\Employees\IssuedEmployeeInvitation;
use App\Enums\EmployeeInvitationStatus;
use App\Exceptions\Employees\EmployeeInvitationException;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\EmployeeInvitation;
use App\Models\User;
use App\Services\Employees\EmployeeInvitationResolver;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Spatie\Permission\PermissionRegistrar;

/** @return array{Company, User} */
function invitationResolverCompanyActor(): array
{
    $company = Company::factory()->create();
    $actor = User::factory()->create();
    CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($company);
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($company->id);
    $actor->assignRole('hr_admin');
    $registrar->setPermissionsTeamId(null);

    return [$company, $actor];
}

function invitationResolverIssue(): IssuedEmployeeInvitation
{
    [$company, $actor] = invitationResolverCompanyActor();
    $employee = Employee::factory()->create(['company_id' => $company->id, 'personal_email' => 'person@example.test']);

    return app(IssueEmployeeInvitation::class)->execute($company, $employee, $actor);
}

test('resolver finds a pending invitation by the hash of its raw token', function () {
    $issued = invitationResolverIssue();

    $resolved = app(EmployeeInvitationResolver::class)->resolve($issued->rawToken);

    expect($resolved->is($issued->invitation))->toBeTrue()
        ->and($resolved->status())->toBe(EmployeeInvitationStatus::Pending)
        ->and($resolved->toArray())->not->toHaveKey('token_hash');
});

test('resolver rejects unknown and empty tokens with a safe not found reason', function (string $token) {
    try {
        app(EmployeeInvitationResolver::class)->resolve($token);
        test()->fail('An unknown invitation token must not resolve.');
    } catch (EmployeeInvitationException $exception) {
        expect($exception->reason)->toBe('INVITATION_NOT_FOUND');
    }
})->with(['unknown-secret', '']);

test('resolver rejects expired invitations', function () {
    $issued = invitationResolverIssue();
    $issued->invitation->forceFill(['expires_at' => now()->subSecond()])->save();

    try {
        app(EmployeeInvitationResolver::class)->resolve($issued->rawToken);
        test()->fail('An expired invitation must not resolve.');
    } catch (EmployeeInvitationException $exception) {
        expect($exception->reason)->toBe('INVITATION_EXPIRED');
    }
});

test('resolver rejects revoked invitations', function () {
    $issued = invitationResolverIssue();
    $issued->invitation->forceFill(['revoked_at' => now()])->save();

    try {
        app(EmployeeInvitationResolver::class)->resolve($issued->rawToken);
        test()->fail('A revoked invitation must not resolve.');
    } catch (EmployeeInvitationException $exception) {
        expect($exception->reason)->toBe('INVITATION_REVOKED');
    }
});

test('resolver rejects accepted invitations without disclosing user information', function () {
    $issued = invitationResolverIssue();
    $issued->invitation->forceFill(['accepted_at' => now()])->save();

    try {
        app(EmployeeInvitationResolver::class)->resolve($issued->rawToken);
        test()->fail('An accepted invitation must not resolve for activation again.');
    } catch (EmployeeInvitationException $exception) {
        expect($exception->reason)->toBe('INVITATION_ALREADY_ACCEPTED')
            ->and($exception->getMessage())->not->toContain($issued->rawToken)
            ->and($exception->getMessage())->not->toContain($issued->invitation->token_hash);
    }
});

test('invitation status gives accepted precedence over revoked and revoked over expired', function () {
    $issued = invitationResolverIssue();

    $issued->invitation->forceFill([
        'accepted_at' => now(),
        'revoked_at' => now(),
        'expires_at' => now()->subDay(),
    ])->save();
    expect($issued->invitation->fresh()->status())->toBe(EmployeeInvitationStatus::Accepted);

    $issued->invitation->forceFill(['accepted_at' => null])->save();
    expect($issued->invitation->fresh()->status())->toBe(EmployeeInvitationStatus::Revoked);
});

test('model mass assignment and serialization cannot expose the invitation hash', function () {
    expect(fn () => new EmployeeInvitation(['token_hash' => str_repeat('a', 64)]))
        ->toThrow(MassAssignmentException::class);
});
