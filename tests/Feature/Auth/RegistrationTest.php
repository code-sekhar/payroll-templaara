<?php

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

test('registration screen can be rendered', function () {
    $response = $this->get('/register');

    $response->assertStatus(200);
});

test('new users can register', function () {
    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
    $this->assertDatabaseCount('companies', 0);
});

test('new business users receive a company owner membership and role', function () {
    $this->post('/register', [
        'name' => 'New Owner',
        'company_name' => 'New Organization',
        'email' => 'owner@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = User::where('email', 'owner@example.com')->firstOrFail();
    $membership = CompanyUser::where('user_id', $user->id)->firstOrFail();
    app(PermissionRegistrar::class)->setPermissionsTeamId($membership->company_id);

    expect(Company::findOrFail($membership->company_id)->name)->toBe('New Organization')
        ->and($user->hasRole('company_owner'))->toBeTrue()
        ->and($user->can('company.update'))->toBeTrue();
});
