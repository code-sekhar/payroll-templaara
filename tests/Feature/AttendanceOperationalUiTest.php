<?php

use App\Actions\SeedCompanyRoles;
use App\Enums\AttendancePunchType;
use App\Enums\AttendanceRegularizationItemAction;
use App\Enums\AttendanceRegularizationStatus;
use App\Enums\AttendanceRegularizationType;
use App\Enums\CalculatedAttendanceStatus;
use App\Enums\DailyAttendanceRecordState;
use App\Models\AttendancePunch;
use App\Models\AttendancePunchCorrection;
use App\Models\AttendanceRegularizationItem;
use App\Models\AttendanceRegularizationRequest;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\DailyAttendanceRecord;
use App\Models\DailyAttendanceRecordHistory;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

/** @return array{User, Company} */
function attendanceOperationalRole(string $role, string $timezone = 'Asia/Kolkata'): array
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

function attendanceOperationalRecord(Company $company, Employee $employee, string $date, array $attributes = []): DailyAttendanceRecord
{
    return DailyAttendanceRecord::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_date' => $date,
        ...$attributes,
    ]);
}

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 12:00:00', 'Asia/Kolkata'));
    config()->set('inertia.testing.page_paths', [resource_path('js/pages')]);
});

test('HR attendance report reads persisted records, applies month defaults and reports filtered summary without materializing missing days', function () {
    [$hr, $company] = attendanceOperationalRole('hr_admin');
    $employee = Employee::factory()->create(['company_id' => $company->id]);
    $present = attendanceOperationalRecord($company, $employee, '2026-10-05', [
        'status' => CalculatedAttendanceStatus::Present,
        'worked_minutes' => 480,
        'late_minutes' => 16,
        'is_late' => true,
        'overtime_minutes' => 25,
    ]);
    attendanceOperationalRecord($company, $employee, '2026-09-30', ['status' => CalculatedAttendanceStatus::Absent]);

    $this->actingAs($hr)->get(route('companies.attendance.index', $company))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('attendance/index')
            ->where('filters.date_from', '2026-10-01')
            ->where('filters.date_to', '2026-10-31')
            ->where('summary.total', 1)
            ->where('summary.present', 1)
            ->where('summary.late', 1)
            ->where('records.data.0.id', $present->id)
            ->where('records.data.0.worked_minutes', 480)
            ->where('records.data.0.late_minutes', 16));

    expect(DailyAttendanceRecord::query()->count())->toBe(2)
        ->and(DailyAttendanceRecordHistory::query()->count())->toBe(0);
});

test('HR filters by employee code and status and summary follows the same filters', function () {
    [$hr, $company] = attendanceOperationalRole('hr_admin');
    $employee = Employee::factory()->create(['company_id' => $company->id, 'employee_code' => 'EMP-FILTER-55']);
    $matching = attendanceOperationalRecord($company, $employee, '2026-10-04', ['status' => CalculatedAttendanceStatus::HalfDay]);
    attendanceOperationalRecord($company, Employee::factory()->create(['company_id' => $company->id]), '2026-10-03', ['status' => CalculatedAttendanceStatus::Present]);

    $this->actingAs($hr)->get(route('companies.attendance.index', [$company, 'search' => 'EMP-FILTER-55', 'status' => 'half_day']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.total', 1)
            ->where('summary.half_day', 1)
            ->where('records.data.0.id', $matching->id));
});

test('attendance report filters leave-aware statuses exposes snapshots and never counts on leave as absent', function () {
    [$hr, $company] = attendanceOperationalRole('hr_admin');
    $type = LeaveType::factory()->create(['company_id' => $company->id, 'name' => 'Annual Leave']);
    $onLeaveEmployee = Employee::factory()->create(['company_id' => $company->id]);
    $partialEmployee = Employee::factory()->create(['company_id' => $company->id]);
    $onLeave = attendanceOperationalRecord($company, $onLeaveEmployee, '2026-10-05', [
        'status' => CalculatedAttendanceStatus::OnLeave,
        'leave_type_id' => $type->id,
        'leave_amount' => '1.00',
        'leave_is_paid' => true,
    ]);
    $partial = attendanceOperationalRecord($company, $partialEmployee, '2026-10-04', [
        'status' => CalculatedAttendanceStatus::PartialLeave,
        'leave_type_id' => $type->id,
        'leave_amount' => '0.50',
        'leave_is_paid' => false,
    ]);
    attendanceOperationalRecord($company, Employee::factory()->create(['company_id' => $company->id]), '2026-10-03', [
        'status' => CalculatedAttendanceStatus::Absent,
    ]);

    $this->actingAs($hr)->get(route('companies.attendance.index', [$company, 'status' => 'on_leave']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.total', 1)
            ->where('summary.on_leave', 1)
            ->where('summary.absent', 0)
            ->where('records.data.0.id', $onLeave->id)
            ->where('records.data.0.leave_type', 'Annual Leave')
            ->where('records.data.0.leave_amount', '1.00'));

    $this->get(route('companies.attendance.index', [$company, 'status' => 'partial_leave']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.partial_leave', 1)
            ->where('records.data.0.id', $partial->id)
            ->where('records.data.0.leave_amount', '0.50'));
});

test('pending regularization filter and summary match employee and attendance date', function () {
    [$hr, $company] = attendanceOperationalRole('hr_admin');
    $employee = Employee::factory()->create(['company_id' => $company->id]);
    $record = attendanceOperationalRecord($company, $employee, '2026-10-05');
    AttendanceRegularizationRequest::factory()->pending()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'daily_attendance_record_id' => $record->id,
        'attendance_date' => '2026-10-05',
    ]);

    $this->actingAs($hr)->get(route('companies.attendance.index', [$company, 'regularization_status' => 'pending']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.total', 1)
            ->where('summary.pending_regularization', 1)
            ->where('records.data.0.regularization_status', 'pending'));
});

test('managers see their own and direct-report attendance but not unrelated employees', function () {
    [$managerUser, $company] = attendanceOperationalRole('manager');
    $manager = Employee::factory()->create(['company_id' => $company->id, 'user_id' => $managerUser->id]);
    $directReport = Employee::factory()->create(['company_id' => $company->id, 'manager_id' => $manager->id]);
    $unrelated = Employee::factory()->create(['company_id' => $company->id]);
    $visible = attendanceOperationalRecord($company, $directReport, '2026-10-05');
    $hidden = attendanceOperationalRecord($company, $unrelated, '2026-10-05');

    $this->actingAs($managerUser)->get(route('companies.attendance.index', $company))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('records.total', 1)
            ->where('summary.total', 1)
            ->where('records.data.0.id', $visible->id)
            ->missing('records.data.1'));

    $this->get(route('companies.attendance.show', [$company, $hidden]))->assertForbidden();
});

test('company-wide attendance is unavailable to employees and accountants and cross-company rows cannot be opened', function () {
    [$employeeUser, $company] = attendanceOperationalRole('employee');
    [, $foreignCompany] = attendanceOperationalRole('company_owner');
    $employee = Employee::factory()->create(['company_id' => $company->id, 'user_id' => $employeeUser->id]);
    $foreignEmployee = Employee::factory()->create(['company_id' => $foreignCompany->id]);
    $foreignRecord = attendanceOperationalRecord($foreignCompany, $foreignEmployee, '2026-10-05');

    $this->actingAs($employeeUser)->get(route('companies.attendance.index', $company))->assertForbidden();
    $this->get(route('companies.attendance.show', [$company, $foreignRecord]))->assertNotFound();
    expect($employee->exists)->toBeTrue();

    [$accountant, $accountantCompany] = attendanceOperationalRole('accountant');
    $this->actingAs($accountant)->get(route('companies.attendance.index', $accountantCompany))->assertForbidden();
});

test('HR detail separates raw punches, effective correction timeline, regularization and materialization history', function () {
    [$hr, $company] = attendanceOperationalRole('hr_admin');
    $employee = Employee::factory()->create(['company_id' => $company->id]);
    $leaveType = LeaveType::factory()->create(['company_id' => $company->id, 'name' => 'Casual Leave']);
    $record = attendanceOperationalRecord($company, $employee, '2026-10-05', [
        'status' => CalculatedAttendanceStatus::Present,
        'record_state' => DailyAttendanceRecordState::Final,
        'leave_type_id' => $leaveType->id,
        'leave_amount' => '0.50',
        'leave_is_paid' => false,
        'expected_start_at' => CarbonImmutable::parse('2026-10-05 09:00', $company->timezone)->utc(),
        'expected_end_at' => CarbonImmutable::parse('2026-10-05 18:00', $company->timezone)->utc(),
    ]);
    $punch = AttendancePunch::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'punch_type' => AttendancePunchType::CheckIn,
        'punched_at' => CarbonImmutable::parse('2026-10-05 09:01', $company->timezone)->utc(),
        'timezone' => $company->timezone,
    ]);
    $requester = User::factory()->create();
    $reviewer = User::factory()->create();
    $regularization = AttendanceRegularizationRequest::factory()->approved()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'daily_attendance_record_id' => $record->id,
        'attendance_date' => '2026-10-05',
        'request_type' => AttendanceRegularizationType::MissingCheckOut,
        'status' => AttendanceRegularizationStatus::Approved,
        'requested_by' => $requester->id,
        'reviewed_by' => $reviewer->id,
        'reviewed_at' => CarbonImmutable::parse('2026-10-06 10:00', $company->timezone)->utc(),
    ]);
    $item = AttendanceRegularizationItem::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_regularization_request_id' => $regularization->id,
        'action' => AttendanceRegularizationItemAction::Add,
        'attendance_punch_id' => null,
        'punch_type' => AttendancePunchType::CheckOut,
        'requested_punched_at' => CarbonImmutable::parse('2026-10-05 18:03', $company->timezone)->utc(),
        'timezone' => $company->timezone,
        'notes' => null,
    ]);
    AttendancePunchCorrection::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_date' => '2026-10-05',
        'attendance_regularization_request_id' => $regularization->id,
        'attendance_regularization_item_id' => $item->id,
        'action' => AttendanceRegularizationItemAction::Add,
        'original_attendance_punch_id' => null,
        'effective_punch_type' => AttendancePunchType::CheckOut,
        'effective_punched_at' => CarbonImmutable::parse('2026-10-05 18:03', $company->timezone)->utc(),
        'timezone' => $company->timezone,
        'approved_by' => $reviewer->id,
        'approved_at' => CarbonImmutable::parse('2026-10-06 10:00', $company->timezone)->utc(),
        'created_at' => CarbonImmutable::parse('2026-10-06 10:00', $company->timezone)->utc(),
    ]);
    $requester = User::factory()->create();
    $reviewer = User::factory()->create();
    $regularization = AttendanceRegularizationRequest::factory()->approved()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'daily_attendance_record_id' => $record->id,
        'attendance_date' => '2026-10-05',
        'request_type' => AttendanceRegularizationType::MissingCheckOut,
        'status' => AttendanceRegularizationStatus::Approved,
        'requested_by' => $requester->id,
        'reviewed_by' => $reviewer->id,
    ]);
    $item = AttendanceRegularizationItem::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_regularization_request_id' => $regularization->id,
        'action' => AttendanceRegularizationItemAction::Add,
        'attendance_punch_id' => null,
        'punch_type' => AttendancePunchType::CheckOut,
        'requested_punched_at' => CarbonImmutable::parse('2026-10-05 18:03', $company->timezone)->utc(),
        'timezone' => $company->timezone,
    ]);
    AttendancePunchCorrection::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_date' => '2026-10-05',
        'attendance_regularization_request_id' => $regularization->id,
        'attendance_regularization_item_id' => $item->id,
        'action' => AttendanceRegularizationItemAction::Add,
        'original_attendance_punch_id' => null,
        'effective_punch_type' => AttendancePunchType::CheckOut,
        'effective_punched_at' => CarbonImmutable::parse('2026-10-05 18:03', $company->timezone)->utc(),
        'timezone' => $company->timezone,
        'approved_by' => $reviewer->id,
        'approved_at' => CarbonImmutable::parse('2026-10-06 12:00', $company->timezone)->utc(),
        'created_at' => CarbonImmutable::parse('2026-10-06 12:00', $company->timezone)->utc(),
    ]);
    DailyAttendanceRecordHistory::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'daily_attendance_record_id' => $record->id,
        'attendance_date' => $record->attendance_date,
        'status' => $record->status,
        'record_state' => $record->record_state,
        'worked_minutes' => 480,
    ]);

    $this->actingAs($hr)->get(route('companies.attendance.show', [$company, $record]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('attendance/show')
            ->where('rawPunches.0.id', $punch->id)
            ->where('rawPunches.0.source', 'web')
            ->where('effectivePunches.0.event_kind', 'raw')
            ->where('effectivePunches.1.event_kind', 'correction')
            ->where('corrections.0.action', 'add')
            ->where('regularizations.0.id', $regularization->id)
            ->where('record.leave_type', 'Casual Leave')
            ->where('record.leave_amount', '0.50')
            ->where('record.leave_is_paid', false)
            ->where('effectivePunches.1.event_kind', 'correction')
            ->where('histories.0.worked_minutes', 480)
            ->where('corrections.0.action', 'add')
            ->where('regularizations.0.id', $regularization->id));

    expect($punch->fresh()->punch_type)->toBe(AttendancePunchType::CheckIn)
        ->and(DailyAttendanceRecordHistory::query()->where('daily_attendance_record_id', $record->id)->count())->toBe(1);
});

test('employees see only their records, company-local today and an explicit uncalculated state', function () {
    [$employeeUser, $company] = attendanceOperationalRole('employee', 'Asia/Kolkata');
    $employee = Employee::factory()->create(['company_id' => $company->id, 'user_id' => $employeeUser->id]);
    $leaveType = LeaveType::factory()->create(['company_id' => $company->id, 'name' => 'Annual Leave']);
    $mine = attendanceOperationalRecord($company, $employee, '2026-10-05', [
        'status' => CalculatedAttendanceStatus::OnLeave,
        'leave_type_id' => $leaveType->id,
        'leave_amount' => '1.00',
        'leave_is_paid' => true,
    ]);
    attendanceOperationalRecord($company, $employee, '2026-09-29');
    attendanceOperationalRecord($company, Employee::factory()->create(['company_id' => $company->id]), '2026-10-05');

    $this->actingAs($employeeUser)->get(route('companies.my-attendance.index', $company))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('attendance/my-attendance')
            ->where('today', '2026-10-10')
            ->where('todayRecord', null)
            ->where('records.total', 1)
            ->where('records.data.0.id', $mine->id)
            ->where('records.data.0.leave_type', 'Annual Leave')
            ->where('records.data.0.leave_amount', '1.00')
            ->where('nextPunchType', 'check_in')
            ->where('filters.date_from', '2026-10-01'));

    $this->get(route('companies.my-attendance.show', [$company, $mine]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('record.leave_type', 'Annual Leave')
            ->where('record.leave_amount', '1.00')
            ->where('record.leave_is_paid', true));
});

test('employees cannot open a coworker attendance record through the self-service route', function () {
    [$employeeUser, $company] = attendanceOperationalRole('employee');
    Employee::factory()->create(['company_id' => $company->id, 'user_id' => $employeeUser->id]);
    $coworker = Employee::factory()->create(['company_id' => $company->id]);
    $record = attendanceOperationalRecord($company, $coworker, '2026-10-05');

    $this->actingAs($employeeUser)->get(route('companies.my-attendance.show', [$company, $record]))->assertForbidden();
});

test('normal self punch reuses the existing endpoint and does not accept a client timestamp', function () {
    [$employeeUser, $company] = attendanceOperationalRole('employee');
    $employee = Employee::factory()->create(['company_id' => $company->id, 'user_id' => $employeeUser->id]);

    $this->actingAs($employeeUser)->post(route('companies.attendance-punches.self', $company), [
        'punch_type' => 'check_in',
    ])->assertRedirect();

    $punch = AttendancePunch::query()->firstOrFail();
    expect($punch->employee_id)->toBe($employee->id)
        ->and($punch->punched_at->setTimezone($company->timezone)->format('Y-m-d H:i'))->toBe('2026-10-10 12:00')
        ->and($punch->timezone)->toBe('Asia/Kolkata')
        ->and($punch->created_by)->toBe($employeeUser->id)
        ->and(DailyAttendanceRecord::query()->count())->toBe(0);
});

test('bounded materialization command handles one company and one date without aborting across employees', function () {
    [, $company] = attendanceOperationalRole('company_owner');
    $active = Employee::factory()->create(['company_id' => $company->id, 'employment_status' => 'active']);
    Employee::factory()->create(['company_id' => $company->id, 'employment_status' => 'inactive']);
    [, $otherCompany] = attendanceOperationalRole('company_owner');
    Employee::factory()->create(['company_id' => $otherCompany->id, 'employment_status' => 'active']);

    $this->artisan('attendance:materialize', ['--company' => $company->id, '--date' => '2026-10-05'])
        ->expectsOutputToContain('Materialized: 1; skipped unchanged: 0; failed: 0.')
        ->assertSuccessful();

    expect(DailyAttendanceRecord::query()->where('company_id', $company->id)->where('employee_id', $active->id)->exists())->toBeTrue()
        ->and(DailyAttendanceRecord::query()->count())->toBe(1);
});

test('materialization command requires explicit scope and rejects future dates', function () {
    [, $company] = attendanceOperationalRole('company_owner');
    $this->artisan('attendance:materialize')->assertExitCode(2);
    $this->artisan('attendance:materialize', ['--company' => $company->id, '--date' => '2026-10-11'])->assertExitCode(2);
    expect(DailyAttendanceRecord::query()->count())->toBe(0);
});
