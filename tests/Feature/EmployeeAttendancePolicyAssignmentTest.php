<?php

use App\Actions\SeedCompanyRoles;
use App\Enums\AttendancePolicyStatus;
use App\Enums\EmployeeAttendancePolicyAssignmentStatus;
use App\Models\AttendancePolicy;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\EmployeeAttendancePolicyAssignment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** @return array{User, Company} */
function attendancePolicyAssignmentUser(string $role): array
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

function attendancePolicyAssignmentEmployee(Company $company, array $attributes = []): Employee
{
    return Employee::factory()->create(array_merge(['company_id' => $company->id], $attributes));
}

function attendancePolicyAssignmentPolicy(Company $company, array $attributes = []): AttendancePolicy
{
    return $company->attendancePolicies()->create(array_merge([
        'name' => 'Standard Attendance',
        'code' => 'STD-ATT',
        'description' => null,
        'status' => AttendancePolicyStatus::Active,
    ], $attributes));
}

function attendancePolicyAssignmentRecord(Company $company, Employee $employee, AttendancePolicy $policy, array $attributes = []): EmployeeAttendancePolicyAssignment
{
    return $company->employeeAttendancePolicyAssignments()->create(array_merge([
        'employee_id' => $employee->id,
        'attendance_policy_id' => $policy->id,
        'effective_from' => '2026-01-01',
        'effective_to' => null,
        'status' => EmployeeAttendancePolicyAssignmentStatus::Active,
    ], $attributes));
}

/** @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function attendancePolicyAssignmentPayload(Employee $employee, AttendancePolicy $policy, array $overrides = []): array
{
    return array_merge([
        'employee_id' => $employee->id,
        'attendance_policy_id' => $policy->id,
        'effective_from' => '2026-01-01',
        'effective_to' => null,
        'status' => 'active',
        'notes' => 'Assigned by HR',
    ], $overrides);
}

test('authorized HR can create an assignment with company ownership and audit fields', function () {
    [$hr, $company] = attendancePolicyAssignmentUser('hr_admin');
    $employee = attendancePolicyAssignmentEmployee($company);
    $policy = attendancePolicyAssignmentPolicy($company);

    $this->actingAs($hr)->post(route('companies.attendance-policy-assignments.store', $company), attendancePolicyAssignmentPayload($employee, $policy))
        ->assertRedirect(route('companies.attendance-policy-assignments.index', $company));

    $assignment = $company->employeeAttendancePolicyAssignments()->firstOrFail();
    expect($assignment->company_id)->toBe($company->id)
        ->and($assignment->employee_id)->toBe($employee->id)
        ->and($assignment->attendance_policy_id)->toBe($policy->id)
        ->and($assignment->effective_from->toDateString())->toBe('2026-01-01')
        ->and($assignment->effective_to)->toBeNull()
        ->and($assignment->status)->toBe(EmployeeAttendancePolicyAssignmentStatus::Active)
        ->and($assignment->created_by)->toBe($hr->id)
        ->and($assignment->updated_by)->toBe($hr->id);
});

test('inactive assignments can be created without occupying an active effective period', function () {
    [$hr, $company] = attendancePolicyAssignmentUser('hr_admin');
    $employee = attendancePolicyAssignmentEmployee($company);
    $policy = attendancePolicyAssignmentPolicy($company);
    $this->actingAs($hr);

    $this->post(route('companies.attendance-policy-assignments.store', $company), attendancePolicyAssignmentPayload($employee, $policy, ['status' => 'inactive']))
        ->assertRedirect();
    $this->post(route('companies.attendance-policy-assignments.store', $company), attendancePolicyAssignmentPayload($employee, $policy, ['effective_from' => '2026-03-01']))
        ->assertRedirect();

    expect($company->employeeAttendancePolicyAssignments()->count())->toBe(2);
});

test('inactive or soft-deleted policies and soft-deleted employees cannot receive new assignments', function () {
    [$hr, $company] = attendancePolicyAssignmentUser('hr_admin');
    $employee = attendancePolicyAssignmentEmployee($company);
    $inactivePolicy = attendancePolicyAssignmentPolicy($company, ['name' => 'Inactive policy', 'code' => 'INACTIVE', 'status' => AttendancePolicyStatus::Inactive]);
    $archivedPolicy = attendancePolicyAssignmentPolicy($company, ['name' => 'Archived policy', 'code' => 'ARCHIVED']);
    $archivedPolicy->delete();
    $archivedEmployee = attendancePolicyAssignmentEmployee($company);
    $archivedEmployee->delete();
    $activePolicy = attendancePolicyAssignmentPolicy($company);
    $this->actingAs($hr);

    $this->post(route('companies.attendance-policy-assignments.store', $company), attendancePolicyAssignmentPayload($employee, $inactivePolicy))
        ->assertSessionHasErrors('attendance_policy_id');
    $this->post(route('companies.attendance-policy-assignments.store', $company), attendancePolicyAssignmentPayload($employee, $archivedPolicy))
        ->assertSessionHasErrors('attendance_policy_id');
    $this->post(route('companies.attendance-policy-assignments.store', $company), attendancePolicyAssignmentPayload($archivedEmployee, $activePolicy))
        ->assertSessionHasErrors('employee_id');

    expect($company->employeeAttendancePolicyAssignments()->exists())->toBeFalse();
});

test('cross-company employee and attendance policy selections are rejected', function () {
    [$hr, $company] = attendancePolicyAssignmentUser('hr_admin');
    $foreignCompany = Company::factory()->create();
    $employee = attendancePolicyAssignmentEmployee($company);
    $policy = attendancePolicyAssignmentPolicy($company);
    $foreignEmployee = attendancePolicyAssignmentEmployee($foreignCompany);
    $foreignPolicy = attendancePolicyAssignmentPolicy($foreignCompany);

    $this->actingAs($hr)->post(route('companies.attendance-policy-assignments.store', $company), attendancePolicyAssignmentPayload($employee, $policy, ['employee_id' => $foreignEmployee->id]))
        ->assertSessionHasErrors('employee_id');
    $this->post(route('companies.attendance-policy-assignments.store', $company), attendancePolicyAssignmentPayload($employee, $policy, ['attendance_policy_id' => $foreignPolicy->id]))
        ->assertSessionHasErrors('attendance_policy_id');
    $this->post(route('companies.attendance-policy-assignments.store', $company), attendancePolicyAssignmentPayload($employee, $policy, ['company_id' => $foreignCompany->id]))
        ->assertSessionHasErrors('company_id');
});

test('date validation rejects invalid dates and end dates before the start', function () {
    [$hr, $company] = attendancePolicyAssignmentUser('hr_admin');
    $employee = attendancePolicyAssignmentEmployee($company);
    $policy = attendancePolicyAssignmentPolicy($company);
    $this->actingAs($hr);

    $this->post(route('companies.attendance-policy-assignments.store', $company), attendancePolicyAssignmentPayload($employee, $policy, ['effective_from' => 'not-a-date']))
        ->assertSessionHasErrors('effective_from');
    $this->post(route('companies.attendance-policy-assignments.store', $company), attendancePolicyAssignmentPayload($employee, $policy, ['effective_from' => '2026-06-30', 'effective_to' => '2026-06-29']))
        ->assertSessionHasErrors('effective_to');

    expect($company->employeeAttendancePolicyAssignments()->exists())->toBeFalse();
});

test('inclusive overlaps are rejected and adjacent active periods are accepted', function () {
    [$hr, $company] = attendancePolicyAssignmentUser('hr_admin');
    $employee = attendancePolicyAssignmentEmployee($company);
    $firstPolicy = attendancePolicyAssignmentPolicy($company);
    $secondPolicy = attendancePolicyAssignmentPolicy($company, ['name' => 'Second policy', 'code' => 'SECOND']);
    attendancePolicyAssignmentRecord($company, $employee, $firstPolicy, ['effective_from' => '2026-01-01', 'effective_to' => '2026-06-30']);
    $this->actingAs($hr);

    $this->post(route('companies.attendance-policy-assignments.store', $company), attendancePolicyAssignmentPayload($employee, $secondPolicy, ['effective_from' => '2026-06-30']))
        ->assertSessionHasErrors('effective_from');
    $this->post(route('companies.attendance-policy-assignments.store', $company), attendancePolicyAssignmentPayload($employee, $secondPolicy, ['effective_from' => '2026-07-01', 'effective_to' => '2026-12-31']))
        ->assertRedirect();

    expect($company->employeeAttendancePolicyAssignments()->count())->toBe(2);
});

test('partial and enclosing inclusive periods are rejected', function () {
    [$hr, $company] = attendancePolicyAssignmentUser('hr_admin');
    $policy = attendancePolicyAssignmentPolicy($company);
    $secondPolicy = attendancePolicyAssignmentPolicy($company, ['name' => 'Second policy', 'code' => 'SECOND']);
    $containedEmployee = attendancePolicyAssignmentEmployee($company);
    $enclosedEmployee = attendancePolicyAssignmentEmployee($company);
    attendancePolicyAssignmentRecord($company, $containedEmployee, $policy, ['effective_from' => '2026-01-01', 'effective_to' => '2026-12-31']);
    attendancePolicyAssignmentRecord($company, $enclosedEmployee, $policy, ['effective_from' => '2026-04-01', 'effective_to' => '2026-06-30']);
    $this->actingAs($hr);

    $this->post(route('companies.attendance-policy-assignments.store', $company), attendancePolicyAssignmentPayload($containedEmployee, $secondPolicy, ['effective_from' => '2026-03-01', 'effective_to' => '2026-04-01']))
        ->assertSessionHasErrors('effective_from');
    $this->post(route('companies.attendance-policy-assignments.store', $company), attendancePolicyAssignmentPayload($enclosedEmployee, $secondPolicy, ['effective_from' => '2026-01-01', 'effective_to' => '2026-12-31']))
        ->assertSessionHasErrors('effective_from');

    expect($company->employeeAttendancePolicyAssignments()->count())->toBe(2);
});

test('open-ended assignment blocks later active periods but inactive and soft-deleted history does not', function () {
    [$hr, $company] = attendancePolicyAssignmentUser('hr_admin');
    $employee = attendancePolicyAssignmentEmployee($company);
    $policy = attendancePolicyAssignmentPolicy($company);
    $secondPolicy = attendancePolicyAssignmentPolicy($company, ['name' => 'Second policy', 'code' => 'SECOND']);
    attendancePolicyAssignmentRecord($company, $employee, $policy, ['effective_from' => '2026-01-01', 'effective_to' => null]);
    $this->actingAs($hr);

    $this->post(route('companies.attendance-policy-assignments.store', $company), attendancePolicyAssignmentPayload($employee, $secondPolicy, ['effective_from' => '2026-06-01']))
        ->assertSessionHasErrors('effective_from');

    $existing = $company->employeeAttendancePolicyAssignments()->firstOrFail();
    $existing->update(['status' => EmployeeAttendancePolicyAssignmentStatus::Inactive]);
    $this->post(route('companies.attendance-policy-assignments.store', $company), attendancePolicyAssignmentPayload($employee, $secondPolicy, ['effective_from' => '2026-06-01']))
        ->assertRedirect();
    $company->employeeAttendancePolicyAssignments()->where('status', 'active')->firstOrFail()->delete();
    $this->post(route('companies.attendance-policy-assignments.store', $company), attendancePolicyAssignmentPayload($employee, $policy, ['effective_from' => '2026-06-01']))
        ->assertRedirect();

    expect($company->employeeAttendancePolicyAssignments()->count())->toBe(2)
        ->and(EmployeeAttendancePolicyAssignment::withTrashed()->where('company_id', $company->id)->count())->toBe(3);
});

test('update excludes itself from overlap detection and rejects overlap with another assignment', function () {
    [$hr, $company] = attendancePolicyAssignmentUser('hr_admin');
    $employee = attendancePolicyAssignmentEmployee($company);
    $policy = attendancePolicyAssignmentPolicy($company);
    $otherPolicy = attendancePolicyAssignmentPolicy($company, ['name' => 'Other policy', 'code' => 'OTHER']);
    $assignment = attendancePolicyAssignmentRecord($company, $employee, $policy, ['effective_from' => '2026-01-01', 'effective_to' => '2026-06-30']);
    $otherAssignment = attendancePolicyAssignmentRecord($company, $employee, $otherPolicy, ['effective_from' => '2026-07-01', 'effective_to' => '2026-12-31']);
    $this->actingAs($hr);

    $this->put(route('companies.attendance-policy-assignments.update', [$company, $assignment]), attendancePolicyAssignmentPayload($employee, $policy, ['effective_to' => '2026-06-30', 'notes' => 'Updated notes']))
        ->assertRedirect();
    expect($assignment->fresh()->notes)->toBe('Updated notes');

    $this->put(route('companies.attendance-policy-assignments.update', [$company, $assignment]), attendancePolicyAssignmentPayload($employee, $policy, ['effective_to' => '2026-07-31']))
        ->assertSessionHasErrors('effective_from');
    expect($otherAssignment->fresh())->not->toBeNull();
});

test('assignments soft delete and disappear from listings and effective-date lookups', function () {
    [$owner, $company] = attendancePolicyAssignmentUser('company_owner');
    $employee = attendancePolicyAssignmentEmployee($company);
    $policy = attendancePolicyAssignmentPolicy($company);
    $assignment = attendancePolicyAssignmentRecord($company, $employee, $policy, ['effective_from' => '2026-01-01']);
    $this->actingAs($owner)->delete(route('companies.attendance-policy-assignments.destroy', [$company, $assignment]))->assertRedirect();

    expect(EmployeeAttendancePolicyAssignment::query()->find($assignment->id))->toBeNull()
        ->and(EmployeeAttendancePolicyAssignment::withTrashed()->findOrFail($assignment->id)->trashed())->toBeTrue()
        ->and($employee->attendancePolicyAssignmentForDate('2026-05-01'))->toBeNull();

    $this->get(route('companies.attendance-policy-assignments.index', $company))
        ->assertInertia(fn (Assert $page) => $page->component('attendance-policy-assignments/index', false)->has('assignments.data', 0));
});

test('employee policy assignment helpers resolve inclusive dates and adjacent history', function () {
    [, $company] = attendancePolicyAssignmentUser('company_owner');
    $employee = attendancePolicyAssignmentEmployee($company);
    $firstPolicy = attendancePolicyAssignmentPolicy($company);
    $secondPolicy = attendancePolicyAssignmentPolicy($company, ['name' => 'Second policy', 'code' => 'SECOND']);
    attendancePolicyAssignmentRecord($company, $employee, $firstPolicy, ['effective_from' => '2026-01-01', 'effective_to' => '2026-06-30']);
    attendancePolicyAssignmentRecord($company, $employee, $secondPolicy, ['effective_from' => '2026-07-01']);

    expect($employee->attendancePolicyAssignmentForDate('2025-12-31'))->toBeNull()
        ->and($employee->attendancePolicyAssignmentForDate('2026-01-01')?->attendance_policy_id)->toBe($firstPolicy->id)
        ->and($employee->attendancePolicyAssignmentForDate('2026-06-30')?->attendance_policy_id)->toBe($firstPolicy->id)
        ->and($employee->attendancePolicyAssignmentForDate('2026-07-01')?->attendance_policy_id)->toBe($secondPolicy->id)
        ->and($employee->attendancePolicyAssignmentForDate('2027-01-01')?->attendance_policy_id)->toBe($secondPolicy->id);
});

test('current assignment uses the employee company timezone date', function () {
    [, $company] = attendancePolicyAssignmentUser('company_owner');
    $company->update(['timezone' => 'Asia/Kolkata']);
    $employee = attendancePolicyAssignmentEmployee($company);
    $policy = attendancePolicyAssignmentPolicy($company);
    $assignment = attendancePolicyAssignmentRecord($company, $employee, $policy, ['effective_from' => '2027-01-01']);
    $this->travelTo(CarbonImmutable::parse('2026-12-31 18:30:00', 'UTC'));

    expect($employee->currentAttendancePolicyAssignment()?->is($assignment))->toBeTrue();
});

test('tenant routes scope assignment reads edits and deletes to the selected company', function () {
    [$owner, $company] = attendancePolicyAssignmentUser('company_owner');
    $employee = attendancePolicyAssignmentEmployee($company);
    $policy = attendancePolicyAssignmentPolicy($company);
    $foreignCompany = Company::factory()->create();
    $foreignEmployee = attendancePolicyAssignmentEmployee($foreignCompany);
    $foreignPolicy = attendancePolicyAssignmentPolicy($foreignCompany);
    $foreignAssignment = attendancePolicyAssignmentRecord($foreignCompany, $foreignEmployee, $foreignPolicy);

    $this->actingAs($owner)->get(route('companies.attendance-policy-assignments.index', $foreignCompany))->assertNotFound();
    $this->put(route('companies.attendance-policy-assignments.update', [$company, $foreignAssignment]), attendancePolicyAssignmentPayload($employee, $policy))
        ->assertNotFound();
    $this->delete(route('companies.attendance-policy-assignments.destroy', [$company, $foreignAssignment]))->assertNotFound();

    expect($foreignAssignment->fresh()->employee_id)->toBe($foreignEmployee->id);
});

test('database composite foreign keys reject cross-company employee or policy combinations', function () {
    [, $company] = attendancePolicyAssignmentUser('company_owner');
    $foreignCompany = Company::factory()->create();
    $localEmployee = attendancePolicyAssignmentEmployee($company);
    $foreignEmployee = attendancePolicyAssignmentEmployee($foreignCompany);
    $localPolicy = attendancePolicyAssignmentPolicy($company);
    $foreignPolicy = attendancePolicyAssignmentPolicy($foreignCompany);

    expect(fn () => attendancePolicyAssignmentRecord($company, $foreignEmployee, $localPolicy))->toThrow(QueryException::class)
        ->and(fn () => attendancePolicyAssignmentRecord($company, $localEmployee, $foreignPolicy))->toThrow(QueryException::class);
});

test('assignment policy roles follow the configured access matrix', function () {
    foreach (['company_owner', 'hr_admin', 'hr_manager', 'manager', 'accountant', 'employee'] as $role) {
        [$user, $company] = attendancePolicyAssignmentUser($role);
        $employee = attendancePolicyAssignmentEmployee($company);
        $policy = attendancePolicyAssignmentPolicy($company);
        $assignment = attendancePolicyAssignmentRecord($company, $employee, $policy, ['effective_to' => '2026-12-31']);
        $this->actingAs($user)->get(route('companies.attendance-policy-assignments.index', $company))
            ->assertStatus(in_array($role, ['accountant', 'employee'], true) ? 403 : 200);

        if (in_array($role, ['company_owner', 'hr_admin', 'hr_manager'], true)) {
            $this->post(route('companies.attendance-policy-assignments.store', $company), attendancePolicyAssignmentPayload($employee, $policy, ['effective_from' => '2027-01-01']))
                ->assertRedirect();
            $this->put(route('companies.attendance-policy-assignments.update', [$company, $assignment]), attendancePolicyAssignmentPayload($employee, $policy, ['notes' => 'Updated notes']))
                ->assertRedirect();
        } else {
            $this->post(route('companies.attendance-policy-assignments.store', $company), attendancePolicyAssignmentPayload($employee, $policy, ['effective_from' => '2027-01-01']))
                ->assertForbidden();
            $this->put(route('companies.attendance-policy-assignments.update', [$company, $assignment]), attendancePolicyAssignmentPayload($employee, $policy))
                ->assertForbidden();
        }

        if (in_array($role, ['company_owner', 'hr_admin'], true)) {
            $this->delete(route('companies.attendance-policy-assignments.destroy', [$company, $assignment]))->assertRedirect();
        } else {
            $this->delete(route('companies.attendance-policy-assignments.destroy', [$company, $assignment]))->assertForbidden();
        }
    }
});

test('assignment listing searches policy and employee and paginates within the company', function () {
    [$hr, $company] = attendancePolicyAssignmentUser('hr_admin');
    $employee = attendancePolicyAssignmentEmployee($company, ['employee_code' => 'EMP-SEARCH']);
    $policy = attendancePolicyAssignmentPolicy($company, ['name' => 'Search Policy', 'code' => 'SEARCH']);
    attendancePolicyAssignmentRecord($company, $employee, $policy);
    $foreignCompany = Company::factory()->create();
    attendancePolicyAssignmentRecord($foreignCompany, attendancePolicyAssignmentEmployee($foreignCompany), attendancePolicyAssignmentPolicy($foreignCompany));

    $this->actingAs($hr)->get(route('companies.attendance-policy-assignments.index', ['company' => $company, 'search' => 'SEARCH']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('attendance-policy-assignments/index', false)
            ->has('assignments.data', 1)
            ->where('assignments.data.0.employee.code', 'EMP-SEARCH')
            ->where('assignments.data.0.policy.code', 'SEARCH'));

    foreach (range(1, 20) as $number) {
        $otherEmployee = attendancePolicyAssignmentEmployee($company);
        attendancePolicyAssignmentRecord($company, $otherEmployee, $policy, ['effective_from' => '2027-01-01']);
    }

    $this->get(route('companies.attendance-policy-assignments.index', $company))
        ->assertInertia(fn (Assert $page) => $page->component('attendance-policy-assignments/index', false)
            ->has('assignments.data', 20)
            ->where('assignments.total', 21));
});

test('super admins may access assignment pages without company membership', function () {
    $company = Company::factory()->create();
    attendancePolicyAssignmentRecord($company, attendancePolicyAssignmentEmployee($company), attendancePolicyAssignmentPolicy($company));
    app(RolePermissionSeeder::class)->run();
    $superAdmin = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId(User::SYSTEM_TEAM_ID);
    $superAdmin->assignRole(Role::query()->where('team_id', User::SYSTEM_TEAM_ID)->where('name', 'super_admin')->firstOrFail());
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $this->actingAs($superAdmin)->get(route('companies.attendance-policy-assignments.index', $company))->assertOk();
});

test('assignment relationships return matching tenant records and permission seeding is repeatable', function () {
    [, $company] = attendancePolicyAssignmentUser('hr_admin');
    $employee = attendancePolicyAssignmentEmployee($company);
    $policy = attendancePolicyAssignmentPolicy($company);
    $assignment = attendancePolicyAssignmentRecord($company, $employee, $policy);
    app(SeedCompanyRoles::class)->handle($company);
    app(SeedCompanyRoles::class)->handle($company);
    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);

    expect($assignment->employee->is($employee))->toBeTrue()
        ->and($assignment->attendancePolicy->is($policy))->toBeTrue()
        ->and($assignment->company->is($company))->toBeTrue()
        ->and($employee->attendancePolicyAssignments()->count())->toBe(1)
        ->and($company->employeeAttendancePolicyAssignments()->count())->toBe(1)
        ->and(Role::query()->where('team_id', $company->id)->where('name', 'manager')->firstOrFail()->hasPermissionTo('attendance_policy_assignment.view'))->toBeTrue()
        ->and(Role::query()->where('team_id', $company->id)->where('name', 'manager')->firstOrFail()->hasPermissionTo('attendance_policy_assignment.create'))->toBeFalse();

    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});
