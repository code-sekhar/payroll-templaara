<?php

use App\Actions\SeedCompanyRoles;
use App\Enums\AttendanceRegularizationStatus;
use App\Models\AttendanceRegularizationRequest;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\AttendanceCorrectionApproved;
use App\Notifications\AttendanceCorrectionRejected;
use App\Notifications\AttendanceCorrectionSubmitted;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

function notificationCenterMember(Company $company, string $role): User
{
    $user = User::factory()->create();
    CompanyUser::query()->create(['company_id' => $company->getKey(), 'user_id' => $user->getKey(), 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($company);
    $registrar = app(PermissionRegistrar::class);
    $previousTeamId = $registrar->getPermissionsTeamId();
    $registrar->setPermissionsTeamId($company->getKey());
    $user->unsetRelation('roles')->unsetRelation('permissions')->assignRole($role);
    $registrar->setPermissionsTeamId($previousTeamId);

    return $user;
}

function notificationCenterRequest(Company $company, Employee $employee, User $requester, AttendanceRegularizationStatus $status = AttendanceRegularizationStatus::Pending): AttendanceRegularizationRequest
{
    return AttendanceRegularizationRequest::query()->create([
        'company_id' => $company->getKey(),
        'employee_id' => $employee->getKey(),
        'attendance_date' => '2026-10-05',
        'request_type' => 'other',
        'status' => $status,
        'reason' => 'Private attendance reason.',
        'requested_by' => $requester->getKey(),
        'requested_at' => now('UTC'),
    ]);
}

function notificationCenterRaw(User $recipient, ?int $companyId, string $type, array $data, ?string $readAt = null, ?string $createdAt = null): void
{
    $recipient->notifications()->create([
        'id' => (string) Str::uuid(),
        'company_id' => $companyId,
        'type' => $type,
        'data' => $data,
        'read_at' => $readAt,
        'created_at' => $createdAt ?? now(),
        'updated_at' => now(),
    ]);
}

test('notification center paginates authorized history and counts more than fifty unread records', function () {
    $company = Company::factory()->create(['timezone' => 'Asia/Kolkata']);
    $reviewer = notificationCenterMember($company, 'hr_admin');
    $employee = Employee::factory()->create(['company_id' => $company->getKey()]);
    $request = notificationCenterRequest($company, $employee, User::factory()->create());

    $reviewer->notify(new AttendanceCorrectionSubmitted($company->getKey(), $request->getKey(), '2026-10-05', $employee->full_name));
    $first = $reviewer->notifications()->firstOrFail();
    for ($index = 1; $index < 55; $index++) {
        notificationCenterRaw($reviewer, (int) $company->getKey(), AttendanceCorrectionSubmitted::class, [
            'regularization_id' => $request->getKey(),
            'message' => 'Forged payload summary must not reach the page.',
            'private_note' => 'Internal detail.',
        ], null, now()->subSeconds(55 - $index)->toDateTimeString());
    }

    $this->actingAs($reviewer)->withSession(['active_company_id' => $company->getKey()])
        ->get(route('notifications.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('notifications/index', false)
            ->where('notificationCenter.unread_count', 55)
            ->where('notificationCenter.pagination.total', 55)
            ->where('notificationCenter.pagination.per_page', 20)
            ->has('notificationCenter.notifications', 20)
            ->where('notificationCenter.notifications.0.title', 'Attendance correction submitted')
            ->where('notificationCenter.notifications.0.summary', 'An attendance correction request is ready for review.')
            ->missing('notificationCenter.notifications.0.regularization_id')
            ->missing('notificationCenter.notifications.0.private_note')
            ->missing('notificationCenter.notifications.0.company_id')
            ->where('notificationCenter.notifications.0.id', fn (string $id): bool => $id === (string) $first->getKey()));
});

test('all and unread filters preserve company scope and unread totals', function () {
    $company = Company::factory()->create();
    $reviewer = notificationCenterMember($company, 'hr_admin');
    $employee = Employee::factory()->create(['company_id' => $company->getKey()]);
    $request = notificationCenterRequest($company, $employee, User::factory()->create());
    notificationCenterRaw($reviewer, (int) $company->getKey(), AttendanceCorrectionSubmitted::class, ['regularization_id' => $request->getKey()]);
    notificationCenterRaw($reviewer, (int) $company->getKey(), AttendanceCorrectionSubmitted::class, ['regularization_id' => $request->getKey()], now()->toDateTimeString());
    notificationCenterRaw($reviewer, null, AttendanceCorrectionSubmitted::class, ['regularization_id' => $request->getKey()]);

    $this->actingAs($reviewer)->withSession(['active_company_id' => $company->getKey()])
        ->get(route('notifications.index', ['filter' => 'unread', 'company_id' => 999, 'user_id' => 999]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('notificationCenter.filter', 'unread')
            ->where('notificationCenter.unread_count', 1)
            ->where('notificationCenter.pagination.total', 1)
            ->has('notificationCenter.notifications', 1));

    $this->get(route('notifications.index', ['filter' => 'all']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('notificationCenter.pagination.total', 2)
            ->has('notificationCenter.notifications', 2));
});

test('mark as read requires an owned currently authorized destination and repeated reads are harmless', function () {
    $company = Company::factory()->create();
    $reviewer = notificationCenterMember($company, 'manager');
    $registrar = app(PermissionRegistrar::class);
    $previousTeamId = $registrar->getPermissionsTeamId();
    $registrar->setPermissionsTeamId($company->getKey());
    $reviewer->unsetRelation('roles')->unsetRelation('permissions')->givePermissionTo('attendance_regularization.approve');
    $registrar->setPermissionsTeamId($previousTeamId);
    $employee = Employee::factory()->create(['company_id' => $company->getKey()]);
    $managerEmployee = Employee::factory()->create(['company_id' => $company->getKey(), 'user_id' => $reviewer->getKey()]);
    $employee->update(['manager_id' => $managerEmployee->getKey()]);
    $request = notificationCenterRequest($company, $employee, User::factory()->create());
    $otherRequest = notificationCenterRequest($company, Employee::factory()->create(['company_id' => $company->getKey()]), User::factory()->create());
    $otherCompany = Company::factory()->create();
    $foreignEmployee = Employee::factory()->create(['company_id' => $otherCompany->getKey()]);
    $foreignRequest = notificationCenterRequest($otherCompany, $foreignEmployee, User::factory()->create());
    $reviewer->notify(new AttendanceCorrectionSubmitted($company->getKey(), $request->getKey(), '2026-10-05', $employee->full_name));
    $allowed = $reviewer->notifications()->firstOrFail();
    notificationCenterRaw($reviewer, (int) $company->getKey(), AttendanceCorrectionSubmitted::class, ['regularization_id' => $otherRequest->getKey()]);
    $outOfScope = $reviewer->notifications()->where('id', '!=', $allowed->getKey())->firstOrFail();
    notificationCenterRaw($reviewer, (int) $otherCompany->getKey(), AttendanceCorrectionSubmitted::class, ['regularization_id' => $foreignRequest->getKey()]);
    $foreign = $reviewer->notifications()->where('company_id', $otherCompany->getKey())->firstOrFail();
    notificationCenterRaw($reviewer, (int) $company->getKey(), 'UnsupportedNotification', ['regularization_id' => $request->getKey()]);
    $unsupported = $reviewer->notifications()->where('type', 'UnsupportedNotification')->firstOrFail();
    $otherUser = notificationCenterMember($company, 'hr_admin');

    $this->actingAs($reviewer)->withSession(['active_company_id' => $company->getKey()])
        ->from(route('notifications.index'))
        ->post(route('notifications.read', $allowed->getKey()))
        ->assertRedirect(route('notifications.index'));

    $this->post(route('notifications.read', $allowed->getKey()))->assertRedirect();
    $this->post(route('notifications.read', $outOfScope->getKey()))->assertNotFound();
    $this->post(route('notifications.read', $foreign->getKey()))->assertNotFound();
    $this->post(route('notifications.read', $unsupported->getKey()))->assertNotFound();
    $this->actingAs($otherUser)->withSession(['active_company_id' => $company->getKey()])
        ->post(route('notifications.read', $allowed->getKey()))
        ->assertNotFound();

    expect($allowed->fresh()->read_at)->not->toBeNull()
        ->and($outOfScope->fresh()->read_at)->toBeNull()
        ->and($foreign->fresh()->read_at)->toBeNull()
        ->and($unsupported->fresh()->read_at)->toBeNull();
});

test('mark all reads only destinations still authorized for a restricted manager', function () {
    $company = Company::factory()->create();
    $manager = notificationCenterMember($company, 'manager');
    $registrar = app(PermissionRegistrar::class);
    $previousTeamId = $registrar->getPermissionsTeamId();
    $registrar->setPermissionsTeamId($company->getKey());
    $manager->unsetRelation('roles')->unsetRelation('permissions')->givePermissionTo('attendance_regularization.approve');
    $registrar->setPermissionsTeamId($previousTeamId);
    $managerEmployee = Employee::factory()->create(['company_id' => $company->getKey(), 'user_id' => $manager->getKey()]);
    $directEmployee = Employee::factory()->create(['company_id' => $company->getKey(), 'manager_id' => $managerEmployee->getKey()]);
    $unrelatedEmployee = Employee::factory()->create(['company_id' => $company->getKey()]);
    $directRequest = notificationCenterRequest($company, $directEmployee, User::factory()->create());
    $unrelatedRequest = notificationCenterRequest($company, $unrelatedEmployee, User::factory()->create());
    $manager->notify(new AttendanceCorrectionSubmitted($company->getKey(), $directRequest->getKey(), '2026-10-05', $directEmployee->full_name));
    $directNotification = $manager->notifications()->firstOrFail();
    notificationCenterRaw($manager, (int) $company->getKey(), AttendanceCorrectionSubmitted::class, ['regularization_id' => $unrelatedRequest->getKey()]);
    $unrelatedNotification = $manager->notifications()->where('id', '!=', $directNotification->getKey())->firstOrFail();
    notificationCenterRaw($manager, null, AttendanceCorrectionSubmitted::class, ['regularization_id' => $directRequest->getKey()]);
    $legacyNotification = $manager->notifications()->whereNull('company_id')->firstOrFail();
    notificationCenterRaw($manager, (int) $company->getKey(), 'UnsupportedNotification', ['regularization_id' => $directRequest->getKey()]);
    $unsupportedNotification = $manager->notifications()->where('type', 'UnsupportedNotification')->firstOrFail();

    $this->actingAs($manager)->withSession(['active_company_id' => $company->getKey()])
        ->post(route('notifications.read-all'))
        ->assertRedirect();

    expect($directNotification->fresh()->read_at)->not->toBeNull()
        ->and($unrelatedNotification->fresh()->read_at)->toBeNull()
        ->and($legacyNotification->fresh()->read_at)->toBeNull()
        ->and($unsupportedNotification->fresh()->read_at)->toBeNull();
});

test('employee portal notification center keeps explicit identity and company boundary', function () {
    $firstCompany = Company::factory()->create();
    $employeeUser = notificationCenterMember($firstCompany, 'employee');
    $firstEmployee = Employee::factory()->create(['company_id' => $firstCompany->getKey(), 'user_id' => $employeeUser->getKey()]);
    $firstRequest = notificationCenterRequest($firstCompany, $firstEmployee, User::factory()->create(), AttendanceRegularizationStatus::Approved);
    $employeeUser->notify(new AttendanceCorrectionApproved($firstCompany->getKey(), $firstRequest->getKey(), '2026-10-05', $firstEmployee->full_name));
    $firstNotification = $employeeUser->notifications()->firstOrFail();

    $secondCompany = Company::factory()->create();
    CompanyUser::query()->create(['company_id' => $secondCompany->getKey(), 'user_id' => $employeeUser->getKey(), 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($secondCompany);
    $registrar = app(PermissionRegistrar::class);
    $previousTeamId = $registrar->getPermissionsTeamId();
    $registrar->setPermissionsTeamId($secondCompany->getKey());
    $employeeUser->unsetRelation('roles')->unsetRelation('permissions')->assignRole('employee');
    $registrar->setPermissionsTeamId($previousTeamId);
    $secondEmployee = Employee::factory()->create(['company_id' => $secondCompany->getKey(), 'user_id' => $employeeUser->getKey()]);
    $secondRequest = notificationCenterRequest($secondCompany, $secondEmployee, User::factory()->create(), AttendanceRegularizationStatus::Rejected);
    $employeeUser->notify(new AttendanceCorrectionRejected($secondCompany->getKey(), $secondRequest->getKey(), '2026-10-05', $secondEmployee->full_name));
    $secondNotification = $employeeUser->notifications()->where('company_id', $secondCompany->getKey())->firstOrFail();

    $this->actingAs($employeeUser)->withSession(['active_company_id' => $firstCompany->getKey()])
        ->get(route('employee.notifications.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('employee-portal/notifications', false)
            ->where('employeePortal.employeeName', $firstEmployee->full_name)
            ->where('auth.user', null)
            ->where('activeCompany', null)
            ->where('companies', [])
            ->has('notificationCenter.notifications', 1)
            ->missing('notificationCenter.notifications.0.regularization_id'));

    $this->withSession(['active_company_id' => $secondCompany->getKey()])
        ->get(route('employee.notifications.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('employeePortal.employeeName', $secondEmployee->full_name)
            ->has('notificationCenter.notifications', 1)
            ->where('notificationCenter.notifications.0.title', 'Attendance correction rejected'));

    $this->post(route('employee.notifications.read', $firstNotification->getKey()))->assertNotFound();
    $this->post(route('employee.notifications.read', $secondNotification->getKey()))->assertRedirect();
    expect($firstNotification->fresh()->read_at)->toBeNull()
        ->and($secondNotification->fresh()->read_at)->not->toBeNull();
});

test('notification get is read only and missing destinations do not affect visible counts', function () {
    $company = Company::factory()->create();
    $reviewer = notificationCenterMember($company, 'hr_admin');
    $employee = Employee::factory()->create(['company_id' => $company->getKey()]);
    $request = notificationCenterRequest($company, $employee, User::factory()->create());
    $reviewer->notify(new AttendanceCorrectionSubmitted($company->getKey(), $request->getKey(), '2026-10-05', $employee->full_name));
    $validNotification = $reviewer->notifications()->firstOrFail();
    notificationCenterRaw($reviewer, (int) $company->getKey(), AttendanceCorrectionSubmitted::class, ['regularization_id' => 999999999]);

    $this->actingAs($reviewer)->withSession(['active_company_id' => $company->getKey()])
        ->get(route('notifications.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('notificationCenter.unread_count', 1)
            ->where('notificationCenter.pagination.total', 1)
            ->has('notificationCenter.notifications', 1));

    expect($validNotification->fresh()->read_at)->toBeNull()
        ->and($reviewer->notifications()->where('company_id', $company->getKey())->count())->toBe(2)
        ->and(AttendanceRegularizationRequest::query()->where('company_id', $company->getKey())->count())->toBe(1);
});

test('notification center requires an active company membership even for a super admin', function () {
    $company = Company::factory()->create();
    $superAdmin = User::factory()->create();
    app(RolePermissionSeeder::class)->run();
    $registrar = app(PermissionRegistrar::class);
    $previousTeamId = $registrar->getPermissionsTeamId();
    $registrar->setPermissionsTeamId(User::SYSTEM_TEAM_ID);
    $superAdmin->assignRole('super_admin');
    $registrar->setPermissionsTeamId($previousTeamId);

    $this->actingAs($superAdmin)->withSession(['active_company_id' => $company->getKey()])
        ->get(route('notifications.index'))
        ->assertForbidden();

    $inactiveMember = notificationCenterMember($company, 'hr_admin');
    CompanyUser::query()->where('company_id', $company->getKey())->where('user_id', $inactiveMember->getKey())->update(['status' => 'inactive']);

    $this->actingAs($inactiveMember)->withSession(['active_company_id' => $company->getKey()])
        ->get(route('notifications.index'))
        ->assertForbidden();
});
