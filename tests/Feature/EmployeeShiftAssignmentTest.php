<?php

use App\Actions\SeedCompanyRoles;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\Shift;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** @return array{User, Company} */
function assignmentModuleUser(string $role): array
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

/** @return array{Employee, Shift} */
function assignmentModuleEntities(Company $company, string $suffix = '001'): array
{
    $employee = $company->employees()->create([
        'employee_code' => 'EMP-'.$suffix, 'first_name' => 'Jordan', 'last_name' => 'Lee',
        'joining_date' => '2026-01-01', 'employment_type' => 'full_time', 'employment_status' => 'active',
    ]);
    $shift = $company->shifts()->create([
        'name' => 'Morning '.$suffix, 'code' => 'MOR-'.$suffix, 'start_time' => '09:00', 'end_time' => '18:00',
        'break_duration_minutes' => 30, 'grace_period_minutes' => 10, 'is_overnight' => false, 'status' => 'active',
    ]);

    return [$employee, $shift];
}

/** @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function assignmentModulePayload(Employee $employee, Shift $shift, array $overrides = []): array
{
    return array_merge([
        'employee_id' => $employee->id, 'shift_id' => $shift->id, 'effective_from' => '2026-01-01',
        'effective_to' => null, 'status' => 'active', 'notes' => 'Initial assignment',
    ], $overrides);
}

test('HR users create and list assignments with company and audit data', function () {
    [$hr, $company] = assignmentModuleUser('hr_admin');
    [$employee, $shift] = assignmentModuleEntities($company);

    $this->actingAs($hr)->post(route('companies.shift-assignments.store', $company), assignmentModulePayload($employee, $shift))
        ->assertRedirect(route('companies.shift-assignments.index', $company));
    $assignment = $company->employeeShiftAssignments()->firstOrFail();
    expect($assignment->employee_id)->toBe($employee->id)
        ->and($assignment->shift_id)->toBe($shift->id)
        ->and($assignment->created_by)->toBe($hr->id)
        ->and($assignment->effective_from->toDateString())->toBe('2026-01-01');

    $this->get(route('companies.shift-assignments.index', $company))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('shift-assignments/index', false)
            ->has('assignments.data', 1)->where('assignments.data.0.employee.code', $employee->employee_code)
            ->where('assignments.data.0.shift.name', $shift->name));
});

test('cross company assignments cannot be viewed selected or persisted', function () {
    [$hr, $company] = assignmentModuleUser('hr_admin');
    [$employee, $shift] = assignmentModuleEntities($company);
    $otherCompany = Company::factory()->create();
    [$foreignEmployee, $foreignShift] = assignmentModuleEntities($otherCompany, '002');

    $this->actingAs($hr)->get(route('companies.shift-assignments.index', $otherCompany))->assertNotFound();
    $this->post(route('companies.shift-assignments.store', $company), assignmentModulePayload($foreignEmployee, $shift))->assertSessionHasErrors('employee_id');
    $this->post(route('companies.shift-assignments.store', $company), assignmentModulePayload($employee, $foreignShift))->assertSessionHasErrors('shift_id');
    expect(fn () => EmployeeShiftAssignment::query()->create([
        'company_id' => $company->id, 'employee_id' => $employee->id, 'shift_id' => $foreignShift->id,
        'effective_from' => '2026-01-01', 'status' => 'active',
    ]))->toThrow(QueryException::class);

    $ownAssignment = $company->employeeShiftAssignments()->create([
        'employee_id' => $employee->id, 'shift_id' => $shift->id, 'effective_from' => '2026-01-01', 'status' => 'active',
    ]);
    $foreignAssignment = $otherCompany->employeeShiftAssignments()->create([
        'employee_id' => $foreignEmployee->id, 'shift_id' => $foreignShift->id, 'effective_from' => '2026-01-01', 'status' => 'active',
    ]);
    $this->put(route('companies.shift-assignments.update', [$company, $foreignAssignment]), assignmentModulePayload($employee, $shift))->assertNotFound();
    $this->delete(route('companies.shift-assignments.destroy', [$company, $foreignAssignment]))->assertNotFound();
    expect($ownAssignment->fresh())->not->toBeNull()->and($foreignAssignment->fresh())->not->toBeNull();
});

test('reversed dates are rejected and inclusive overlapping active periods are blocked', function () {
    [$hr, $company] = assignmentModuleUser('hr_admin');
    [$employee, $shift] = assignmentModuleEntities($company);
    $this->actingAs($hr);

    $this->post(route('companies.shift-assignments.store', $company), assignmentModulePayload($employee, $shift, [
        'effective_from' => '2026-10-10', 'effective_to' => '2026-10-01',
    ]))->assertSessionHasErrors('effective_to');
    $this->post(route('companies.shift-assignments.store', $company), assignmentModulePayload($employee, $shift, [
        'effective_from' => '2026-10-01', 'effective_to' => '2026-10-31',
    ]))->assertRedirect();

    foreach ([
        ['effective_from' => '2026-10-10', 'effective_to' => '2026-10-20'],
        ['effective_from' => '2026-10-31', 'effective_to' => '2026-11-10'],
        ['effective_from' => '2026-10-31', 'effective_to' => null],
    ] as $index => $overlap) {
        [$anotherEmployee] = assignmentModuleEntities($company, '00'.($index + 2));
        $company->employeeShiftAssignments()->create([
            'employee_id' => $anotherEmployee->id, 'shift_id' => $shift->id,
            'effective_from' => '2026-10-01', 'effective_to' => '2026-10-31', 'status' => 'active',
        ]);
        $this->post(route('companies.shift-assignments.store', $company), assignmentModulePayload($anotherEmployee, $shift, $overlap))
            ->assertSessionHasErrors('employee_id');
    }

    foreach ([
        ['effective_from' => '2026-10-10', 'effective_to' => '2026-10-20'],
        ['effective_from' => '2026-10-01', 'effective_to' => null],
    ] as $index => $overlap) {
        [$anotherEmployee] = assignmentModuleEntities($company, '00'.($index + 5));
        $company->employeeShiftAssignments()->create([
            'employee_id' => $anotherEmployee->id, 'shift_id' => $shift->id,
            'effective_from' => '2026-10-01', 'effective_to' => null, 'status' => 'active',
        ]);
        $this->post(route('companies.shift-assignments.store', $company), assignmentModulePayload($anotherEmployee, $shift, $overlap))
            ->assertSessionHasErrors('employee_id');
    }
});

test('adjacent and open ended assignments are allowed and inactive history does not overlap active periods', function () {
    [$hr, $company] = assignmentModuleUser('hr_admin');
    [$employee, $shift] = assignmentModuleEntities($company);
    $this->actingAs($hr)->post(route('companies.shift-assignments.store', $company), assignmentModulePayload($employee, $shift, [
        'effective_from' => '2026-10-01', 'effective_to' => '2026-10-31',
    ]))->assertRedirect();
    $this->post(route('companies.shift-assignments.store', $company), assignmentModulePayload($employee, $shift, [
        'effective_from' => '2026-11-01', 'effective_to' => null,
    ]))->assertRedirect();

    [$secondEmployee] = assignmentModuleEntities($company, '002');
    $company->employeeShiftAssignments()->create([
        'employee_id' => $secondEmployee->id, 'shift_id' => $shift->id,
        'effective_from' => '2026-10-01', 'effective_to' => null, 'status' => 'inactive',
    ]);
    $this->post(route('companies.shift-assignments.store', $company), assignmentModulePayload($secondEmployee, $shift, [
        'effective_from' => '2026-10-01', 'effective_to' => null,
    ]))->assertRedirect();

    expect($company->employeeShiftAssignments()->count())->toBe(4);
});

test('date lookup returns historical current and future assignments without changing history', function () {
    [, $company] = assignmentModuleUser('hr_admin');
    [$employee, $firstShift] = assignmentModuleEntities($company);
    $secondShift = $company->shifts()->create([
        'name' => 'Evening', 'code' => 'EVE', 'start_time' => '14:00', 'end_time' => '22:00',
        'break_duration_minutes' => 0, 'grace_period_minutes' => 0, 'is_overnight' => false, 'status' => 'active',
    ]);
    $thirdShift = $company->shifts()->create([
        'name' => 'Night', 'code' => 'NIGHT', 'start_time' => '22:00', 'end_time' => '06:00',
        'break_duration_minutes' => 0, 'grace_period_minutes' => 0, 'is_overnight' => true, 'status' => 'active',
    ]);
    $company->employeeShiftAssignments()->createMany([
        ['employee_id' => $employee->id, 'shift_id' => $firstShift->id, 'effective_from' => '2026-01-01', 'effective_to' => '2026-03-31', 'status' => 'active'],
        ['employee_id' => $employee->id, 'shift_id' => $secondShift->id, 'effective_from' => '2026-04-01', 'effective_to' => '2026-06-30', 'status' => 'active'],
        ['employee_id' => $employee->id, 'shift_id' => $thirdShift->id, 'effective_from' => '2026-07-01', 'effective_to' => null, 'status' => 'active'],
    ]);

    Carbon::setTestNow('2026-04-15');
    expect($employee->currentShiftAssignment()?->shift_id)->toBe($secondShift->id)
        ->and($employee->shiftAssignmentForDate('2026-02-01')?->shift_id)->toBe($firstShift->id)
        ->and($employee->shiftAssignmentForDate('2026-07-01')?->shift_id)->toBe($thirdShift->id);
    Carbon::setTestNow();
    expect($employee->shiftAssignments()->count())->toBe(3);
});

test('updates revalidate overlaps while excluding the assignment being edited', function () {
    [$hr, $company] = assignmentModuleUser('hr_admin');
    [$employee, $shift] = assignmentModuleEntities($company);
    $first = $company->employeeShiftAssignments()->create([
        'employee_id' => $employee->id, 'shift_id' => $shift->id, 'effective_from' => '2026-01-01',
        'effective_to' => '2026-03-31', 'status' => 'active',
    ]);
    $second = $company->employeeShiftAssignments()->create([
        'employee_id' => $employee->id, 'shift_id' => $shift->id, 'effective_from' => '2026-04-01',
        'effective_to' => '2026-06-30', 'status' => 'active',
    ]);
    $this->actingAs($hr)->put(route('companies.shift-assignments.update', [$company, $second]), assignmentModulePayload($employee, $shift, [
        'effective_from' => '2026-03-01', 'effective_to' => '2026-06-30',
    ]))->assertSessionHasErrors('employee_id');
    $this->put(route('companies.shift-assignments.update', [$company, $second]), assignmentModulePayload($employee, $shift, [
        'effective_from' => '2026-04-01', 'effective_to' => '2026-06-30',
    ]))->assertRedirect();
    expect($first->fresh()->effective_from->toDateString())->toBe('2026-01-01')->and($second->fresh()->updated_by)->toBe($hr->id);
});

test('managers have read only access and owners archive assignment records without erasing history', function () {
    [$manager, $managerCompany] = assignmentModuleUser('manager');
    [$managerEmployee, $managerShift] = assignmentModuleEntities($managerCompany);
    $managerCompany->employeeShiftAssignments()->create([
        'employee_id' => $managerEmployee->id, 'shift_id' => $managerShift->id,
        'effective_from' => '2026-01-01', 'status' => 'active',
    ]);
    $this->actingAs($manager)->get(route('companies.shift-assignments.index', $managerCompany))->assertOk();
    $this->get(route('companies.shift-assignments.create', $managerCompany))->assertForbidden();

    $managerEmployee = $managerCompany->employees()->create([
        'employee_code' => 'EMP-MGR', 'user_id' => $manager->id, 'first_name' => 'Team', 'last_name' => 'Lead',
        'joining_date' => '2026-01-01', 'employment_type' => 'full_time', 'employment_status' => 'active',
    ]);
    [$report, $managerShift] = assignmentModuleEntities($managerCompany, '004');
    $report->update(['manager_id' => $managerEmployee->id]);
    [$otherEmployee] = assignmentModuleEntities($managerCompany, '005');
    foreach ([$managerEmployee, $report, $otherEmployee] as $teamEmployee) {
        $managerCompany->employeeShiftAssignments()->create([
            'employee_id' => $teamEmployee->id, 'shift_id' => $managerShift->id,
            'effective_from' => '2026-01-01', 'status' => 'active',
        ]);
    }
    $this->get(route('companies.shift-assignments.index', $managerCompany))->assertInertia(
        fn (Assert $page) => $page->has('assignments.data', 2),
    );

    [$owner, $ownerCompany] = assignmentModuleUser('company_owner');
    [$employee, $shift] = assignmentModuleEntities($ownerCompany);
    $assignment = $ownerCompany->employeeShiftAssignments()->create([
        'employee_id' => $employee->id, 'shift_id' => $shift->id,
        'effective_from' => '2026-01-01', 'status' => 'active',
    ]);
    $this->actingAs($owner)->delete(route('companies.shift-assignments.destroy', [$ownerCompany, $assignment]))->assertRedirect();
    expect($assignment->fresh()->trashed())->toBeTrue();
});

test('assigned shifts cannot be hard deleted while assignment history references them', function () {
    [$owner, $company] = assignmentModuleUser('company_owner');
    [$employee, $shift] = assignmentModuleEntities($company);
    $company->employeeShiftAssignments()->create([
        'employee_id' => $employee->id, 'shift_id' => $shift->id,
        'effective_from' => '2026-01-01', 'status' => 'active',
    ]);

    $this->actingAs($owner)->delete(route('companies.shifts.destroy', [$company, $shift]))->assertForbidden();
    expect($shift->fresh())->not->toBeNull();
});

test('employees and accountants cannot view assignments and super admins can access selected companies', function () {
    [$employeeUser, $employeeCompany] = assignmentModuleUser('employee');
    [$accountant, $accountantCompany] = assignmentModuleUser('accountant');
    $this->actingAs($employeeUser)->get(route('companies.shift-assignments.index', $employeeCompany))->assertForbidden();
    $this->actingAs($accountant)->get(route('companies.shift-assignments.index', $accountantCompany))->assertForbidden();

    $company = Company::factory()->create();
    [$employee, $shift] = assignmentModuleEntities($company, '003');
    $company->employeeShiftAssignments()->create([
        'employee_id' => $employee->id, 'shift_id' => $shift->id, 'effective_from' => '2026-01-01', 'status' => 'active',
    ]);
    app(RolePermissionSeeder::class)->run();
    $superAdmin = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId(User::SYSTEM_TEAM_ID);
    $superAdmin->assignRole(Role::query()->where('team_id', User::SYSTEM_TEAM_ID)->where('name', 'super_admin')->firstOrFail());
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $this->actingAs($superAdmin)->get(route('companies.shift-assignments.index', $company))->assertOk();
});
