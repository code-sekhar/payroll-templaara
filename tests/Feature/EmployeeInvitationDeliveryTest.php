<?php

use App\Actions\Employees\IssueEmployeeInvitation;
use App\Actions\SeedCompanyRoles;
use App\Enums\EmployeeInvitationStatus;
use App\Exceptions\Employees\EmployeeInvitationException;
use App\Mail\Employees\EmployeeInvitationMail;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\EmployeeInvitation;
use App\Models\User;
use App\Services\Employees\EmployeeInvitationResolver;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;

/** @return array{Company, User} */
function employeeInvitationDeliveryActor(string $role = 'hr_admin'): array
{
    $company = Company::factory()->create(['timezone' => 'Asia/Kolkata']);
    $user = User::factory()->create(['name' => 'HR Sender']);
    CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $user->id, 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($company);
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($company->id);
    $user->assignRole($role);
    $registrar->setPermissionsTeamId(null);

    return [$company, $user];
}

function employeeInvitationMailToken(EmployeeInvitationMail $mail): string
{
    $path = trim((string) parse_url($mail->activationUrl, PHP_URL_PATH), '/');
    $segments = explode('/', $path);

    return (string) $segments[array_key_last($segments)];
}

test('authorized HR can issue an invitation and send the committed token to the email snapshot', function (string $role) {
    Mail::fake();
    Notification::fake();
    config(['app.url' => 'https://hr.example.test/portal']);
    [$company, $actor] = employeeInvitationDeliveryActor($role);
    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'personal_email' => '  Rahul.Das@example.test  ',
        'first_name' => 'Rahul',
        'last_name' => 'Das',
    ]);

    $response = $this->actingAs($actor)->post(route('companies.employees.invitation.store', [$company, $employee]));

    $response->assertRedirect(route('employees.show', $employee))
        ->assertSessionHas('success', 'Invitation sent to rahul.das@example.test.');
    expect(EmployeeInvitation::query()->count())->toBe(1)
        ->and(EmployeeInvitation::query()->firstOrFail()->status())->toBe(EmployeeInvitationStatus::Pending);

    Mail::assertSent(EmployeeInvitationMail::class, function (EmployeeInvitationMail $mail) use ($company): bool {
        expect($mail->hasTo('rahul.das@example.test'))->toBeTrue()
            ->and($mail->hasSubject("Activate your employee account for {$company->name}"))->toBeTrue()
            ->and($mail->employeeName)->toBe('Rahul Das')
            ->and($mail->inviterName)->toBe('HR Sender')
            ->and($mail->expiresAt)->toContain('IST');

        $token = employeeInvitationMailToken($mail);
        expect(strlen($token))->toBe(64)
            ->and(app(EmployeeInvitationResolver::class)->resolve($token)->email)->toBe('rahul.das@example.test');

        $invitation = EmployeeInvitation::query()->firstOrFail();
        $expectedPath = '/portal'.route('employee-invitations.show', ['token' => $token], absolute: false);
        expect(parse_url($mail->activationUrl, PHP_URL_PATH) === $expectedPath)->toBeTrue()
            ->and(parse_url($mail->activationUrl, PHP_URL_SCHEME))->toBe('https')
            ->and(parse_url($mail->activationUrl, PHP_URL_HOST))->toBe('hr.example.test')
            ->and(parse_url($mail->activationUrl, PHP_URL_PATH))->toContain('/portal/employee-invitations/')
            ->and($mail->expiresAt === $invitation->expires_at->copy()->timezone($company->timezone)->format('j M Y, g:i A T'))->toBeTrue();

        $html = $mail->render();
        expect($html)->toContain($company->name)
            ->and($html)->toContain('Rahul Das')
            ->and($html)->toContain('Activate Employee Account')
            ->and($html)->toContain($mail->activationUrl)
            ->and($html)->toContain($mail->expiresAt);

        return true;
    });
    Notification::assertNothingSent();
})->with(['company_owner', 'hr_admin', 'hr_manager']);

test('employee detail exposes safe login access state without identity or invitation secrets', function () {
    Mail::fake();
    [$company, $actor] = employeeInvitationDeliveryActor();
    $employee = Employee::factory()->create(['company_id' => $company->id, 'personal_email' => 'person@example.test']);

    $this->actingAs($actor)->get(route('employees.show', $employee))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('employees/show', false)
            ->where('loginAccess.status', 'not_invited')
            ->where('loginAccess.email', 'person@example.test')
            ->where('loginAccess.canInvite', true)
            ->has('invitationUrl')
            ->missing('employee.user_id')
            ->missing('employee.user.id')
            ->missing('employee.user.remember_token')
            ->missing('loginAccess.token_hash')
            ->missing('loginAccess.rawToken'));

    $this->actingAs($actor)->post(route('companies.employees.invitation.store', [$company, $employee]))->assertRedirect();
    $this->actingAs($actor)->get(route('employees.show', $employee))
        ->assertInertia(fn ($page) => $page
            ->where('loginAccess.status', 'pending')
            ->where('loginAccess.invitationEmail', 'person@example.test')
            ->where('loginAccess.canInvite', true));

    Mail::assertSent(EmployeeInvitationMail::class);
});

test('resending rotates the activation link and follows a changed employee email snapshot', function () {
    Mail::fake();
    [$company, $actor] = employeeInvitationDeliveryActor();
    $employee = Employee::factory()->create(['company_id' => $company->id, 'personal_email' => 'old@example.test']);
    $this->actingAs($actor)->post(route('companies.employees.invitation.store', [$company, $employee]))->assertRedirect();
    $firstInvitation = EmployeeInvitation::query()->firstOrFail();

    $employee->update(['personal_email' => 'new@example.test']);
    $this->actingAs($actor)->get(route('employees.show', $employee))
        ->assertInertia(fn ($page) => $page
            ->where('loginAccess.status', 'pending')
            ->where('loginAccess.email', 'new@example.test')
            ->where('loginAccess.invitationEmail', 'old@example.test')
            ->where('loginAccess.emailChanged', true));

    $this->actingAs($actor)->post(route('companies.employees.invitation.store', [$company, $employee]))
        ->assertRedirect()
        ->assertSessionHas('success', 'A new invitation was sent to new@example.test. The previous invitation link is no longer valid.');

    $tokens = [];
    Mail::assertSent(EmployeeInvitationMail::class, function (EmployeeInvitationMail $mail) use (&$tokens): bool {
        $tokens[] = employeeInvitationMailToken($mail);

        return true;
    });

    expect(count($tokens))->toBe(2)
        ->and($tokens[0])->not->toBe($tokens[1])
        ->and($firstInvitation->fresh()->status())->toBe(EmployeeInvitationStatus::Revoked)
        ->and(EmployeeInvitation::query()->count())->toBe(2)
        ->and(EmployeeInvitation::query()->orderByDesc('id')->firstOrFail()->email)->toBe('new@example.test')
        ->and(app(EmployeeInvitationResolver::class)->resolve($tokens[1])->email)->toBe('new@example.test');

    expect(fn () => app(EmployeeInvitationResolver::class)->resolve($tokens[0]))
        ->toThrow(EmployeeInvitationException::class);
});

test('mail delivery failure preserves invitation history and returns partial-success guidance without token leakage', function () {
    [$company, $actor] = employeeInvitationDeliveryActor();
    $employee = Employee::factory()->create(['company_id' => $company->id, 'personal_email' => 'person@example.test']);
    Mail::shouldReceive('to')->once()->with('person@example.test')->andThrow(new RuntimeException('transport contains private details'));

    $response = $this->actingAs($actor)->post(route('companies.employees.invitation.store', [$company, $employee]));

    $response->assertRedirect(route('employees.show', $employee))
        ->assertSessionHas('warning', 'The invitation was created, but the email could not be sent. Please try sending a new invitation.')
        ->assertSessionMissing('rawToken');
    expect(EmployeeInvitation::query()->count())->toBe(1)
        ->and(EmployeeInvitation::query()->firstOrFail()->status())->toBe(EmployeeInvitationStatus::Pending)
        ->and(EmployeeInvitation::query()->firstOrFail()->token_hash)->not->toBeEmpty();
});

test('unauthorized and cross-company actors cannot issue an invitation', function () {
    Mail::fake();
    [$company, $actor] = employeeInvitationDeliveryActor('employee');
    $employee = Employee::factory()->create(['company_id' => $company->id, 'personal_email' => 'person@example.test']);

    $this->actingAs($actor)->post(route('companies.employees.invitation.store', [$company, $employee]))->assertForbidden();
    Mail::assertNothingSent();
    expect(EmployeeInvitation::query()->count())->toBe(0);

    [$otherCompany, $otherActor] = employeeInvitationDeliveryActor('hr_admin');
    $foreignEmployee = Employee::factory()->create(['company_id' => $otherCompany->id, 'personal_email' => 'foreign@example.test']);
    $this->actingAs($actor)->post(route('companies.employees.invitation.store', [$company, $foreignEmployee]))->assertNotFound();
    $this->actingAs($otherActor)->post(route('companies.employees.invitation.store', [$company, $employee]))->assertNotFound();
    expect(EmployeeInvitation::query()->count())->toBe(0);
});

test('active and unavailable employees have accurate login access states and no invitation action', function () {
    Mail::fake();
    [$company, $actor] = employeeInvitationDeliveryActor();
    $linkedUser = User::factory()->create();
    $activeEmployee = Employee::factory()->create([
        'company_id' => $company->id,
        'personal_email' => 'active@example.test',
        'user_id' => $linkedUser->id,
    ]);
    $missingEmailEmployee = Employee::factory()->create(['company_id' => $company->id, 'personal_email' => null]);
    $inactiveEmployee = Employee::factory()->create([
        'company_id' => $company->id,
        'personal_email' => 'inactive@example.test',
        'employment_status' => 'terminated',
    ]);

    $this->actingAs($actor)->get(route('employees.show', $activeEmployee))
        ->assertInertia(fn ($page) => $page
            ->where('loginAccess.status', 'active')
            ->where('loginAccess.email', $linkedUser->email)
            ->where('loginAccess.canInvite', false)
            ->where('invitationUrl', null)
            ->missing('employee.user.id'));
    $this->post(route('companies.employees.invitation.store', [$company, $activeEmployee]))
        ->assertRedirect(route('employees.show', $activeEmployee))
        ->assertSessionHas('warning', 'This employee already has login access.');
    $this->get(route('employees.show', $missingEmailEmployee))
        ->assertInertia(fn ($page) => $page
            ->where('loginAccess.status', 'unavailable')
            ->where('loginAccess.unavailableReason', 'email_missing')
            ->where('loginAccess.canInvite', false));
    $this->post(route('companies.employees.invitation.store', [$company, $missingEmailEmployee]))
        ->assertRedirect(route('employees.show', $missingEmailEmployee))
        ->assertSessionHas('warning', 'Add a valid personal email before sending an invitation.');
    $this->get(route('employees.show', $inactiveEmployee))
        ->assertInertia(fn ($page) => $page
            ->where('loginAccess.status', 'unavailable')
            ->where('loginAccess.unavailableReason', 'employee_inactive')
            ->where('loginAccess.canInvite', false));
    $this->post(route('companies.employees.invitation.store', [$company, $inactiveEmployee]))
        ->assertRedirect(route('employees.show', $inactiveEmployee))
        ->assertSessionHas('warning', 'Login invitations are not available for this employee’s current status.');
    Mail::assertNothingSent();
});

test('unauthenticated users are redirected before the HR invitation endpoint', function () {
    [$company] = employeeInvitationDeliveryActor();
    $employee = Employee::factory()->create(['company_id' => $company->id, 'personal_email' => 'person@example.test']);

    $this->post(route('companies.employees.invitation.store', [$company, $employee]))
        ->assertRedirect(route('login'));

    expect(EmployeeInvitation::query()->count())->toBe(0);
});

test('expired invitations are shown as expired with a new invitation action', function () {
    Mail::fake();
    [$company, $actor] = employeeInvitationDeliveryActor();
    $employee = Employee::factory()->create(['company_id' => $company->id, 'personal_email' => 'person@example.test']);
    $issued = app(IssueEmployeeInvitation::class)->execute($company, $employee, $actor);
    $issued->invitation->expires_at = now()->subMinute();
    $issued->invitation->save();

    $this->actingAs($actor)->get(route('employees.show', $employee))
        ->assertInertia(fn ($page) => $page
            ->where('loginAccess.status', 'expired')
            ->where('loginAccess.canInvite', true));

    $this->post(route('companies.employees.invitation.store', [$company, $employee]))->assertRedirect();
    expect(EmployeeInvitation::query()->count())->toBe(2)
        ->and(EmployeeInvitation::query()->orderByDesc('id')->firstOrFail()->status())->toBe(EmployeeInvitationStatus::Pending);
    Mail::assertSent(EmployeeInvitationMail::class);
});
