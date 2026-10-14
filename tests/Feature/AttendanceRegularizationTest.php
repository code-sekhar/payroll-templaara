<?php

use App\Actions\SeedCompanyRoles;
use App\Enums\AttendancePunchType;
use App\Enums\AttendanceRegularizationStatus;
use App\Models\AttendancePunch;
use App\Models\AttendanceRegularizationItem;
use App\Models\AttendanceRegularizationRequest;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\DailyAttendanceRecord;
use App\Models\DailyAttendanceRecordHistory;
use App\Models\Employee;
use App\Models\EmployeeWorkScheduleAssignment;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

/** @return array{User, Company} */
function regularizationRoleFixture(string $role, string $timezone = 'Asia/Kolkata'): array
{
    $user = User::factory()->create();
    $company = Company::factory()->create(['timezone' => $timezone]);
    CompanyUser::create(['company_id' => $company->id, 'user_id' => $user->id, 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($company);
    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    return [$user, $company];
}

function regularizationEmployee(Company $company, ?User $user = null): Employee
{
    return Employee::factory()->create(['company_id' => $company->id, 'user_id' => $user?->id]);
}

/** @return array<string, mixed> */
function regularizationAttributes(Model $model): array
{
    $attributes = $model->refresh()->getAttributes();
    ksort($attributes);

    return $attributes;
}

/** @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function regularizationPayload(Employee $employee, array $overrides = []): array
{
    return array_merge([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-10-04',
        'request_type' => 'missing_check_out',
        'reason' => 'Forgot to check out before leaving the office.',
        'items' => [[
            'action' => 'add',
            'punch_type' => 'check_out',
            'requested_punched_at' => '2026-10-04T18:07',
            'notes' => 'Time confirmed by the supervisor.',
        ]],
    ], $overrides);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'Asia/Kolkata'));
});

test('HR can submit a pending request with correction items without changing attendance evidence', function () {
    [$hr, $company] = regularizationRoleFixture('hr_admin');
    $employee = regularizationEmployee($company);
    $punch = AttendancePunch::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'punched_at' => CarbonImmutable::parse('2026-10-04 09:00', $company->timezone)->utc(),
    ]);
    $record = DailyAttendanceRecord::factory()->incomplete()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_date' => '2026-10-04',
    ]);
    $recordUpdatedAt = $record->updated_at->toDateTimeString();
    $recordStatus = $record->status;
    $punchBefore = regularizationAttributes($punch);
    $historyCount = DailyAttendanceRecordHistory::query()->count();

    $this->actingAs($hr)->post(route('companies.attendance-regularizations.store', $company), regularizationPayload($employee))
        ->assertRedirect();

    $request = AttendanceRegularizationRequest::query()->with('items')->firstOrFail();
    expect($request->status)->toBe(AttendanceRegularizationStatus::Pending)
        ->and($request->requested_by)->toBe($hr->id)
        ->and($request->requested_at)->not->toBeNull()
        ->and($request->daily_attendance_record_id)->toBe($record->id)
        ->and($request->items)->toHaveCount(1)
        ->and(AttendancePunch::query()->count())->toBe(1)
        ->and(regularizationAttributes(AttendancePunch::query()->findOrFail($punch->id)))->toBe($punchBefore)
        ->and(DailyAttendanceRecord::query()->findOrFail($record->id)->updated_at->toDateTimeString())->toBe($recordUpdatedAt)
        ->and(DailyAttendanceRecord::query()->findOrFail($record->id)->status)->toBe($recordStatus)
        ->and(DailyAttendanceRecordHistory::query()->count())->toBe($historyCount);
});

test('an employee can submit and view their own request but cannot forge another employee identity', function () {
    [$employeeUser, $company] = regularizationRoleFixture('employee');
    $employee = regularizationEmployee($company, $employeeUser);
    $otherEmployee = regularizationEmployee($company);
    $url = route('companies.attendance-regularizations.self.store', $company);

    $this->actingAs($employeeUser)->post($url, regularizationPayload($employee, ['employee_id' => $otherEmployee->id]))
        ->assertSessionHasErrors('employee_id');
    expect(AttendanceRegularizationRequest::query()->count())->toBe(0);

    $selfPayload = regularizationPayload($employee);
    unset($selfPayload['employee_id']);
    $this->actingAs($employeeUser)->post($url, $selfPayload)
        ->assertRedirect();
    $created = AttendanceRegularizationRequest::query()->firstOrFail();
    expect($created->employee_id)->toBe($employee->id)->and($created->requested_by)->toBe($employeeUser->id);

    $indexResponse = $this->actingAs($employeeUser)->get(route('companies.attendance-regularizations.index', $company));
    $indexResponse
        ->assertInertia(fn (Assert $page) => $page->component('attendance-regularizations/index', false)->has('requests.data', 1));
});

test('company scope prevents requests for foreign employees and punches', function () {
    [$hr, $company] = regularizationRoleFixture('hr_admin');
    [, $foreignCompany] = regularizationRoleFixture('company_owner');
    $employee = regularizationEmployee($company);
    $foreignEmployee = regularizationEmployee($foreignCompany);

    $this->actingAs($hr)->post(route('companies.attendance-regularizations.store', $company), regularizationPayload($foreignEmployee))
        ->assertSessionHasErrors('employee_id');

    $foreignPunch = AttendancePunch::factory()->create([
        'company_id' => $foreignCompany->id,
        'employee_id' => $foreignEmployee->id,
        'punched_at' => CarbonImmutable::parse('2026-10-04 09:00', $company->timezone)->utc(),
    ]);
    $payload = regularizationPayload($employee, [
        'request_type' => 'duplicate_punch',
        'items' => [['action' => 'remove', 'attendance_punch_id' => $foreignPunch->id]],
    ]);

    $this->actingAs($hr)->post(route('companies.attendance-regularizations.store', $company), $payload)
        ->assertSessionHasErrors('items.0.attendance_punch_id');
    expect(AttendanceRegularizationRequest::query()->count())->toBe(0);
});

test('ADD REPLACE and REMOVE items validate structure and preserve the referenced raw punch', function () {
    [$hr, $company] = regularizationRoleFixture('hr_admin');
    $employee = regularizationEmployee($company);
    $punch = AttendancePunch::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'punch_type' => AttendancePunchType::CheckOut,
        'punched_at' => CarbonImmutable::parse('2026-10-04 18:00', $company->timezone)->utc(),
    ]);
    $original = regularizationAttributes($punch);

    $this->actingAs($hr)->post(route('companies.attendance-regularizations.store', $company), regularizationPayload($employee, [
        'request_type' => 'incorrect_punch',
        'items' => [['action' => 'replace', 'attendance_punch_id' => $punch->id, 'punch_type' => 'check_out', 'requested_punched_at' => '2026-10-04T18:10']],
    ]))->assertRedirect();

    $this->assertDatabaseHas('attendance_regularization_items', [
        'action' => 'replace', 'attendance_punch_id' => $punch->id, 'requested_punched_at' => '2026-10-04 12:40:00',
    ]);
    expect(regularizationAttributes(AttendancePunch::query()->findOrFail($punch->id)))->toBe($original);

    $this->actingAs($hr)->post(route('companies.attendance-regularizations.store', $company), regularizationPayload($employee, [
        'attendance_date' => '2026-10-03',
        'request_type' => 'duplicate_punch',
        'items' => [['action' => 'remove', 'attendance_punch_id' => $punch->id]],
    ]))->assertSessionHasErrors('items.0.attendance_punch_id');
});

test('REMOVE preserves the raw punch and stores only the requested removal intent', function () {
    [$hr, $company] = regularizationRoleFixture('hr_admin');
    $employee = regularizationEmployee($company);
    $punch = AttendancePunch::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'punched_at' => CarbonImmutable::parse('2026-10-04 18:00', $company->timezone)->utc(),
    ]);
    $original = regularizationAttributes($punch);

    $this->actingAs($hr)->post(route('companies.attendance-regularizations.store', $company), regularizationPayload($employee, [
        'request_type' => 'duplicate_punch',
        'items' => [['action' => 'remove', 'attendance_punch_id' => $punch->id, 'notes' => 'Duplicate scan from the kiosk.']],
    ]))->assertRedirect();

    expect(AttendancePunch::query()->count())->toBe(1)
        ->and(regularizationAttributes(AttendancePunch::query()->findOrFail($punch->id)))->toBe($original)
        ->and(AttendanceRegularizationItem::query()->firstOrFail()->action->value)->toBe('remove');
});

test('night shift context permits an overnight correction after midnight', function () {
    [$hr, $company] = regularizationRoleFixture('hr_admin');
    $employee = regularizationEmployee($company);
    $schedule = makeWorkScheduleForAttendanceDate($company, $employee, '2026-10-04', '22:00', '06:00');
    expect($schedule)->not->toBeNull();

    $this->actingAs($hr)->post(route('companies.attendance-regularizations.store', $company), regularizationPayload($employee, [
        'attendance_date' => '2026-10-04',
        'request_type' => 'missing_check_out',
        'items' => [['action' => 'add', 'punch_type' => 'check_out', 'requested_punched_at' => '2026-10-05T06:07']],
    ]))->assertRedirect();

    expect(AttendanceRegularizationRequest::query()->firstOrFail()->items()->firstOrFail()->requested_punched_at->setTimezone($company->timezone)->format('Y-m-d H:i'))
        ->toBe('2026-10-05 06:07');
});

test('a punch after midnight can be referenced for the previous overnight attendance date', function () {
    [$hr, $company] = regularizationRoleFixture('hr_admin');
    $employee = regularizationEmployee($company);
    makeWorkScheduleForAttendanceDate($company, $employee, '2026-10-04', '22:00', '06:00');
    $overnightPunch = AttendancePunch::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'punch_type' => AttendancePunchType::CheckOut,
        'punched_at' => CarbonImmutable::parse('2026-10-05 06:05', $company->timezone)->utc(),
    ]);

    $this->actingAs($hr)->post(route('companies.attendance-regularizations.store', $company), regularizationPayload($employee, [
        'attendance_date' => '2026-10-04',
        'request_type' => 'duplicate_punch',
        'items' => [['action' => 'remove', 'attendance_punch_id' => $overnightPunch->id]],
    ]))->assertRedirect();

    expect(AttendanceRegularizationItem::query()->firstOrFail()->attendance_punch_id)->toBe($overnightPunch->id);
});

test('future attendance dates and timestamps outside the calculation window are rejected', function () {
    [$hr, $company] = regularizationRoleFixture('hr_admin');
    $employee = regularizationEmployee($company);
    $this->actingAs($hr)->post(route('companies.attendance-regularizations.store', $company), regularizationPayload($employee, [
        'attendance_date' => '2026-10-06',
    ]))->assertSessionHasErrors('attendance_date');

    $this->actingAs($hr)->post(route('companies.attendance-regularizations.store', $company), regularizationPayload($employee, [
        'attendance_date' => '2026-10-04',
        'items' => [['action' => 'add', 'punch_type' => 'check_out', 'requested_punched_at' => '2026-10-05T09:00']],
    ]))->assertSessionHasErrors('items.0.requested_punched_at');
    expect(AttendanceRegularizationRequest::query()->count())->toBe(0);
});

test('a request requires a meaningful reason and between one and ten structurally valid items', function () {
    [$hr, $company] = regularizationRoleFixture('hr_admin');
    $employee = regularizationEmployee($company);
    $url = route('companies.attendance-regularizations.store', $company);

    $this->actingAs($hr)->post($url, regularizationPayload($employee, [
        'reason' => '   ',
        'items' => [],
    ]))->assertSessionHasErrors(['reason', 'items']);

    $tooManyItems = array_fill(0, 11, ['action' => 'add', 'punch_type' => 'check_out', 'requested_punched_at' => '2026-10-04T18:00']);
    $this->actingAs($hr)->post($url, regularizationPayload($employee, ['items' => $tooManyItems]))
        ->assertSessionHasErrors('items');

    $this->actingAs($hr)->post($url, regularizationPayload($employee, [
        'items' => [['action' => 'add', 'punch_type' => 'check_out']],
    ]))->assertSessionHasErrors('items.0.requested_punched_at');

    $this->actingAs($hr)->post($url, regularizationPayload($employee, [
        'request_type' => 'missing_check_in',
        'items' => [['action' => 'add', 'punch_type' => 'check_out', 'requested_punched_at' => '2026-10-04T18:00']],
    ]))->assertSessionHasErrors('items');

    expect(AttendanceRegularizationRequest::query()->count())->toBe(0);
});

test('only owner and HR roles create company requests and future attendance rows are denied', function (string $role) {
    [$actor, $company] = regularizationRoleFixture($role);
    $employee = regularizationEmployee($company);

    $this->actingAs($actor)->post(route('companies.attendance-regularizations.store', $company), regularizationPayload($employee))
        ->assertRedirect();
    expect(AttendanceRegularizationRequest::query()->firstOrFail()->status)->toBe(AttendanceRegularizationStatus::Pending);
})->with(['company owner' => 'company_owner', 'HR manager' => 'hr_manager']);

test('an employee cannot request missing checkout while today is still in progress', function () {
    [$employeeUser, $company] = regularizationRoleFixture('employee');
    $employee = regularizationEmployee($company, $employeeUser);
    makeWorkScheduleForAttendanceDate($company, $employee, '2026-10-05', '09:00', '18:00');
    DailyAttendanceRecord::factory()->inProgress()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_date' => '2026-10-05',
    ]);
    $payload = regularizationPayload($employee, [
        'attendance_date' => '2026-10-05',
        'request_type' => 'missing_check_out',
        'items' => [['action' => 'add', 'punch_type' => 'check_out', 'requested_punched_at' => '2026-10-05T18:00']],
    ]);
    unset($payload['employee_id']);

    $this->actingAs($employeeUser)->post(route('companies.attendance-regularizations.self.store', $company), $payload)
        ->assertSessionHasErrors('attendance_date');
    expect(AttendanceRegularizationRequest::query()->count())->toBe(0);
});

test('foreign company URLs are hidden and clients cannot spoof attendance record or audit fields', function () {
    [$hr, $company] = regularizationRoleFixture('hr_admin');
    [, $foreignCompany] = regularizationRoleFixture('company_owner');
    $employee = regularizationEmployee($company);
    $foreignEmployee = regularizationEmployee($foreignCompany);
    $foreignRequester = User::factory()->create();
    $foreignRequest = AttendanceRegularizationRequest::factory()->create(['company_id' => $foreignCompany->id, 'employee_id' => $foreignEmployee->id]);

    $this->actingAs($hr)->get(route('companies.attendance-regularizations.show', [$foreignCompany, $foreignRequest]))->assertNotFound();

    $this->actingAs($hr)->post(route('companies.attendance-regularizations.store', $company), regularizationPayload($employee, [
        'status' => 'approved',
        'requested_by' => $foreignRequester->id,
        'daily_attendance_record_id' => 900000,
    ]))->assertSessionHasErrors(['status', 'requested_by', 'daily_attendance_record_id']);
    expect(AttendanceRegularizationRequest::query()->where('company_id', $company->id)->count())->toBe(0);
});

test('duplicate pending requests are rejected and cancellation preserves request items and attendance records', function () {
    [$hr, $company] = regularizationRoleFixture('hr_admin');
    $employee = regularizationEmployee($company);
    $record = DailyAttendanceRecord::factory()->incomplete()->create(['company_id' => $company->id, 'employee_id' => $employee->id, 'attendance_date' => '2026-10-04']);

    $this->actingAs($hr)->post(route('companies.attendance-regularizations.store', $company), regularizationPayload($employee))->assertRedirect();
    $request = AttendanceRegularizationRequest::query()->firstOrFail();
    $this->actingAs($hr)->post(route('companies.attendance-regularizations.store', $company), regularizationPayload($employee))->assertSessionHasErrors('attendance_date');

    $this->actingAs($hr)->post(route('companies.attendance-regularizations.cancel', [$company, $request]))->assertRedirect();
    expect($request->refresh()->status)->toBe(AttendanceRegularizationStatus::Cancelled)
        ->and($request->items()->count())->toBe(1)
        ->and(DailyAttendanceRecord::query()->findOrFail($record->id)->status->value)->toBe('incomplete');

    $this->actingAs($hr)->post(route('companies.attendance-regularizations.store', $company), regularizationPayload($employee))->assertRedirect();
    expect(AttendanceRegularizationRequest::query()->count())->toBe(2);
});

test('employees may only cancel their own pending request and cannot see another employee request', function () {
    [$user, $company] = regularizationRoleFixture('employee');
    $employee = regularizationEmployee($company, $user);
    $other = regularizationEmployee($company);
    $own = AttendanceRegularizationRequest::factory()->create(['company_id' => $company->id, 'employee_id' => $employee->id, 'requested_by' => $user->id]);
    $foreignRequest = AttendanceRegularizationRequest::factory()->create(['company_id' => $company->id, 'employee_id' => $other->id]);

    $this->actingAs($user)->get(route('companies.attendance-regularizations.show', [$company, $foreignRequest]))->assertForbidden();
    $this->actingAs($user)->post(route('companies.attendance-regularizations.cancel', [$company, $own]))->assertRedirect();
    expect($own->refresh()->status)->toBe(AttendanceRegularizationStatus::Cancelled)
        ->and($foreignRequest->refresh()->status)->toBe(AttendanceRegularizationStatus::Pending);
});

test('approved and rejected requests cannot be cancelled', function (AttendanceRegularizationStatus $status) {
    [$hr, $company] = regularizationRoleFixture('hr_admin');
    $employee = regularizationEmployee($company);
    $request = AttendanceRegularizationRequest::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'status' => $status,
    ]);

    $this->actingAs($hr)->post(route('companies.attendance-regularizations.cancel', [$company, $request]))->assertForbidden();
    expect($request->refresh()->status)->toBe($status);
})->with([
    'approved' => AttendanceRegularizationStatus::Approved,
    'rejected' => AttendanceRegularizationStatus::Rejected,
]);

test('database constraints enforce company and employee ownership and one pending request per date', function () {
    [, $company] = regularizationRoleFixture('company_owner');
    [, $foreignCompany] = regularizationRoleFixture('company_owner');
    $employee = regularizationEmployee($company);
    $foreignEmployee = regularizationEmployee($foreignCompany);
    $request = AttendanceRegularizationRequest::factory()->create(['company_id' => $company->id, 'employee_id' => $employee->id]);
    $foreignPunch = AttendancePunch::factory()->create(['company_id' => $foreignCompany->id, 'employee_id' => $foreignEmployee->id]);

    expect(fn () => AttendanceRegularizationItem::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_regularization_request_id' => $request->id,
        'action' => 'remove',
        'attendance_punch_id' => $foreignPunch->id,
    ]))->toThrow(QueryException::class);

    expect(fn () => AttendanceRegularizationRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_date' => $request->attendance_date->toDateString(),
        'request_type' => 'other',
        'status' => 'pending',
        'reason' => 'Another request for the same date.',
        'requested_by' => $request->requested_by,
        'requested_at' => now('UTC'),
    ]))->toThrow(QueryException::class);
});

test('unauthorized roles cannot create requests and managers are restricted to visible employees', function () {
    [$accountant, $company] = regularizationRoleFixture('accountant');
    $employee = regularizationEmployee($company);
    $this->actingAs($accountant)->post(route('companies.attendance-regularizations.store', $company), regularizationPayload($employee))->assertForbidden();

    [$manager, $managedCompany] = regularizationRoleFixture('manager');
    $managerEmployee = regularizationEmployee($managedCompany, $manager);
    $report = Employee::factory()->create(['company_id' => $managedCompany->id, 'manager_id' => $managerEmployee->id]);
    $unrelated = regularizationEmployee($managedCompany);
    $visible = AttendanceRegularizationRequest::factory()->create(['company_id' => $managedCompany->id, 'employee_id' => $report->id]);
    AttendanceRegularizationRequest::factory()->create(['company_id' => $managedCompany->id, 'employee_id' => $unrelated->id]);

    $managerResponse = $this->actingAs($manager)->get(route('companies.attendance-regularizations.index', $managedCompany));
    $managerResponse
        ->assertInertia(fn (Assert $page) => $page->component('attendance-regularizations/index', false)->has('requests.data', 1)->where('requests.data.0.id', $visible->id));
});

test('request and item audit records cannot be generically edited or deleted and there are no review routes', function () {
    [$hr, $company] = regularizationRoleFixture('hr_admin');
    $employee = regularizationEmployee($company);
    $this->actingAs($hr)->post(route('companies.attendance-regularizations.store', $company), regularizationPayload($employee))->assertRedirect();
    $request = AttendanceRegularizationRequest::query()->firstOrFail();

    expect(Schema::hasTable('attendance_regularization_requests'))->toBeTrue()
        ->and(Schema::hasTable('attendance_regularization_items'))->toBeTrue()
        ->and(Route::has('companies.attendance-regularizations.update'))->toBeFalse()
        ->and(Route::has('companies.attendance-regularizations.destroy'))->toBeFalse()
        ->and(Route::has('companies.attendance-regularizations.approve'))->toBeTrue()
        ->and(Route::has('companies.attendance-regularizations.reject'))->toBeTrue()
        ->and(Route::has('companies.attendance-regularizations.apply'))->toBeFalse();

    expect(function () use ($request): void {
        $request->reason = 'Edited after submission';
        $request->save();
    })->toThrow(LogicException::class)
        ->and(fn () => AttendanceRegularizationRequest::query()->findOrFail($request->id)->delete())->toThrow(LogicException::class)
        ->and(fn () => $request->items()->firstOrFail()->delete())->toThrow(LogicException::class);
});

test('a request page exposes current record and correction audit information without review controls', function () {
    [$hr, $company] = regularizationRoleFixture('hr_admin');
    $employee = regularizationEmployee($company);
    $this->actingAs($hr)->post(route('companies.attendance-regularizations.store', $company), regularizationPayload($employee))->assertRedirect();
    $request = AttendanceRegularizationRequest::query()->firstOrFail();

    $this->actingAs($hr)->get(route('companies.attendance-regularizations.show', [$company, $request]))
        ->assertInertia(fn (Assert $page) => $page->component('attendance-regularizations/show', false)->where('request.status', 'pending')->has('request.items', 1));
});

function makeWorkScheduleForAttendanceDate(Company $company, Employee $employee, string $date, string $start, string $end): mixed
{
    $shift = Shift::factory()->create([
        'company_id' => $company->id,
        'start_time' => $start,
        'end_time' => $end,
        'is_overnight' => $end < $start,
    ]);
    $schedule = WorkSchedule::factory()->create(['company_id' => $company->id]);
    $schedule->days()->update(['day_type' => 'working', 'shift_id' => $shift->id]);
    EmployeeWorkScheduleAssignment::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'work_schedule_id' => $schedule->id,
        'effective_from' => $date,
        'effective_to' => null,
    ]);

    return $schedule;
}
