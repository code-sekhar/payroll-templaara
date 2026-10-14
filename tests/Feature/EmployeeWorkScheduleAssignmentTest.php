<?php

use App\Actions\SeedCompanyRoles;
use App\Enums\EmployeeWorkScheduleAssignmentStatus;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\EmployeeWorkScheduleAssignment;
use App\Models\User;
use App\Models\WorkSchedule;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** @return array{User, Company} */
function workScheduleAssignmentUser(string $role): array
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

function workScheduleAssignmentEmployee(Company $company, ?User $user = null): Employee
{
    return $company->employees()->create([
        'user_id' => $user?->id,
        'employee_code' => 'EMP-'.strtoupper(fake()->unique()->bothify('??????')),
        'first_name' => fake()->firstName(),
        'last_name' => fake()->lastName(),
        'joining_date' => '2026-01-01',
        'employment_type' => 'full_time',
        'employment_status' => 'active',
    ]);
}

function workScheduleAssignmentSchedule(Company $company, array $overrides = []): WorkSchedule
{
    return WorkSchedule::factory()->create(array_merge([
        'company_id' => $company->id,
        'status' => 'active',
    ], $overrides));
}

/** @return array<string, mixed> */
function workScheduleAssignmentPayload(Employee $employee, WorkSchedule $schedule, array $overrides = []): array
{
    return array_merge([
        'employee_id' => $employee->id,
        'work_schedule_id' => $schedule->id,
        'effective_from' => '2026-10-01',
        'effective_to' => null,
        'status' => 'active',
        'notes' => 'Initial assignment',
    ], $overrides);
}

test('HR can assign a company schedule and the application records tenant and audit data', function () {
    [$hr, $company] = workScheduleAssignmentUser('hr_admin');
    $employee = workScheduleAssignmentEmployee($company);
    $schedule = workScheduleAssignmentSchedule($company);

    $this->actingAs($hr)->post(route('companies.work-schedule-assignments.store', $company), workScheduleAssignmentPayload($employee, $schedule))
        ->assertRedirect(route('companies.work-schedule-assignments.index', $company));

    $assignment = $company->employeeWorkScheduleAssignments()->firstOrFail();
    expect($assignment->employee_id)->toBe($employee->id)
        ->and($assignment->work_schedule_id)->toBe($schedule->id)
        ->and($assignment->created_by)->toBe($hr->id)
        ->and($assignment->company_id)->toBe($company->id)
        ->and($assignment->effective_from->toDateString())->toBe('2026-10-01');
});

test('a company cannot list or assign another company employee or schedule', function () {
    [$hr, $company] = workScheduleAssignmentUser('hr_admin');
    $employee = workScheduleAssignmentEmployee($company);
    $schedule = workScheduleAssignmentSchedule($company);
    $otherCompany = Company::factory()->create();
    $foreignEmployee = workScheduleAssignmentEmployee($otherCompany);
    $foreignSchedule = workScheduleAssignmentSchedule($otherCompany);

    $this->actingAs($hr)->get(route('companies.work-schedule-assignments.create', $company))
        ->assertInertia(fn (Assert $page) => $page->where('employees.0.id', $employee->id)
            ->where('schedules.0.id', $schedule->id));
    $this->actingAs($hr)->get(route('companies.work-schedule-assignments.index', $otherCompany))->assertNotFound();
    $this->post(route('companies.work-schedule-assignments.store', $company), workScheduleAssignmentPayload($foreignEmployee, $schedule))->assertSessionHasErrors('employee_id');
    $this->post(route('companies.work-schedule-assignments.store', $company), workScheduleAssignmentPayload($employee, $foreignSchedule))->assertSessionHasErrors('work_schedule_id');
    $forgedCompanyPayload = workScheduleAssignmentPayload($employee, $schedule, ['company_id' => $otherCompany->id]);
    $this->post(route('companies.work-schedule-assignments.store', $company), $forgedCompanyPayload)->assertSessionHasErrors('company_id');

    $foreignAssignment = $otherCompany->employeeWorkScheduleAssignments()->create([
        'employee_id' => $foreignEmployee->id,
        'work_schedule_id' => $foreignSchedule->id,
        'effective_from' => '2026-10-01',
        'status' => 'active',
    ]);
    $this->put(route('companies.work-schedule-assignments.update', [$company, $foreignAssignment]), workScheduleAssignmentPayload($employee, $schedule))->assertNotFound();
    $this->delete(route('companies.work-schedule-assignments.destroy', [$company, $foreignAssignment]))->assertNotFound();
    expect($foreignAssignment->fresh())->not->toBeNull();
});

test('inactive or archived schedules and archived employees cannot be newly assigned', function () {
    [$hr, $company] = workScheduleAssignmentUser('hr_admin');
    $employee = workScheduleAssignmentEmployee($company);
    $inactiveSchedule = workScheduleAssignmentSchedule($company, ['status' => 'inactive']);
    $archivedSchedule = workScheduleAssignmentSchedule($company);
    $archivedSchedule->delete();

    $this->actingAs($hr)->post(route('companies.work-schedule-assignments.store', $company), workScheduleAssignmentPayload($employee, $inactiveSchedule))
        ->assertSessionHasErrors('work_schedule_id');
    $this->post(route('companies.work-schedule-assignments.store', $company), workScheduleAssignmentPayload($employee, $archivedSchedule))
        ->assertSessionHasErrors('work_schedule_id');

    $otherEmployee = workScheduleAssignmentEmployee($company);
    $otherEmployee->delete();
    $activeSchedule = workScheduleAssignmentSchedule($company);
    $this->post(route('companies.work-schedule-assignments.store', $company), workScheduleAssignmentPayload($otherEmployee, $activeSchedule))
        ->assertSessionHasErrors('employee_id');
});

test('active assignments reject inclusive boundary and open-ended overlaps but allow adjacent periods', function () {
    [$hr, $company] = workScheduleAssignmentUser('hr_admin');
    $employee = workScheduleAssignmentEmployee($company);
    $schedule = workScheduleAssignmentSchedule($company);
    $this->actingAs($hr);

    $this->post(route('companies.work-schedule-assignments.store', $company), workScheduleAssignmentPayload($employee, $schedule, [
        'effective_from' => '2026-10-01', 'effective_to' => '2026-10-31',
    ]))->assertRedirect();

    foreach ([
        ['effective_from' => '2026-10-31', 'effective_to' => '2026-11-30'],
        ['effective_from' => '2026-10-15', 'effective_to' => '2026-11-15'],
        ['effective_from' => '2026-09-01', 'effective_to' => '2026-11-15'],
        ['effective_from' => '2026-10-15', 'effective_to' => null],
    ] as $overlap) {
        $this->post(route('companies.work-schedule-assignments.store', $company), workScheduleAssignmentPayload($employee, $schedule, $overlap))
            ->assertSessionHasErrors('effective_from');
    }

    $this->post(route('companies.work-schedule-assignments.store', $company), workScheduleAssignmentPayload($employee, $schedule, [
        'effective_from' => '2026-11-01', 'effective_to' => '2026-11-30',
    ]))->assertRedirect();

    expect($company->employeeWorkScheduleAssignments()->count())->toBe(2);
});

test('an open-ended active assignment blocks later assignments and reversed dates are rejected', function () {
    [$hr, $company] = workScheduleAssignmentUser('hr_admin');
    $employee = workScheduleAssignmentEmployee($company);
    $schedule = workScheduleAssignmentSchedule($company);
    $this->actingAs($hr)->post(route('companies.work-schedule-assignments.store', $company), workScheduleAssignmentPayload($employee, $schedule))
        ->assertRedirect();

    $this->post(route('companies.work-schedule-assignments.store', $company), workScheduleAssignmentPayload($employee, $schedule, [
        'effective_from' => '2027-01-01', 'effective_to' => null,
    ]))->assertSessionHasErrors('effective_from');
    $this->post(route('companies.work-schedule-assignments.store', $company), workScheduleAssignmentPayload($employee, $schedule, [
        'effective_from' => '2027-02-01', 'effective_to' => '2027-01-01',
    ]))->assertSessionHasErrors('effective_to');

    expect($company->employeeWorkScheduleAssignments()->count())->toBe(1);
});

test('inactive history does not overlap and may coexist with an active assignment', function () {
    [$hr, $company] = workScheduleAssignmentUser('hr_admin');
    $employee = workScheduleAssignmentEmployee($company);
    $schedule = workScheduleAssignmentSchedule($company);
    $company->employeeWorkScheduleAssignments()->create([
        'employee_id' => $employee->id, 'work_schedule_id' => $schedule->id,
        'effective_from' => '2026-10-01', 'status' => EmployeeWorkScheduleAssignmentStatus::Inactive,
    ]);

    $this->actingAs($hr)->post(route('companies.work-schedule-assignments.store', $company), workScheduleAssignmentPayload($employee, $schedule))
        ->assertRedirect();

    expect($company->employeeWorkScheduleAssignments()->count())->toBe(2);
});

test('updates exclude their own period and reject changes that overlap another active assignment', function () {
    [$hr, $company] = workScheduleAssignmentUser('hr_admin');
    $employee = workScheduleAssignmentEmployee($company);
    $schedule = workScheduleAssignmentSchedule($company);
    $first = $company->employeeWorkScheduleAssignments()->create([
        'employee_id' => $employee->id, 'work_schedule_id' => $schedule->id,
        'effective_from' => '2026-01-01', 'effective_to' => '2026-03-31', 'status' => 'active',
    ]);
    $second = $company->employeeWorkScheduleAssignments()->create([
        'employee_id' => $employee->id, 'work_schedule_id' => $schedule->id,
        'effective_from' => '2026-04-01', 'effective_to' => '2026-06-30', 'status' => 'active',
    ]);

    $this->actingAs($hr)->put(route('companies.work-schedule-assignments.update', [$company, $second]), workScheduleAssignmentPayload($employee, $schedule, [
        'effective_from' => '2026-03-01', 'effective_to' => '2026-06-30',
    ]))->assertSessionHasErrors('effective_from');
    $this->put(route('companies.work-schedule-assignments.update', [$company, $second]), workScheduleAssignmentPayload($employee, $schedule, [
        'effective_from' => '2026-04-01', 'effective_to' => '2026-06-30', 'notes' => 'Updated notes',
    ]))->assertRedirect();

    expect($first->fresh()->effective_from->toDateString())->toBe('2026-01-01')
        ->and($second->fresh()->notes)->toBe('Updated notes')
        ->and($second->fresh()->updated_by)->toBe($hr->id);
});

test('date lookup returns historical current and future schedules using the company timezone', function () {
    [, $company] = workScheduleAssignmentUser('hr_admin');
    $company->update(['timezone' => 'Asia/Kolkata']);
    $employee = workScheduleAssignmentEmployee($company);
    $historicalSchedule = workScheduleAssignmentSchedule($company);
    $currentSchedule = workScheduleAssignmentSchedule($company);
    $futureSchedule = workScheduleAssignmentSchedule($company);
    $company->employeeWorkScheduleAssignments()->createMany([
        ['employee_id' => $employee->id, 'work_schedule_id' => $historicalSchedule->id, 'effective_from' => '2026-01-01', 'effective_to' => '2026-09-30', 'status' => 'active'],
        ['employee_id' => $employee->id, 'work_schedule_id' => $currentSchedule->id, 'effective_from' => '2026-10-01', 'effective_to' => '2026-10-31', 'status' => 'active'],
        ['employee_id' => $employee->id, 'work_schedule_id' => $futureSchedule->id, 'effective_from' => '2026-11-01', 'effective_to' => null, 'status' => 'active'],
    ]);

    expect($employee->workScheduleAssignmentForDate('2026-02-01')?->work_schedule_id)->toBe($historicalSchedule->id)
        ->and($employee->workScheduleAssignmentForDate('2026-10-31')?->work_schedule_id)->toBe($currentSchedule->id)
        ->and($employee->workScheduleAssignmentForDate('2026-11-01')?->work_schedule_id)->toBe($futureSchedule->id);

    $this->travelTo('2026-10-31 18:00:00 UTC');
    expect($employee->currentWorkScheduleAssignment()?->work_schedule_id)->toBe($currentSchedule->id);
    $this->travelTo('2026-10-31 18:30:00 UTC');
    expect($employee->currentWorkScheduleAssignment()?->work_schedule_id)->toBe($futureSchedule->id);
    $this->travelBack();
    expect($employee->workScheduleAssignments()->count())->toBe(3);
});

test('authorized deletion archives the record and retains it in storage', function () {
    [$owner, $company] = workScheduleAssignmentUser('company_owner');
    $employee = workScheduleAssignmentEmployee($company);
    $schedule = workScheduleAssignmentSchedule($company);
    $assignment = $company->employeeWorkScheduleAssignments()->create([
        'employee_id' => $employee->id, 'work_schedule_id' => $schedule->id,
        'effective_from' => '2026-01-01', 'status' => 'active',
    ]);

    $this->actingAs($owner)->delete(route('companies.work-schedule-assignments.destroy', [$company, $assignment]))->assertRedirect();

    $this->assertSoftDeleted('employee_work_schedule_assignments', ['id' => $assignment->id]);
    expect(EmployeeWorkScheduleAssignment::withTrashed()->find($assignment->id))->not->toBeNull();
});

test('managers can view but cannot manage assignments while employees and accountants cannot view them', function () {
    [$manager, $managerCompany] = workScheduleAssignmentUser('manager');
    $employee = workScheduleAssignmentEmployee($managerCompany);
    $schedule = workScheduleAssignmentSchedule($managerCompany);
    $assignment = $managerCompany->employeeWorkScheduleAssignments()->create([
        'employee_id' => $employee->id, 'work_schedule_id' => $schedule->id,
        'effective_from' => '2026-01-01', 'status' => 'active',
    ]);

    $this->actingAs($manager)->get(route('companies.work-schedule-assignments.index', $managerCompany))->assertOk();
    $this->get(route('companies.work-schedule-assignments.create', $managerCompany))->assertForbidden();
    $this->post(route('companies.work-schedule-assignments.store', $managerCompany), workScheduleAssignmentPayload($employee, $schedule))
        ->assertForbidden();
    $this->put(route('companies.work-schedule-assignments.update', [$managerCompany, $assignment]), workScheduleAssignmentPayload($employee, $schedule))
        ->assertForbidden();
    $this->delete(route('companies.work-schedule-assignments.destroy', [$managerCompany, $assignment]))->assertForbidden();

    [$employeeUser, $employeeCompany] = workScheduleAssignmentUser('employee');
    [$accountant, $accountantCompany] = workScheduleAssignmentUser('accountant');
    $this->actingAs($employeeUser)->get(route('companies.work-schedule-assignments.index', $employeeCompany))->assertForbidden();
    $this->actingAs($accountant)->get(route('companies.work-schedule-assignments.index', $accountantCompany))->assertForbidden();
});

test('HR managers and company owners receive their configured assignment permissions', function () {
    [$hrManager, $managerCompany] = workScheduleAssignmentUser('hr_manager');
    $employee = workScheduleAssignmentEmployee($managerCompany);
    $schedule = workScheduleAssignmentSchedule($managerCompany);
    $this->actingAs($hrManager)->post(route('companies.work-schedule-assignments.store', $managerCompany), workScheduleAssignmentPayload($employee, $schedule))
        ->assertRedirect();

    app(PermissionRegistrar::class)->setPermissionsTeamId($managerCompany->id);
    expect($hrManager->can('work_schedule_assignment.delete'))->toBeFalse();

    [$owner, $ownerCompany] = workScheduleAssignmentUser('company_owner');
    app(PermissionRegistrar::class)->setPermissionsTeamId($ownerCompany->id);
    expect($owner->can('work_schedule_assignment.view'))->toBeTrue()
        ->and($owner->can('work_schedule_assignment.create'))->toBeTrue()
        ->and($owner->can('work_schedule_assignment.update'))->toBeTrue()
        ->and($owner->can('work_schedule_assignment.delete'))->toBeTrue();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    expect($ownerCompany->employeeWorkScheduleAssignments()->count())->toBe(0);
});

test('database composite foreign keys reject employee and schedule company mismatches', function () {
    [, $company] = workScheduleAssignmentUser('hr_admin');
    [, $otherCompany] = workScheduleAssignmentUser('hr_admin');
    $employee = workScheduleAssignmentEmployee($company);
    $foreignEmployee = workScheduleAssignmentEmployee($otherCompany);
    $schedule = workScheduleAssignmentSchedule($company);
    $foreignSchedule = workScheduleAssignmentSchedule($otherCompany);

    expect(fn () => $company->employeeWorkScheduleAssignments()->create([
        'employee_id' => $foreignEmployee->id, 'work_schedule_id' => $schedule->id,
        'effective_from' => '2026-01-01', 'status' => 'active',
    ]))->toThrow(QueryException::class);
    expect(fn () => $company->employeeWorkScheduleAssignments()->create([
        'employee_id' => $employee->id, 'work_schedule_id' => $foreignSchedule->id,
        'effective_from' => '2026-01-01', 'status' => 'active',
    ]))->toThrow(QueryException::class);
});

test('the index searches and filters schedules with server-side pagination', function () {
    [$hr, $company] = workScheduleAssignmentUser('hr_admin');
    $schedule = workScheduleAssignmentSchedule($company, ['name' => 'Standard Weekly', 'code' => 'STANDARD-WEEKLY']);

    $historicalEmployeeCode = null;
    foreach (range(1, 22) as $index) {
        $employee = workScheduleAssignmentEmployee($company);
        if ($index === 1) {
            $historicalEmployeeCode = $employee->employee_code;
        }
        $company->employeeWorkScheduleAssignments()->create([
            'employee_id' => $employee->id, 'work_schedule_id' => $schedule->id,
            'effective_from' => $index === 1 ? '2026-01-01' : '2026-10-01',
            'effective_to' => $index === 1 ? '2026-02-01' : null,
            'status' => $index === 2 ? 'inactive' : 'active',
        ]);
    }

    $this->actingAs($hr)->get(route('companies.work-schedule-assignments.index', $company).'?search=STANDARD&schedule_id='.$schedule->id.'&status=active&period=historical')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('employee-work-schedule-assignments/index', false)
            ->where('assignments.total', 1)
            ->where('assignments.data.0.schedule.name', 'Standard Weekly')
            ->where('assignments.data.0.period', 'historical'));

    $this->get(route('companies.work-schedule-assignments.index', $company).'?employee='.$historicalEmployeeCode)
        ->assertInertia(fn (Assert $page) => $page->where('assignments.total', 1)
            ->where('assignments.data.0.employee.code', $historicalEmployeeCode));

    $this->get(route('companies.work-schedule-assignments.index', $company))
        ->assertInertia(fn (Assert $page) => $page->has('assignments.data', 20)->where('assignments.total', 22));
});

test('super admins can view assignments for a selected company', function () {
    [, $company] = workScheduleAssignmentUser('hr_admin');
    $employee = workScheduleAssignmentEmployee($company);
    $schedule = workScheduleAssignmentSchedule($company);
    $company->employeeWorkScheduleAssignments()->create([
        'employee_id' => $employee->id, 'work_schedule_id' => $schedule->id,
        'effective_from' => '2026-01-01', 'status' => 'active',
    ]);
    app(RolePermissionSeeder::class)->run();
    $superAdmin = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId(User::SYSTEM_TEAM_ID);
    $superAdmin->assignRole(Role::query()->where('team_id', User::SYSTEM_TEAM_ID)->where('name', 'super_admin')->firstOrFail());
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $this->actingAs($superAdmin)->get(route('companies.work-schedule-assignments.index', $company))->assertOk();
});
