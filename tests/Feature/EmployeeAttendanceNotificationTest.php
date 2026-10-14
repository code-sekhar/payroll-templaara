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
use App\Services\Attendance\AttendanceRegularizationNotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

/** @return array{Company, User, Employee} */
function attendanceNotificationEmployee(string $companyName = 'Notification Co'): array
{
    $company = Company::factory()->create(['name' => $companyName, 'timezone' => 'Asia/Kolkata']);
    $user = User::factory()->create();
    CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $user->id, 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($company);
    $registrar = app(PermissionRegistrar::class);
    $previousTeamId = $registrar->getPermissionsTeamId();
    $registrar->setPermissionsTeamId($company->id);
    $user->unsetRelation('roles')->unsetRelation('permissions')->assignRole('employee');
    $registrar->setPermissionsTeamId($previousTeamId);
    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'user_id' => $user->id,
        'joining_date' => '2024-01-01',
    ]);

    return [$company, $user, $employee];
}

function attendanceNotificationReviewer(Company $company, string $role = 'hr_admin'): User
{
    $user = User::factory()->create();
    CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $user->id, 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($company);
    $registrar = app(PermissionRegistrar::class);
    $previousTeamId = $registrar->getPermissionsTeamId();
    $registrar->setPermissionsTeamId($company->id);
    $user->unsetRelation('roles')->unsetRelation('permissions')->assignRole($role);
    $registrar->setPermissionsTeamId($previousTeamId);

    return $user;
}

function attendanceNotificationRequest(Company $company, Employee $employee, User $requester, AttendanceRegularizationStatus $status = AttendanceRegularizationStatus::Pending): AttendanceRegularizationRequest
{
    return AttendanceRegularizationRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_date' => '2026-10-05',
        'request_type' => 'other',
        'status' => $status,
        'reason' => 'Private employee reason that must not appear in notification.',
        'requested_by' => $requester->id,
        'requested_at' => CarbonImmutable::parse('2026-10-05 12:00:00', 'Asia/Kolkata')->utc(),
    ]);
}

test('employee correction submission notifies only active scoped reviewers and keeps reason private', function () {
    [$company, $employeeUser, $employee] = attendanceNotificationEmployee();
    $reviewer = attendanceNotificationReviewer($company);
    $managerWithoutReviewPermission = attendanceNotificationReviewer($company, 'manager');
    $otherCompany = Company::factory()->create();
    $otherReviewer = attendanceNotificationReviewer($otherCompany);

    $this->actingAs($employeeUser)->withSession(['active_company_id' => $company->id])
        ->post(route('employee.attendance.corrections.store'), [
            'attendance_date' => '2026-10-05',
            'request_type' => 'missing_check_in',
            'reason' => 'Private correction reason.',
            'items' => [[
                'action' => 'add',
                'punch_type' => 'check_in',
                'requested_punched_at' => '2026-10-05T09:02',
            ]],
        ])->assertRedirect(route('employee.attendance.index', ['month' => '2026-10']));

    $regularization = AttendanceRegularizationRequest::query()->firstOrFail();
    expect($regularization->employee_id)->toBe($employee->id)
        ->and($reviewer->notifications()->where('type', AttendanceCorrectionSubmitted::class)->count())->toBe(1)
        ->and($reviewer->notifications()->where('company_id', $company->id)->count())->toBe(1)
        ->and($employeeUser->notifications()->count())->toBe(0)
        ->and($managerWithoutReviewPermission->notifications()->count())->toBe(0)
        ->and($otherReviewer->notifications()->count())->toBe(0);

    $notificationData = $reviewer->notifications()->firstOrFail()->data;
    expect($notificationData)->toHaveKeys(['company_id', 'regularization_id', 'attendance_date', 'title', 'message'])
        ->and($notificationData['message'])->toContain($employee->full_name)
        ->and($notificationData['message'])->not->toContain('Private correction reason')
        ->and($notificationData)->not->toHaveKey('reason')
        ->and($notificationData)->not->toHaveKey('notes');
});

test('manager with explicit review permission receives only direct-report correction notifications', function () {
    [$company, $employeeUser, $employee] = attendanceNotificationEmployee();
    $manager = attendanceNotificationReviewer($company, 'manager');
    $managerEmployee = Employee::factory()->create(['company_id' => $company->id, 'user_id' => $manager->id]);
    $employee->update(['manager_id' => $managerEmployee->id]);
    $unrelatedManager = attendanceNotificationReviewer($company, 'manager');
    $registrar = app(PermissionRegistrar::class);
    $previousTeamId = $registrar->getPermissionsTeamId();
    $registrar->setPermissionsTeamId($company->id);
    $manager->unsetRelation('roles')->unsetRelation('permissions')->givePermissionTo('attendance_regularization.approve');
    $registrar->setPermissionsTeamId($previousTeamId);

    $this->actingAs($employeeUser)->withSession(['active_company_id' => $company->id])
        ->post(route('employee.attendance.corrections.store'), [
            'attendance_date' => '2026-10-05',
            'request_type' => 'missing_check_in',
            'reason' => 'I missed my check in.',
            'items' => [[
                'action' => 'add',
                'punch_type' => 'check_in',
                'requested_punched_at' => '2026-10-05T09:02',
            ]],
        ])->assertRedirect();

    expect($manager->notifications()->where('type', AttendanceCorrectionSubmitted::class)->count())->toBe(1)
        ->and($unrelatedManager->notifications()->count())->toBe(0);
});

test('management notification inbox is company scoped and opens only currently viewable requests', function () {
    [$company, $employeeUser, $employee] = attendanceNotificationEmployee();
    $reviewer = attendanceNotificationReviewer($company);
    [$otherCompany, , $otherEmployee] = attendanceNotificationEmployee('Other Notification Co');
    $localRequest = attendanceNotificationRequest($company, $employee, $employeeUser);
    $foreignRequest = attendanceNotificationRequest($otherCompany, $otherEmployee, User::factory()->create());
    CompanyUser::query()->create(['company_id' => $otherCompany->id, 'user_id' => $reviewer->id, 'status' => 'active']);
    $reviewer->notify(new AttendanceCorrectionSubmitted($company->id, $localRequest->id, '2026-10-05', $employee->full_name));
    $reviewer->notify(new AttendanceCorrectionSubmitted($otherCompany->id, $foreignRequest->id, '2026-10-05', $otherEmployee->full_name));

    $this->actingAs($reviewer)->withSession(['active_company_id' => $company->id])
        ->get(route('companies.notifications.index', $company))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('attendance-notifications/index', false)
            ->has('notifications.notifications', 1)
            ->where('notifications.notifications.0.attendance_date', '2026-10-05')
            ->where('auth.user.id', $reviewer->id)
            ->missing('notifications.notifications.0.company_id')
            ->missing('notifications.notifications.0.employee_id'));

    $foreignNotification = $reviewer->notifications()->where('data->regularization_id', $foreignRequest->id)->firstOrFail();
    expect((int) $foreignNotification->company_id)->toBe((int) $otherCompany->id);
    $this->post(route('companies.notifications.open', [$company, $foreignNotification]))->assertNotFound();
    expect($foreignNotification->fresh()->read_at)->toBeNull();
});

test('company-specific attendance inboxes exclude and cannot open legacy null-owned notifications', function () {
    [$company, $employeeUser, $employee] = attendanceNotificationEmployee();
    $reviewer = attendanceNotificationReviewer($company);
    $request = attendanceNotificationRequest($company, $employee, $employeeUser);
    $employeeRequest = attendanceNotificationRequest($company, $employee, User::factory()->create(), AttendanceRegularizationStatus::Approved);

    $legacyManagementNotification = $reviewer->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => AttendanceCorrectionSubmitted::class,
        'data' => ['regularization_id' => $request->getKey(), 'message' => 'Legacy notification.'],
    ]);
    $legacyEmployeeNotification = $employeeUser->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => AttendanceCorrectionApproved::class,
        'data' => ['regularization_id' => $employeeRequest->getKey(), 'message' => 'Legacy notification.'],
    ]);

    expect($legacyManagementNotification->company_id)->toBeNull()
        ->and($legacyEmployeeNotification->company_id)->toBeNull();

    $this->actingAs($reviewer)->withSession(['active_company_id' => $company->id])
        ->get(route('companies.notifications.index', $company))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('notifications.notifications', 0)
            ->where('notifications.unread_count', 0));

    $this->post(route('companies.notifications.open', [$company, $legacyManagementNotification]))->assertNotFound();

    $this->actingAs($employeeUser)->withSession(['active_company_id' => $company->id])
        ->get(route('employee.notifications.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('notificationCenter.notifications', 0)
            ->where('notificationCenter.unread_count', 0));

    $this->post(route('employee.notifications.open', $legacyEmployeeNotification))->assertNotFound();

    expect($legacyManagementNotification->fresh()->read_at)->toBeNull()
        ->and($legacyEmployeeNotification->fresh()->read_at)->toBeNull();
});

test('employee receives approval and rejection notices only for their linked employee in the selected company', function () {
    [$company, $employeeUser, $employee] = attendanceNotificationEmployee();
    $reviewer = attendanceNotificationReviewer($company);
    $approvedRequest = attendanceNotificationRequest($company, $employee, User::factory()->create(), AttendanceRegularizationStatus::Approved);
    $rejectedRequest = attendanceNotificationRequest($company, $employee, User::factory()->create(), AttendanceRegularizationStatus::Rejected);
    [, , $foreignEmployee] = attendanceNotificationEmployee('Foreign Company');
    $foreignRequest = attendanceNotificationRequest($foreignEmployee->company, $foreignEmployee, User::factory()->create(), AttendanceRegularizationStatus::Approved);

    app(AttendanceRegularizationNotificationService::class)->correctionApproved($approvedRequest);
    app(AttendanceRegularizationNotificationService::class)->correctionRejected($rejectedRequest);
    app(AttendanceRegularizationNotificationService::class)->correctionApproved($foreignRequest);

    $this->actingAs($employeeUser)->withSession(['active_company_id' => $company->id])
        ->get(route('employee.notifications.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('employee-portal/notifications', false)
            ->has('notificationCenter.notifications', 2)
            ->where('employeePortal.employeeName', $employee->full_name)
            ->where('auth.user', null)
            ->where('activeCompany', null)
            ->where('companies', [])
            ->missing('notificationCenter.notifications.0.user_id'));

    expect($employeeUser->notifications()->where('type', AttendanceCorrectionApproved::class)->count())->toBe(1)
        ->and($employeeUser->notifications()->where('type', AttendanceCorrectionRejected::class)->count())->toBe(1)
        ->and($reviewer->notifications()->count())->toBe(0);
});

test('employee notification opening requires owned active-company notification and matching employee request', function () {
    [$company, $employeeUser, $employee] = attendanceNotificationEmployee();
    $request = attendanceNotificationRequest($company, $employee, User::factory()->create(), AttendanceRegularizationStatus::Approved);
    $employeeUser->notify(new AttendanceCorrectionApproved($company->id, $request->id, '2026-10-05', $employee->full_name));
    $notification = $employeeUser->notifications()->firstOrFail();

    $this->actingAs($employeeUser)->withSession(['active_company_id' => $company->id])
        ->post(route('employee.notifications.open', $notification))
        ->assertRedirect(route('employee.attendance.index', ['month' => '2026-10']));

    expect($notification->fresh()->read_at)->not->toBeNull()
        ->and($request->refresh()->status)->toBe(AttendanceRegularizationStatus::Approved);
});

test('notification delivery failure does not roll back a submitted correction request', function () {
    [$company, $employeeUser, $employee] = attendanceNotificationEmployee();
    attendanceNotificationReviewer($company);
    app()->instance(Dispatcher::class, Mockery::mock(Dispatcher::class)->shouldReceive('send')->andThrow(new RuntimeException('Notification storage unavailable.'))->getMock());

    $this->actingAs($employeeUser)->withSession(['active_company_id' => $company->id])
        ->post(route('employee.attendance.corrections.store'), [
            'attendance_date' => '2026-10-05',
            'request_type' => 'missing_check_in',
            'reason' => 'I missed my check in.',
            'items' => [[
                'action' => 'add',
                'punch_type' => 'check_in',
                'requested_punched_at' => '2026-10-05T09:02',
            ]],
        ])->assertRedirect();

    $request = AttendanceRegularizationRequest::query()->firstOrFail();
    expect($request->employee_id)->toBe($employee->id)
        ->and($request->status)->toBe(AttendanceRegularizationStatus::Pending);
});

test('rejection creates one employee notification and a stale retry creates no duplicate', function () {
    [$company, $employeeUser, $employee] = attendanceNotificationEmployee();
    $reviewer = attendanceNotificationReviewer($company);
    $request = attendanceNotificationRequest($company, $employee, User::factory()->create());

    $this->actingAs($reviewer)->withSession(['active_company_id' => $company->id])
        ->post(route('companies.attendance-regularizations.reject', [$company, $request]), ['review_note' => 'The submitted time could not be confirmed.'])
        ->assertRedirect();

    expect($request->refresh()->status)->toBe(AttendanceRegularizationStatus::Rejected)
        ->and($employeeUser->notifications()->where('type', AttendanceCorrectionRejected::class)->count())->toBe(1);

    $this->post(route('companies.attendance-regularizations.reject', [$company, $request]), ['review_note' => 'A stale retry.'])
        ->assertSessionHasErrors('request');

    expect($employeeUser->notifications()->where('type', AttendanceCorrectionRejected::class)->count())->toBe(1)
        ->and($request->refresh()->status)->toBe(AttendanceRegularizationStatus::Rejected);
});

test('employee notification route denies access after company membership is removed', function () {
    [$company, $employeeUser, $employee] = attendanceNotificationEmployee();
    $request = attendanceNotificationRequest($company, $employee, User::factory()->create(), AttendanceRegularizationStatus::Approved);
    $employeeUser->notify(new AttendanceCorrectionApproved($company->id, $request->id, '2026-10-05', $employee->full_name));
    CompanyUser::query()->where('company_id', $company->id)->where('user_id', $employeeUser->id)->update(['status' => 'inactive']);

    $this->actingAs($employeeUser)->withSession(['active_company_id' => $company->id])
        ->get(route('employee.notifications.index'))
        ->assertForbidden();
});

test('reviewer who loses request visibility cannot open a prior notification', function () {
    [$company, $employeeUser, $employee] = attendanceNotificationEmployee();
    $reviewer = attendanceNotificationReviewer($company);
    $request = attendanceNotificationRequest($company, $employee, $employeeUser);
    $reviewer->notify(new AttendanceCorrectionSubmitted($company->id, $request->id, '2026-10-05', $employee->full_name));
    $notification = $reviewer->notifications()->firstOrFail();
    $registrar = app(PermissionRegistrar::class);
    $previousTeamId = $registrar->getPermissionsTeamId();
    $registrar->setPermissionsTeamId($company->id);
    $reviewer->unsetRelation('roles')->unsetRelation('permissions')->syncRoles('manager');
    $registrar->setPermissionsTeamId($previousTeamId);

    $this->actingAs($reviewer)->withSession(['active_company_id' => $company->id])
        ->post(route('companies.notifications.open', [$company, $notification]))
        ->assertForbidden();

    expect($notification->fresh()->read_at)->toBeNull()
        ->and($request->refresh()->status)->toBe(AttendanceRegularizationStatus::Pending);
});

test('another user cannot open or mark a reviewer notification as read', function () {
    [$company, $employeeUser, $employee] = attendanceNotificationEmployee();
    $reviewer = attendanceNotificationReviewer($company);
    $request = attendanceNotificationRequest($company, $employee, $employeeUser);
    $reviewer->notify(new AttendanceCorrectionSubmitted($company->id, $request->id, '2026-10-05', $employee->full_name));
    $notification = $reviewer->notifications()->firstOrFail();
    $otherUser = attendanceNotificationReviewer($company);

    $this->actingAs($otherUser)->withSession(['active_company_id' => $company->id])
        ->post(route('companies.notifications.open', [$company, $notification]))
        ->assertNotFound();

    expect($notification->fresh()->read_at)->toBeNull()
        ->and($request->refresh()->status)->toBe(AttendanceRegularizationStatus::Pending);
});

test('cancelling a pending correction does not send an asynchronous notification', function () {
    [$company, $employeeUser, $employee] = attendanceNotificationEmployee();
    $reviewer = attendanceNotificationReviewer($company);
    $request = attendanceNotificationRequest($company, $employee, User::factory()->create());

    $this->actingAs($reviewer)->withSession(['active_company_id' => $company->id])
        ->post(route('companies.attendance-regularizations.cancel', [$company, $request]))
        ->assertRedirect();

    expect($request->refresh()->status)->toBe(AttendanceRegularizationStatus::Cancelled)
        ->and($employeeUser->notifications()->count())->toBe(0);
});

test('same employee user sees only the selected company notification context', function () {
    [$firstCompany, $user, $firstEmployee] = attendanceNotificationEmployee();
    $secondCompany = Company::factory()->create(['timezone' => 'Asia/Kolkata']);
    CompanyUser::query()->create(['company_id' => $secondCompany->id, 'user_id' => $user->id, 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($secondCompany);
    $registrar = app(PermissionRegistrar::class);
    $previousTeamId = $registrar->getPermissionsTeamId();
    $registrar->setPermissionsTeamId($secondCompany->id);
    $user->unsetRelation('roles')->unsetRelation('permissions')->assignRole('employee');
    $registrar->setPermissionsTeamId($previousTeamId);
    $secondEmployee = Employee::factory()->create([
        'company_id' => $secondCompany->id,
        'user_id' => $user->id,
        'joining_date' => '2024-01-01',
    ]);
    $firstRequest = attendanceNotificationRequest($firstCompany, $firstEmployee, User::factory()->create(), AttendanceRegularizationStatus::Approved);
    $secondRequest = attendanceNotificationRequest($secondCompany, $secondEmployee, User::factory()->create(), AttendanceRegularizationStatus::Rejected);
    $user->notify(new AttendanceCorrectionApproved($firstCompany->id, $firstRequest->id, '2026-10-05', $firstEmployee->full_name));
    $user->notify(new AttendanceCorrectionRejected($secondCompany->id, $secondRequest->id, '2026-10-05', $secondEmployee->full_name));

    $this->actingAs($user)->withSession(['active_company_id' => $firstCompany->id])
        ->get(route('employee.notifications.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('notificationCenter.notifications', 1)
            ->where('notificationCenter.notifications.0.title', 'Attendance correction approved')
            ->where('employeePortal.employeeName', $firstEmployee->full_name));

    $this->withSession(['active_company_id' => $secondCompany->id])
        ->get(route('employee.notifications.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('notificationCenter.notifications', 1)
            ->where('notificationCenter.notifications.0.title', 'Attendance correction rejected')
            ->where('employeePortal.employeeName', $secondEmployee->full_name));
});

test('unlinked employee receives no outcome notification and workflow service returns safely', function () {
    $company = Company::factory()->create(['timezone' => 'Asia/Kolkata']);
    $employee = Employee::factory()->create(['company_id' => $company->id, 'user_id' => null]);
    $requester = User::factory()->create();
    $request = attendanceNotificationRequest($company, $employee, $requester, AttendanceRegularizationStatus::Approved);

    app(AttendanceRegularizationNotificationService::class)->correctionApproved($request);

    expect($requester->notifications()->count())->toBe(0)
        ->and($request->refresh()->status)->toBe(AttendanceRegularizationStatus::Approved)
        ->and($employee->refresh()->user_id)->toBeNull();
});
