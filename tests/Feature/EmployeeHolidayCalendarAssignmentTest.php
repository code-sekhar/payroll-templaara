<?php

use App\Actions\SeedCompanyRoles;
use App\Enums\EmployeeHolidayCalendarAssignmentStatus;
use App\Enums\HolidayCalendarStatus;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\EmployeeHolidayCalendarAssignment;
use App\Models\HolidayCalendar;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

/** @return array{User, Company} */
function holidayCalendarAssignmentUser(string $role): array
{
    $user = User::factory()->create();
    $company = Company::factory()->create();
    CompanyUser::create(['company_id' => $company->id, 'user_id' => $user->id, 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($company);
    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    return [$user, $company];
}

function holidayCalendarAssignmentEmployee(Company $company): Employee
{
    return $company->employees()->create([
        'employee_code' => 'EMP-'.strtoupper(fake()->unique()->bothify('??????')),
        'first_name' => fake()->firstName(),
        'last_name' => fake()->lastName(),
        'joining_date' => '2026-01-01',
        'employment_type' => 'full_time',
        'employment_status' => 'active',
    ]);
}

function holidayCalendarAssignmentCalendar(Company $company, array $attributes = []): HolidayCalendar
{
    return HolidayCalendar::factory()->create(array_merge([
        'company_id' => $company->id,
        'status' => HolidayCalendarStatus::Active,
    ], $attributes));
}

/** @return array<string, mixed> */
function holidayCalendarAssignmentPayload(Employee $employee, HolidayCalendar $calendar, array $attributes = []): array
{
    return array_merge([
        'employee_id' => $employee->id,
        'holiday_calendar_id' => $calendar->id,
        'effective_from' => '2026-01-01',
        'effective_to' => null,
        'status' => 'active',
        'notes' => 'Calendar assignment',
    ], $attributes);
}

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

test('authorized HR can create update and archive a tenant-scoped assignment with audit fields', function () {
    [$hr, $company] = holidayCalendarAssignmentUser('hr_admin');
    $employee = holidayCalendarAssignmentEmployee($company);
    $calendar = holidayCalendarAssignmentCalendar($company);

    $this->actingAs($hr)->post(route('companies.employee-holiday-calendar-assignments.store', $company), holidayCalendarAssignmentPayload($employee, $calendar))
        ->assertRedirect(route('companies.employee-holiday-calendar-assignments.index', $company));

    $assignment = $company->employeeHolidayCalendarAssignments()->firstOrFail();
    expect($assignment->company_id)->toBe($company->id)
        ->and($assignment->employee_id)->toBe($employee->id)
        ->and($assignment->holiday_calendar_id)->toBe($calendar->id)
        ->and($assignment->created_by)->toBe($hr->id)
        ->and($assignment->updated_by)->toBe($hr->id);

    $this->put(route('companies.employee-holiday-calendar-assignments.update', [$company, $assignment]), holidayCalendarAssignmentPayload($employee, $calendar, ['notes' => 'Updated']))
        ->assertRedirect();
    expect($assignment->fresh()->notes)->toBe('Updated');

    $this->delete(route('companies.employee-holiday-calendar-assignments.destroy', [$company, $assignment]))->assertRedirect();
    expect(EmployeeHolidayCalendarAssignment::find($assignment->id))->toBeNull()
        ->and(EmployeeHolidayCalendarAssignment::withTrashed()->find($assignment->id)->trashed())->toBeTrue();
});

test('tenant scoping rejects foreign employees calendars assignments and forged company ids', function () {
    [$hr, $company] = holidayCalendarAssignmentUser('hr_admin');
    $employee = holidayCalendarAssignmentEmployee($company);
    $calendar = holidayCalendarAssignmentCalendar($company);
    $otherCompany = Company::factory()->create();
    $foreignEmployee = holidayCalendarAssignmentEmployee($otherCompany);
    $foreignCalendar = holidayCalendarAssignmentCalendar($otherCompany);

    $this->actingAs($hr)->get(route('companies.employee-holiday-calendar-assignments.index', $otherCompany))->assertNotFound();
    $this->post(route('companies.employee-holiday-calendar-assignments.store', $company), holidayCalendarAssignmentPayload($foreignEmployee, $calendar))->assertSessionHasErrors('employee_id');
    $this->post(route('companies.employee-holiday-calendar-assignments.store', $company), holidayCalendarAssignmentPayload($employee, $foreignCalendar))->assertSessionHasErrors('holiday_calendar_id');
    $this->post(route('companies.employee-holiday-calendar-assignments.store', $company), holidayCalendarAssignmentPayload($employee, $calendar, ['company_id' => $otherCompany->id]))->assertSessionHasErrors('company_id');

    $foreignAssignment = $otherCompany->employeeHolidayCalendarAssignments()->create([
        'employee_id' => $foreignEmployee->id,
        'holiday_calendar_id' => $foreignCalendar->id,
        'effective_from' => '2026-01-01',
        'status' => 'active',
    ]);
    $this->put(route('companies.employee-holiday-calendar-assignments.update', [$company, $foreignAssignment]), holidayCalendarAssignmentPayload($employee, $calendar))->assertNotFound();
    $this->delete(route('companies.employee-holiday-calendar-assignments.destroy', [$company, $foreignAssignment]))->assertNotFound();
    expect($foreignAssignment->fresh())->not->toBeNull();
});

test('composite foreign keys reject cross-company employee and calendar references', function () {
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $employee = holidayCalendarAssignmentEmployee($company);
    $foreignEmployee = holidayCalendarAssignmentEmployee($otherCompany);
    $calendar = holidayCalendarAssignmentCalendar($company);
    $foreignCalendar = holidayCalendarAssignmentCalendar($otherCompany);

    expect(fn () => $company->employeeHolidayCalendarAssignments()->create([
        'employee_id' => $foreignEmployee->id,
        'holiday_calendar_id' => $calendar->id,
        'effective_from' => '2026-01-01',
        'status' => 'active',
    ]))->toThrow(QueryException::class);

    expect(fn () => $company->employeeHolidayCalendarAssignments()->create([
        'employee_id' => $employee->id,
        'holiday_calendar_id' => $foreignCalendar->id,
        'effective_from' => '2026-01-01',
        'status' => 'active',
    ]))->toThrow(QueryException::class);
});

test('active periods reject inclusive overlaps and allow adjacent dates', function () {
    [$hr, $company] = holidayCalendarAssignmentUser('hr_admin');
    $employee = holidayCalendarAssignmentEmployee($company);
    $calendar = holidayCalendarAssignmentCalendar($company);
    $this->actingAs($hr);

    $this->post(route('companies.employee-holiday-calendar-assignments.store', $company), holidayCalendarAssignmentPayload($employee, $calendar, ['effective_to' => '2026-06-30']))->assertRedirect();
    foreach ([
        ['effective_from' => '2026-06-30', 'effective_to' => '2026-12-31'],
        ['effective_from' => '2026-06-15', 'effective_to' => '2026-07-15'],
        ['effective_from' => '2025-12-01', 'effective_to' => '2026-08-01'],
        ['effective_from' => '2026-04-01', 'effective_to' => null],
    ] as $overlap) {
        $this->post(route('companies.employee-holiday-calendar-assignments.store', $company), holidayCalendarAssignmentPayload($employee, $calendar, $overlap))->assertSessionHasErrors('effective_from');
    }
    $this->post(route('companies.employee-holiday-calendar-assignments.store', $company), holidayCalendarAssignmentPayload($employee, $calendar, ['effective_from' => '2026-07-01', 'effective_to' => '2026-12-31']))->assertRedirect();
    expect($company->employeeHolidayCalendarAssignments()->count())->toBe(2);
});

test('inactive and archived history do not block active assignment creation', function () {
    [$hr, $company] = holidayCalendarAssignmentUser('hr_admin');
    $employee = holidayCalendarAssignmentEmployee($company);
    $calendar = holidayCalendarAssignmentCalendar($company);
    $company->employeeHolidayCalendarAssignments()->create([
        'employee_id' => $employee->id, 'holiday_calendar_id' => $calendar->id,
        'effective_from' => '2026-01-01', 'status' => EmployeeHolidayCalendarAssignmentStatus::Inactive,
    ]);
    $archivedAssignment = $company->employeeHolidayCalendarAssignments()->create([
        'employee_id' => $employee->id, 'holiday_calendar_id' => $calendar->id,
        'effective_from' => '2026-01-01', 'status' => 'active',
    ]);
    $archivedAssignment->delete();

    $this->actingAs($hr)->post(route('companies.employee-holiday-calendar-assignments.store', $company), holidayCalendarAssignmentPayload($employee, $calendar))
        ->assertRedirect();
    expect($company->employeeHolidayCalendarAssignments()->count())->toBe(2);
});

test('inactive assignments may retain inactive calendars and do not create active applicability', function () {
    [$hr, $company] = holidayCalendarAssignmentUser('hr_admin');
    $employee = holidayCalendarAssignmentEmployee($company);
    $calendar = holidayCalendarAssignmentCalendar($company, ['status' => HolidayCalendarStatus::Inactive]);

    $this->actingAs($hr)->post(route('companies.employee-holiday-calendar-assignments.store', $company), holidayCalendarAssignmentPayload($employee, $calendar, ['status' => 'inactive']))
        ->assertRedirect();

    expect($employee->holidayCalendarAssignmentForDate('2026-03-01'))->toBeNull();
});

test('updating dates rechecks overlap while excluding the assignment itself', function () {
    [$hr, $company] = holidayCalendarAssignmentUser('hr_admin');
    $employee = holidayCalendarAssignmentEmployee($company);
    $calendar = holidayCalendarAssignmentCalendar($company);
    $secondCalendar = holidayCalendarAssignmentCalendar($company);
    $assignment = $company->employeeHolidayCalendarAssignments()->create([
        'employee_id' => $employee->id, 'holiday_calendar_id' => $calendar->id,
        'effective_from' => '2026-01-01', 'effective_to' => '2026-06-30', 'status' => 'active',
    ]);
    $company->employeeHolidayCalendarAssignments()->create([
        'employee_id' => $employee->id, 'holiday_calendar_id' => $secondCalendar->id,
        'effective_from' => '2026-07-01', 'effective_to' => '2026-12-31', 'status' => 'active',
    ]);
    $this->actingAs($hr);

    $this->put(route('companies.employee-holiday-calendar-assignments.update', [$company, $assignment]), holidayCalendarAssignmentPayload($employee, $calendar, [
        'effective_from' => '2026-06-30', 'effective_to' => '2026-08-01',
    ]))->assertSessionHasErrors('effective_from');
    $this->put(route('companies.employee-holiday-calendar-assignments.update', [$company, $assignment]), holidayCalendarAssignmentPayload($employee, $calendar, [
        'notes' => 'Self does not overlap itself',
    ]))->assertRedirect();
});

test('inactive or soft deleted calendars and employees cannot receive a new active assignment', function () {
    [$hr, $company] = holidayCalendarAssignmentUser('hr_admin');
    $employee = holidayCalendarAssignmentEmployee($company);
    $inactiveCalendar = holidayCalendarAssignmentCalendar($company, ['status' => HolidayCalendarStatus::Inactive]);
    $archivedCalendar = holidayCalendarAssignmentCalendar($company);
    $archivedCalendar->delete();
    $this->actingAs($hr);

    $this->post(route('companies.employee-holiday-calendar-assignments.store', $company), holidayCalendarAssignmentPayload($employee, $inactiveCalendar))->assertSessionHasErrors('holiday_calendar_id');
    $this->post(route('companies.employee-holiday-calendar-assignments.store', $company), holidayCalendarAssignmentPayload($employee, $archivedCalendar))->assertSessionHasErrors('holiday_calendar_id');
    $employee->delete();
    $activeCalendar = holidayCalendarAssignmentCalendar($company);
    $this->post(route('companies.employee-holiday-calendar-assignments.store', $company), holidayCalendarAssignmentPayload($employee, $activeCalendar))->assertSessionHasErrors('employee_id');
});

test('employee date helpers resolve effective assignment and respect company timezone for today', function () {
    Carbon::setTestNow('2026-10-05 00:30:00 UTC');
    try {
        $company = Company::factory()->create(['timezone' => 'America/Los_Angeles']);
        $employee = holidayCalendarAssignmentEmployee($company);
        $older = holidayCalendarAssignmentCalendar($company, ['name' => 'Older', 'year' => 2025]);
        $current = holidayCalendarAssignmentCalendar($company, ['name' => 'Current', 'year' => 2026]);
        $company->employeeHolidayCalendarAssignments()->create(['employee_id' => $employee->id, 'holiday_calendar_id' => $older->id, 'effective_from' => '2025-01-01', 'effective_to' => '2026-10-04', 'status' => 'active']);
        $company->employeeHolidayCalendarAssignments()->create(['employee_id' => $employee->id, 'holiday_calendar_id' => $current->id, 'effective_from' => '2026-10-05', 'status' => 'active']);

        expect($employee->holidayCalendarAssignmentForDate('2025-06-01')?->holidayCalendar?->is($older))->toBeTrue()
            ->and($employee->holidayCalendarAssignmentForDate('2027-01-01')?->holidayCalendar?->is($current))->toBeTrue()
            ->and($employee->currentHolidayCalendarAssignment()?->holidayCalendar?->is($older))->toBeTrue();
        $unassigned = holidayCalendarAssignmentEmployee($company);
        expect($unassigned->holidayCalendarAssignmentForDate('2026-01-01'))->toBeNull();
    } finally {
        Carbon::setTestNow();
    }
});

test('the assignment index is paginated tenant scoped and exposes no foreign calendars', function () {
    [$hr, $company] = holidayCalendarAssignmentUser('hr_admin');
    $employee = holidayCalendarAssignmentEmployee($company);
    $calendar = holidayCalendarAssignmentCalendar($company);
    $otherCompany = Company::factory()->create();
    holidayCalendarAssignmentCalendar($otherCompany);
    $company->employeeHolidayCalendarAssignments()->create(['employee_id' => $employee->id, 'holiday_calendar_id' => $calendar->id, 'effective_from' => '2026-01-01', 'status' => 'active']);

    $this->actingAs($hr)->get(route('companies.employee-holiday-calendar-assignments.index', $company))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->component('employee-holiday-calendar-assignments/index', false)
        ->has('assignments.data', 1)->where('assignments.data.0.calendar.id', $calendar->id)
        ->has('calendars', 1));
});

test('roles receive only their assignment permissions', function () {
    foreach ([
        'company_owner' => ['view', 'create', 'update', 'delete'],
        'hr_admin' => ['view', 'create', 'update', 'delete'],
        'hr_manager' => ['view', 'create', 'update'],
        'manager' => ['view'],
        'accountant' => [],
        'employee' => [],
    ] as $role => $allowed) {
        [$user, $company] = holidayCalendarAssignmentUser($role);
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
        foreach (['view', 'create', 'update', 'delete'] as $action) {
            expect($user->can('holiday_calendar_assignment.'.$action))->toBe(in_array($action, $allowed, true));
        }
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    }
});

test('manager can view assignments but cannot create and accountant is denied', function () {
    [$manager, $company] = holidayCalendarAssignmentUser('manager');
    $this->actingAs($manager)->get(route('companies.employee-holiday-calendar-assignments.index', $company))->assertOk();
    $this->post(route('companies.employee-holiday-calendar-assignments.store', $company), [])->assertForbidden();

    [$accountant, $accountingCompany] = holidayCalendarAssignmentUser('accountant');
    $this->actingAs($accountant)->get(route('companies.employee-holiday-calendar-assignments.index', $accountingCompany))->assertForbidden();
});

test('date validation rejects end dates before start dates', function () {
    [$hr, $company] = holidayCalendarAssignmentUser('hr_admin');
    $employee = holidayCalendarAssignmentEmployee($company);
    $calendar = holidayCalendarAssignmentCalendar($company);

    $this->actingAs($hr)->post(route('companies.employee-holiday-calendar-assignments.store', $company), holidayCalendarAssignmentPayload($employee, $calendar, [
        'effective_from' => '2026-06-01', 'effective_to' => '2026-05-31',
    ]))->assertSessionHasErrors('effective_to');
});
