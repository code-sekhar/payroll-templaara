<?php

use App\Actions\SeedCompanyRoles;
use App\Enums\AttendanceRegularizationStatus;
use App\Models\AttendancePunch;
use App\Models\AttendanceRegularizationRequest;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\DailyAttendanceRecord;
use App\Models\Employee;
use App\Models\EmployeeWorkScheduleAssignment;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

/** @return array{Company, User, Employee} */
function employeeCorrectionIdentity(?Company $company = null, ?User $user = null, array $employeeAttributes = []): array
{
    $company ??= Company::factory()->create(['timezone' => 'Asia/Kolkata']);
    $user ??= User::factory()->create();
    CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $user->id, 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($company);
    $registrar = app(PermissionRegistrar::class);
    $previousTeamId = $registrar->getPermissionsTeamId();
    $registrar->setPermissionsTeamId($company->id);
    $user->unsetRelation('roles')->unsetRelation('permissions')->assignRole('employee');
    $registrar->setPermissionsTeamId($previousTeamId);
    $employee = Employee::factory()->create(array_merge([
        'company_id' => $company->id,
        'user_id' => $user->id,
        'joining_date' => '2024-01-01',
    ], $employeeAttributes));

    return [$company, $user, $employee];
}

function correctionRequestPayload(string $date = '2026-10-05', array $overrides = []): array
{
    return array_merge([
        'attendance_date' => $date,
        'request_type' => 'missing_check_in',
        'reason' => 'I forgot to punch in when I arrived at work.',
        'items' => [[
            'action' => 'add',
            'punch_type' => 'check_in',
            'requested_punched_at' => '2026-10-05T09:02',
        ]],
    ], $overrides);
}

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00:00', 'Asia/Kolkata'));
});

test('employee can submit a correction for a date without materialized attendance and attendance remains unchanged', function () {
    [$company, $user, $employee] = employeeCorrectionIdentity();
    $punchCount = AttendancePunch::query()->count();
    $recordCount = DailyAttendanceRecord::query()->count();

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->post(route('employee.attendance.corrections.store'), correctionRequestPayload())
        ->assertRedirect(route('employee.attendance.index', ['month' => '2026-10']))
        ->assertSessionHas('success');

    $request = AttendanceRegularizationRequest::query()->with('items')->firstOrFail();
    expect($request->company_id)->toBe($company->id)
        ->and($request->employee_id)->toBe($employee->id)
        ->and($request->requested_by)->toBe($user->id)
        ->and($request->status)->toBe(AttendanceRegularizationStatus::Pending)
        ->and($request->items)->toHaveCount(1)
        ->and(AttendancePunch::query()->count())->toBe($punchCount)
        ->and(DailyAttendanceRecord::query()->count())->toBe($recordCount);
});

test('create form provides only own scoped attendance context and opaque punch references', function () {
    [$company, $user, $employee] = employeeCorrectionIdentity();
    $punch = AttendancePunch::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'punched_at' => CarbonImmutable::parse('2026-10-05 09:00', $company->timezone)->utc(),
    ]);

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->get(route('employee.attendance.corrections.create', ['attendance_date' => '2026-10-05']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('employee-portal/attendance-correction-create', false)
            ->where('context.date', '2026-10-05')
            ->where('context.timezone', 'Asia/Kolkata')
            ->where('context.punches.0.type', 'check_in')
            ->where('context.punches.0.time', '2026-10-05T09:00')
            ->missing('context.punches.0.id')
            ->missing('employeePortal.employee_id')
            ->missing('employeePortal.company_id')
            ->where('auth.user', null)
            ->where('activeCompany', null));

    expect($punch->exists)->toBeTrue();
});

test('repeated correction context and history reads are strictly read only', function () {
    [$company, $user, $employee] = employeeCorrectionIdentity();
    $punch = AttendancePunch::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'punched_at' => CarbonImmutable::parse('2026-10-05 09:00', $company->timezone)->utc(),
    ]);
    $record = DailyAttendanceRecord::factory()->incomplete()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_date' => '2026-10-05',
    ]);
    $punchSnapshot = $punch->refresh()->getAttributes();
    $recordSnapshot = $record->refresh()->getAttributes();
    $this->actingAs($user)->withSession(['active_company_id' => $company->id]);

    $this->get(route('employee.attendance.corrections.create', ['attendance_date' => '2026-10-05']))->assertOk();
    $this->get(route('employee.attendance.corrections.create', ['attendance_date' => '2026-10-05']))->assertOk();
    $this->get(route('employee.attendance.index', ['month' => '2026-10']))->assertOk();
    $this->get(route('employee.attendance.index', ['month' => '2026-10']))->assertOk();

    $storedPunch = AttendancePunch::query()->findOrFail($punch->id)->getAttributes();
    $storedRecord = DailyAttendanceRecord::query()->findOrFail($record->id)->getAttributes();
    ksort($punchSnapshot);
    ksort($recordSnapshot);
    ksort($storedPunch);
    ksort($storedRecord);
    expect(AttendanceRegularizationRequest::query()->count())->toBe(0)
        ->and($storedPunch)->toBe($punchSnapshot)
        ->and($storedRecord)->toBe($recordSnapshot);
});

test('incorrect punch request resolves opaque reference server-side and retains original punch unchanged', function () {
    [$company, $user, $employee] = employeeCorrectionIdentity();
    $punch = AttendancePunch::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'punch_type' => 'check_in',
        'punched_at' => CarbonImmutable::parse('2026-10-05 09:00', $company->timezone)->utc(),
    ]);
    $punchAttributes = $punch->refresh()->getAttributes();
    $response = $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->get(route('employee.attendance.corrections.create', ['attendance_date' => '2026-10-05']));
    $reference = $response->viewData('page')['props']['context']['punches'][0]['reference'];

    $this->post(route('employee.attendance.corrections.store'), correctionRequestPayload('2026-10-05', [
        'request_type' => 'incorrect_punch',
        'items' => [[
            'action' => 'replace',
            'punch_reference' => $reference,
            'punch_type' => 'check_in',
            'requested_punched_at' => '2026-10-05T09:10',
        ]],
    ]))->assertRedirect();

    $item = AttendanceRegularizationRequest::query()->firstOrFail()->items()->firstOrFail();
    $persistedPunchAttributes = AttendancePunch::query()->findOrFail($punch->id)->getAttributes();
    ksort($punchAttributes);
    ksort($persistedPunchAttributes);
    expect($item->attendance_punch_id)->toBe($punch->id)
        ->and($persistedPunchAttributes)->toBe($punchAttributes);
});

test('portal request accepts a valid overnight requested checkout on the following local date', function () {
    [$company, $user, $employee] = employeeCorrectionIdentity();
    $shift = Shift::factory()->create([
        'company_id' => $company->id,
        'start_time' => '22:00',
        'end_time' => '06:00',
        'is_overnight' => true,
    ]);
    $schedule = WorkSchedule::factory()->create(['company_id' => $company->id]);
    $schedule->days()->update(['day_type' => 'working', 'shift_id' => $shift->id]);
    EmployeeWorkScheduleAssignment::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'work_schedule_id' => $schedule->id,
        'effective_from' => '2026-10-05',
    ]);

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->post(route('employee.attendance.corrections.store'), correctionRequestPayload('2026-10-05', [
            'request_type' => 'missing_check_out',
            'items' => [[
                'action' => 'add',
                'punch_type' => 'check_out',
                'requested_punched_at' => '2026-10-06T02:10',
            ]],
        ]))
        ->assertRedirect();

    $item = AttendanceRegularizationRequest::query()->firstOrFail()->items()->firstOrFail();
    expect($item->requested_punched_at->setTimezone($company->timezone)->format('Y-m-d H:i'))->toBe('2026-10-06 02:10')
        ->and($item->timezone)->toBe('Asia/Kolkata');
});

test('employee attendance shows bounded own correction history with cancellation capability and no database identifiers', function () {
    [$company, $user, $employee] = employeeCorrectionIdentity();
    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->post(route('employee.attendance.corrections.store'), correctionRequestPayload());

    $this->get(route('employee.attendance.index', ['month' => '2026-10']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('employee-portal/attendance', false)
            ->where('canRequestCorrection', true)
            ->where('canViewCorrections', true)
            ->has('correctionRequests', 1)
            ->where('correctionRequests.0.typeLabel', 'Missing check-in')
            ->where('correctionRequests.0.status', 'pending')
            ->where('correctionRequests.0.canCancel', true)
            ->missing('correctionRequests.0.id')
            ->missing('correctionRequests.0.employee_id')
            ->missing('correctionRequests.0.company_id')
            ->where('employeeAttendance.days.4.correctionPending', true));

    expect($employee->exists)->toBeTrue();
});

test('employee can cancel own pending request but attendance evidence is not changed', function () {
    [$company, $user, $employee] = employeeCorrectionIdentity();
    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->post(route('employee.attendance.corrections.store'), correctionRequestPayload());
    $request = AttendanceRegularizationRequest::query()->firstOrFail();
    $reference = hash_hmac('sha256', implode('|', [$company->id, $employee->id, $request->id]), (string) config('app.key'));
    $requestAttributes = $request->getAttributes();
    $punchCount = AttendancePunch::query()->count();
    $recordCount = DailyAttendanceRecord::query()->count();

    $this->post(route('employee.attendance.corrections.cancel', ['attendanceDate' => '2026-10-05', 'requestReference' => $reference]))
        ->assertRedirect(route('employee.attendance.index', ['month' => '2026-10']))
        ->assertSessionHas('success');

    expect(AttendanceRegularizationRequest::query()->findOrFail($request->id)->status)->toBe(AttendanceRegularizationStatus::Cancelled)
        ->and(AttendancePunch::query()->count())->toBe($punchCount)
        ->and(DailyAttendanceRecord::query()->count())->toBe($recordCount)
        ->and($requestAttributes['reason'])->toBe('I forgot to punch in when I arrived at work.');
});

test('foreign employee request cannot be targeted through portal correction history or cancellation', function () {
    [$company, $user] = employeeCorrectionIdentity();
    $coworker = Employee::factory()->create(['company_id' => $company->id]);
    $request = AttendanceRegularizationRequest::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $coworker->id,
        'requested_by' => $user->id,
        'attendance_date' => '2026-10-05',
    ]);
    $reference = hash_hmac('sha256', implode('|', [$company->id, $coworker->id, $request->id]), (string) config('app.key'));

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->post(route('employee.attendance.corrections.cancel', ['attendanceDate' => '2026-10-05', 'requestReference' => $reference]))
        ->assertNotFound();

    expect(AttendanceRegularizationRequest::query()->findOrFail($request->id)->status)->toBe(AttendanceRegularizationStatus::Pending);
});

test('portal cancellation is unavailable after a request leaves pending status', function (string $status): void {
    [$company, $user, $employee] = employeeCorrectionIdentity();
    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->post(route('employee.attendance.corrections.store'), correctionRequestPayload());
    $request = AttendanceRegularizationRequest::query()->firstOrFail();
    $request->status = AttendanceRegularizationStatus::from($status);
    $request->save();
    $reference = hash_hmac('sha256', implode('|', [$company->id, $employee->id, $request->id]), (string) config('app.key'));

    $this->post(route('employee.attendance.corrections.cancel', ['attendanceDate' => '2026-10-05', 'requestReference' => $reference]))
        ->assertNotFound();

    expect(AttendanceRegularizationRequest::query()->findOrFail($request->id)->status->value)->toBe($status);
})->with(['approved', 'rejected', 'cancelled']);

test('portal request rejects forged identity, status and original punch fields', function () {
    [$company, $user] = employeeCorrectionIdentity();
    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->post(route('employee.attendance.corrections.store'), correctionRequestPayload('2026-10-05', [
            'employee_id' => 999,
            'company_id' => 999,
            'user_id' => 999,
            'status' => 'approved',
            'items' => [[
                'action' => 'replace',
                'attendance_punch_id' => 999,
                'punch_type' => 'check_in',
                'requested_punched_at' => '2026-10-05T09:10',
            ]],
        ]))
        ->assertSessionHasErrors(['employee_id', 'company_id', 'user_id', 'status', 'items.0.attendance_punch_id']);

    expect(AttendanceRegularizationRequest::query()->count())->toBe(0);
});

test('portal request rejects whitespace reason future date and pre-employment date', function () {
    [$company, $user] = employeeCorrectionIdentity(employeeAttributes: ['joining_date' => '2026-10-06']);
    $this->actingAs($user)->withSession(['active_company_id' => $company->id]);

    $this->post(route('employee.attendance.corrections.store'), correctionRequestPayload('2026-10-07', ['attendance_date' => '2026-10-07']))
        ->assertSessionHasErrors('attendance_date');
    $this->post(route('employee.attendance.corrections.store'), correctionRequestPayload('2025-12-31'))
        ->assertSessionHasErrors('attendance_date');
    $this->post(route('employee.attendance.corrections.store'), correctionRequestPayload('2026-10-05', ['reason' => '          ']))
        ->assertSessionHasErrors('reason');
    $this->get(route('employee.attendance.index', ['month' => '2026-10']))
        ->assertInertia(fn (Assert $page) => $page->where('employeeAttendance.days.4.withinEmploymentPeriod', false));

    expect(AttendanceRegularizationRequest::query()->count())->toBe(0);
});

test('pending request blocks a second request for the same employee date', function () {
    [$company, $user] = employeeCorrectionIdentity();
    $this->actingAs($user)->withSession(['active_company_id' => $company->id]);

    $this->post(route('employee.attendance.corrections.store'), correctionRequestPayload())->assertRedirect();
    $this->post(route('employee.attendance.corrections.store'), correctionRequestPayload())->assertSessionHasErrors('attendance_date');

    expect(AttendanceRegularizationRequest::query()->count())->toBe(1);
});

test('unauthenticated users cannot access the portal correction endpoints', function () {
    $this->get(route('employee.attendance.corrections.create', ['attendance_date' => '2026-10-05']))->assertRedirect();
    $this->post(route('employee.attendance.corrections.store'), correctionRequestPayload())->assertRedirect();
});

test('same user uses the employee linked in the active company when submitting a correction', function () {
    [$companyA, $user, $employeeA] = employeeCorrectionIdentity();
    $companyB = Company::factory()->create(['timezone' => 'Asia/Dhaka']);
    CompanyUser::query()->create(['company_id' => $companyB->id, 'user_id' => $user->id, 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($companyB);
    $registrar = app(PermissionRegistrar::class);
    $previousTeamId = $registrar->getPermissionsTeamId();
    $registrar->setPermissionsTeamId($companyB->id);
    $user->unsetRelation('roles')->unsetRelation('permissions')->assignRole('employee');
    $registrar->setPermissionsTeamId($previousTeamId);
    $employeeB = Employee::factory()->create([
        'company_id' => $companyB->id,
        'user_id' => $user->id,
        'joining_date' => '2024-01-01',
    ]);

    $this->actingAs($user)->withSession(['active_company_id' => $companyA->id])
        ->post(route('employee.attendance.corrections.store'), correctionRequestPayload())
        ->assertRedirect();
    $this->withSession(['active_company_id' => $companyB->id])
        ->post(route('employee.attendance.corrections.store'), correctionRequestPayload())
        ->assertRedirect();

    $requestsByEmployee = AttendanceRegularizationRequest::query()->orderBy('company_id')->get()->keyBy('company_id');
    expect($requestsByEmployee[$companyA->id]->employee_id)->toBe($employeeA->id)
        ->and($requestsByEmployee[$companyB->id]->employee_id)->toBe($employeeB->id);
});
