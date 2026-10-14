<?php

use App\Actions\SeedCompanyRoles;
use App\Enums\EmployeeLeavePolicyAssignmentStatus;
use App\Enums\LeavePolicyStatus;
use App\Enums\LeaveTypeStatus;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\EmployeeLeavePolicyAssignment;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

/** @return array{User, Company} */
function leavePolicyAssignmentActor(string $role): array
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

function leavePolicyAssignmentEmployee(Company $company, array $attributes = []): Employee
{
    return Employee::factory()->create(array_merge(['company_id' => $company->id], $attributes));
}

function leavePolicyAssignmentType(Company $company, array $attributes = []): LeaveType
{
    return LeaveType::factory()->create(array_merge(['company_id' => $company->id], $attributes));
}

function leavePolicyAssignmentPolicy(Company $company, LeaveType $type, array $attributes = []): LeavePolicy
{
    return LeavePolicy::factory()->create(array_merge(['company_id' => $company->id, 'leave_type_id' => $type->id], $attributes));
}

function leavePolicyAssignmentRecord(Company $company, Employee $employee, LeavePolicy $policy, array $attributes = []): EmployeeLeavePolicyAssignment
{
    return $company->employeeLeavePolicyAssignments()->create(array_merge([
        'employee_id' => $employee->id,
        'leave_type_id' => $policy->leave_type_id,
        'leave_policy_id' => $policy->id,
        'effective_from' => '2026-01-01',
        'effective_to' => null,
        'status' => 'active',
    ], $attributes));
}

/** @return array<string, mixed> */
function leavePolicyAssignmentPayload(Employee $employee, LeavePolicy $policy, array $overrides = []): array
{
    return array_merge([
        'employee_id' => $employee->id,
        'leave_policy_id' => $policy->id,
        'effective_from' => '2026-01-01',
        'effective_to' => null,
        'status' => 'active',
        'notes' => 'Initial leave policy assignment',
    ], $overrides);
}

function leavePolicyAssignmentUrl(Company $company): string
{
    return route('companies.leave-policy-assignments.index', $company);
}

test('authorized HR assigns policy with derived type tenant and audit data', function () {
    [$hr, $company] = leavePolicyAssignmentActor('hr_admin');
    $employee = leavePolicyAssignmentEmployee($company);
    $type = leavePolicyAssignmentType($company);
    $policy = leavePolicyAssignmentPolicy($company, $type);

    $this->actingAs($hr)->post(leavePolicyAssignmentUrl($company), leavePolicyAssignmentPayload($employee, $policy, [
        'leave_type_id' => $type->id,
        'company_id' => $company->id,
    ]))->assertSessionHasErrors(['leave_type_id', 'company_id']);

    $this->post(leavePolicyAssignmentUrl($company), leavePolicyAssignmentPayload($employee, $policy))
        ->assertRedirect(leavePolicyAssignmentUrl($company));

    $assignment = $company->employeeLeavePolicyAssignments()->firstOrFail();
    expect($assignment->company_id)->toBe($company->id)
        ->and($assignment->employee_id)->toBe($employee->id)
        ->and($assignment->leave_type_id)->toBe($type->id)
        ->and($assignment->leave_policy_id)->toBe($policy->id)
        ->and($assignment->created_by)->toBe($hr->id)
        ->and($assignment->updated_by)->toBe($hr->id);
});

test('different leave types may overlap while same-type policies may transition adjacently', function () {
    [$hr, $company] = leavePolicyAssignmentActor('hr_admin');
    $employee = leavePolicyAssignmentEmployee($company);
    $annual = leavePolicyAssignmentType($company, ['name' => 'Annual', 'code' => 'ANNUAL']);
    $sick = leavePolicyAssignmentType($company, ['name' => 'Sick', 'code' => 'SICK']);
    $annualA = leavePolicyAssignmentPolicy($company, $annual, ['name' => 'Annual A', 'code' => 'ANNUAL-A']);
    $annualB = leavePolicyAssignmentPolicy($company, $annual, ['name' => 'Annual B', 'code' => 'ANNUAL-B']);
    $sickPolicy = leavePolicyAssignmentPolicy($company, $sick, ['name' => 'Sick policy', 'code' => 'SICK-1']);
    $this->actingAs($hr);

    $this->post(leavePolicyAssignmentUrl($company), leavePolicyAssignmentPayload($employee, $annualA, ['effective_to' => '2026-06-30']))->assertRedirect();
    $this->post(leavePolicyAssignmentUrl($company), leavePolicyAssignmentPayload($employee, $sickPolicy, ['effective_to' => '2026-12-31']))->assertRedirect();
    $this->post(leavePolicyAssignmentUrl($company), leavePolicyAssignmentPayload($employee, $annualB, ['effective_from' => '2026-07-01']))->assertRedirect();
    $this->post(leavePolicyAssignmentUrl($company), leavePolicyAssignmentPayload($employee, $annualB, ['effective_from' => '2026-06-30']))->assertSessionHasErrors('effective_from');

    expect($company->employeeLeavePolicyAssignments()->count())->toBe(3);
});

test('open-ended periods block same-type overlap but inactive and deleted history does not', function () {
    [$hr, $company] = leavePolicyAssignmentActor('hr_admin');
    $employee = leavePolicyAssignmentEmployee($company);
    $type = leavePolicyAssignmentType($company);
    $policyA = leavePolicyAssignmentPolicy($company, $type, ['name' => 'Policy A', 'code' => 'A']);
    $policyB = leavePolicyAssignmentPolicy($company, $type, ['name' => 'Policy B', 'code' => 'B']);
    $this->actingAs($hr)->post(leavePolicyAssignmentUrl($company), leavePolicyAssignmentPayload($employee, $policyA))->assertRedirect();
    $this->post(leavePolicyAssignmentUrl($company), leavePolicyAssignmentPayload($employee, $policyB, ['effective_from' => '2027-01-01']))->assertSessionHasErrors('effective_from');

    $open = $company->employeeLeavePolicyAssignments()->firstOrFail();
    $open->update(['status' => EmployeeLeavePolicyAssignmentStatus::Inactive]);
    $this->post(leavePolicyAssignmentUrl($company), leavePolicyAssignmentPayload($employee, $policyB, [
        'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31',
    ]))->assertRedirect();
    $company->employeeLeavePolicyAssignments()->whereKey($open->id)->delete();
    $this->post(leavePolicyAssignmentUrl($company), leavePolicyAssignmentPayload($employee, $policyA, ['effective_from' => '2027-01-01']))->assertRedirect();

    expect($company->employeeLeavePolicyAssignments()->count())->toBe(2);
});

test('inactive policies or types and archived employees cannot receive active assignments', function () {
    [$hr, $company] = leavePolicyAssignmentActor('hr_admin');
    $employee = leavePolicyAssignmentEmployee($company);
    $activeType = leavePolicyAssignmentType($company);
    $inactiveType = leavePolicyAssignmentType($company, ['name' => 'Inactive Type', 'code' => 'INACTIVE-TYPE', 'status' => LeaveTypeStatus::Inactive]);
    $inactivePolicy = leavePolicyAssignmentPolicy($company, $activeType, ['name' => 'Inactive Policy', 'code' => 'INACTIVE-POL', 'status' => LeavePolicyStatus::Inactive]);
    $inactiveTypePolicy = leavePolicyAssignmentPolicy($company, $inactiveType, ['name' => 'Inactive Type Policy', 'code' => 'INACTIVE-TYPE-POL']);
    $this->actingAs($hr);

    $this->post(leavePolicyAssignmentUrl($company), leavePolicyAssignmentPayload($employee, $inactivePolicy))->assertSessionHasErrors('leave_policy_id');
    $this->post(leavePolicyAssignmentUrl($company), leavePolicyAssignmentPayload($employee, $inactiveTypePolicy))->assertSessionHasErrors('leave_policy_id');
    $inactivePolicy->delete();
    $this->post(leavePolicyAssignmentUrl($company), leavePolicyAssignmentPayload($employee, $inactivePolicy))->assertSessionHasErrors('leave_policy_id');
    $employee->delete();
    $validPolicy = leavePolicyAssignmentPolicy($company, $activeType, ['name' => 'Valid policy', 'code' => 'VALID']);
    $this->post(leavePolicyAssignmentUrl($company), leavePolicyAssignmentPayload($employee, $validPolicy))->assertSessionHasErrors('employee_id');
});

test('manager only sees assignments for their direct reports and cannot create assignments', function () {
    [$manager, $company] = leavePolicyAssignmentActor('manager');
    $managerEmployee = leavePolicyAssignmentEmployee($company, ['user_id' => $manager->id]);
    $directReport = leavePolicyAssignmentEmployee($company, ['manager_id' => $managerEmployee->id]);
    $unrelated = leavePolicyAssignmentEmployee($company);
    $type = leavePolicyAssignmentType($company);
    $policy = leavePolicyAssignmentPolicy($company, $type);
    $visible = leavePolicyAssignmentRecord($company, $directReport, $policy);
    leavePolicyAssignmentRecord($company, $unrelated, $policy, ['effective_from' => '2027-01-01']);

    $this->actingAs($manager)->get(leavePolicyAssignmentUrl($company))
        ->assertInertia(fn (Assert $page) => $page->has('assignments.data', 1)->where('assignments.data.0.id', $visible->id));
    $this->post(leavePolicyAssignmentUrl($company), leavePolicyAssignmentPayload($directReport, $policy))->assertForbidden();
});

test('company isolation is enforced for foreign employees policies and assignments', function () {
    [$hr, $company] = leavePolicyAssignmentActor('hr_admin');
    $ownEmployee = leavePolicyAssignmentEmployee($company);
    $ownType = leavePolicyAssignmentType($company);
    $ownPolicy = leavePolicyAssignmentPolicy($company, $ownType);
    $otherCompany = Company::factory()->create();
    $foreignEmployee = leavePolicyAssignmentEmployee($otherCompany);
    $foreignType = leavePolicyAssignmentType($otherCompany);
    $foreignPolicy = leavePolicyAssignmentPolicy($otherCompany, $foreignType);
    $foreignAssignment = leavePolicyAssignmentRecord($otherCompany, $foreignEmployee, $foreignPolicy);

    $this->actingAs($hr)->get(leavePolicyAssignmentUrl($otherCompany))->assertNotFound();
    $this->post(leavePolicyAssignmentUrl($company), leavePolicyAssignmentPayload($foreignEmployee, $ownPolicy))->assertSessionHasErrors('employee_id');
    $this->post(leavePolicyAssignmentUrl($company), leavePolicyAssignmentPayload($ownEmployee, $foreignPolicy))->assertSessionHasErrors('leave_policy_id');
    $this->put(route('companies.leave-policy-assignments.update', [$company, $foreignAssignment]), leavePolicyAssignmentPayload($ownEmployee, $ownPolicy))->assertNotFound();
    $this->delete(route('companies.leave-policy-assignments.destroy', [$company, $foreignAssignment]))->assertNotFound();
});

test('lookup helpers return date-specific and company-timezone current assignments by leave type', function () {
    [, $company] = leavePolicyAssignmentActor('hr_admin');
    $company->update(['timezone' => 'Asia/Kolkata']);
    $employee = leavePolicyAssignmentEmployee($company);
    $annual = leavePolicyAssignmentType($company, ['name' => 'Annual', 'code' => 'ANNUAL']);
    $sick = leavePolicyAssignmentType($company, ['name' => 'Sick', 'code' => 'SICK']);
    $oldPolicy = leavePolicyAssignmentPolicy($company, $annual, ['name' => 'Old Annual', 'code' => 'OLD']);
    $newPolicy = leavePolicyAssignmentPolicy($company, $annual, ['name' => 'New Annual', 'code' => 'NEW']);
    $sickPolicy = leavePolicyAssignmentPolicy($company, $sick, ['name' => 'Sick Standard', 'code' => 'SICK-STD']);
    leavePolicyAssignmentRecord($company, $employee, $oldPolicy, ['effective_from' => '2026-01-01', 'effective_to' => '2026-06-30']);
    $current = leavePolicyAssignmentRecord($company, $employee, $newPolicy, ['effective_from' => '2026-10-05', 'effective_to' => '2026-12-31']);
    leavePolicyAssignmentRecord($company, $employee, $sickPolicy, ['effective_from' => '2026-01-01']);
    config(['app.timezone' => 'UTC']);
    Carbon::setTestNow('2026-10-04 20:00:00 UTC');

    expect($employee->leavePolicyAssignmentForDate($annual, '2026-06-30')?->leave_policy_id)->toBe($oldPolicy->id)
        ->and($employee->leavePolicyAssignmentForDate($annual, '2026-07-01'))->toBeNull()
        ->and($employee->leavePolicyAssignmentForDate($annual, '2026-10-05')?->id)->toBe($current->id)
        ->and($employee->leavePolicyAssignmentForDate($annual, '2026-12-31')?->id)->toBe($current->id)
        ->and($employee->leavePolicyAssignmentForDate($annual, '2027-01-01'))->toBeNull()
        ->and($employee->currentLeavePolicyAssignment($annual)?->id)->toBe($current->id)
        ->and($employee->currentLeavePolicyAssignments())->toHaveCount(2);

    Carbon::setTestNow();
});

test('deleting an assignment soft deletes history and preserves its related records', function () {
    [$owner, $company] = leavePolicyAssignmentActor('company_owner');
    $employee = leavePolicyAssignmentEmployee($company);
    $type = leavePolicyAssignmentType($company);
    $policy = leavePolicyAssignmentPolicy($company, $type);
    $assignment = leavePolicyAssignmentRecord($company, $employee, $policy);

    $this->actingAs($owner)->delete(route('companies.leave-policy-assignments.destroy', [$company, $assignment]))
        ->assertRedirect(leavePolicyAssignmentUrl($company));

    expect(EmployeeLeavePolicyAssignment::withTrashed()->findOrFail($assignment->id)->trashed())->toBeTrue()
        ->and($employee->fresh())->not->toBeNull()
        ->and($policy->fresh())->not->toBeNull()
        ->and($type->fresh())->not->toBeNull()
        ->and($employee->currentLeavePolicyAssignment($type))->toBeNull();
});

test('unauthorized employees and accountants cannot view assignment management', function (string $role) {
    [$user, $company] = leavePolicyAssignmentActor($role);

    $this->actingAs($user)->get(leavePolicyAssignmentUrl($company))->assertForbidden();
})->with(['employee', 'accountant']);

test('updates exclude themselves but cannot overlap another active policy of the same type', function () {
    [$hr, $company] = leavePolicyAssignmentActor('hr_admin');
    $employee = leavePolicyAssignmentEmployee($company);
    $type = leavePolicyAssignmentType($company);
    $firstPolicy = leavePolicyAssignmentPolicy($company, $type, ['name' => 'First', 'code' => 'FIRST']);
    $secondPolicy = leavePolicyAssignmentPolicy($company, $type, ['name' => 'Second', 'code' => 'SECOND']);
    $first = leavePolicyAssignmentRecord($company, $employee, $firstPolicy, ['effective_from' => '2026-01-01', 'effective_to' => '2026-06-30']);
    $second = leavePolicyAssignmentRecord($company, $employee, $secondPolicy, ['effective_from' => '2026-07-01', 'effective_to' => '2026-12-31']);

    $this->actingAs($hr)->put(route('companies.leave-policy-assignments.update', [$company, $second]), leavePolicyAssignmentPayload($employee, $secondPolicy, [
        'effective_from' => '2026-06-30', 'effective_to' => '2026-12-31',
    ]))->assertSessionHasErrors('effective_from');
    $this->put(route('companies.leave-policy-assignments.update', [$company, $second]), leavePolicyAssignmentPayload($employee, $secondPolicy, [
        'effective_from' => '2026-07-01', 'effective_to' => '2026-12-31', 'notes' => 'Updated history note',
    ]))->assertRedirect();

    expect($first->fresh()->effective_to->toDateString())->toBe('2026-06-30')
        ->and($second->fresh()->notes)->toBe('Updated history note')
        ->and($second->fresh()->updated_by)->toBe($hr->id);
});

test('assignment status changes recheck overlaps while later policy deactivation preserves history', function () {
    [$hr, $company] = leavePolicyAssignmentActor('hr_admin');
    $employee = leavePolicyAssignmentEmployee($company);
    $type = leavePolicyAssignmentType($company);
    $firstPolicy = leavePolicyAssignmentPolicy($company, $type, ['name' => 'Policy one', 'code' => 'ONE']);
    $secondPolicy = leavePolicyAssignmentPolicy($company, $type, ['name' => 'Policy two', 'code' => 'TWO']);
    $first = leavePolicyAssignmentRecord($company, $employee, $firstPolicy, ['effective_from' => '2026-01-01', 'effective_to' => '2026-12-31']);
    $second = leavePolicyAssignmentRecord($company, $employee, $secondPolicy, ['effective_from' => '2026-01-01', 'effective_to' => '2026-12-31', 'status' => 'inactive']);
    $this->actingAs($hr);

    $this->put(route('companies.leave-policy-assignments.update', [$company, $first]), leavePolicyAssignmentPayload($employee, $firstPolicy, ['status' => 'inactive']))->assertRedirect();
    $this->put(route('companies.leave-policy-assignments.update', [$company, $second]), leavePolicyAssignmentPayload($employee, $secondPolicy))->assertRedirect();
    $this->put(route('companies.leave-policy-assignments.update', [$company, $first]), leavePolicyAssignmentPayload($employee, $firstPolicy))->assertSessionHasErrors('effective_from');

    $first->refresh()->update(['status' => 'active']);
    $firstPolicy->update(['status' => LeavePolicyStatus::Inactive]);
    $type->update(['status' => LeaveTypeStatus::Inactive]);
    expect($employee->leavePolicyAssignmentForDate($type, '2026-06-01')?->leave_policy_id)->toBe($firstPolicy->id);
});

test('database rejects a leave type that does not match the selected policy', function () {
    [, $company] = leavePolicyAssignmentActor('company_owner');
    $employee = leavePolicyAssignmentEmployee($company);
    $typeA = leavePolicyAssignmentType($company, ['name' => 'Type A', 'code' => 'TYPE-A']);
    $typeB = leavePolicyAssignmentType($company, ['name' => 'Type B', 'code' => 'TYPE-B']);
    $policy = leavePolicyAssignmentPolicy($company, $typeA);

    expect(fn () => $company->employeeLeavePolicyAssignments()->create([
        'employee_id' => $employee->id,
        'leave_type_id' => $typeB->id,
        'leave_policy_id' => $policy->id,
        'effective_from' => '2026-01-01',
        'status' => 'active',
    ]))->toThrow(QueryException::class);
});

test('assignment listing searches filters and paginates within the selected company', function () {
    [$hr, $company] = leavePolicyAssignmentActor('hr_admin');
    $type = leavePolicyAssignmentType($company, ['name' => 'Annual Leave', 'code' => 'ANNUAL']);
    $policy = leavePolicyAssignmentPolicy($company, $type, ['name' => 'Annual Standard', 'code' => 'ANNUAL-STD']);
    $employees = Employee::factory()->count(21)->create(['company_id' => $company->id]);
    foreach ($employees as $employee) {
        leavePolicyAssignmentRecord($company, $employee, $policy);
    }
    $otherCompany = Company::factory()->create();
    $otherType = leavePolicyAssignmentType($otherCompany);
    $otherPolicy = leavePolicyAssignmentPolicy($otherCompany, $otherType);
    leavePolicyAssignmentRecord($otherCompany, leavePolicyAssignmentEmployee($otherCompany), $otherPolicy);

    $this->actingAs($hr)->get(leavePolicyAssignmentUrl($company))
        ->assertInertia(fn (Assert $page) => $page->where('assignments.total', 21)->has('assignments.data', 20));
    $this->get(leavePolicyAssignmentUrl($company).'?search='.$employees[0]->employee_code)
        ->assertInertia(fn (Assert $page) => $page->where('assignments.total', 1)->where('assignments.data.0.employee.code', $employees[0]->employee_code));
    $this->get(leavePolicyAssignmentUrl($company).'?leave_type_id='.$type->id.'&status=active&period=current')
        ->assertInertia(fn (Assert $page) => $page->where('assignments.total', 21));
});

test('factory creates company-consistent employee policy and leave type records', function () {
    $assignment = EmployeeLeavePolicyAssignment::factory()->create();

    expect($assignment->employee->company_id)->toBe($assignment->company_id)
        ->and($assignment->leaveType->company_id)->toBe($assignment->company_id)
        ->and($assignment->leavePolicy->company_id)->toBe($assignment->company_id)
        ->and($assignment->leavePolicy->leave_type_id)->toBe($assignment->leave_type_id);
});

test('HR managers may create and update assignments while owners have full assignment access', function () {
    [$hrManager, $company] = leavePolicyAssignmentActor('hr_manager');
    $employee = leavePolicyAssignmentEmployee($company);
    $type = leavePolicyAssignmentType($company);
    $policy = leavePolicyAssignmentPolicy($company, $type);

    $this->actingAs($hrManager)->post(leavePolicyAssignmentUrl($company), leavePolicyAssignmentPayload($employee, $policy))->assertRedirect();
    $assignment = $company->employeeLeavePolicyAssignments()->firstOrFail();
    $this->put(route('companies.leave-policy-assignments.update', [$company, $assignment]), leavePolicyAssignmentPayload($employee, $policy, ['notes' => 'HR manager update']))->assertRedirect();

    [$owner, $ownerCompany] = leavePolicyAssignmentActor('company_owner');
    $ownerEmployee = leavePolicyAssignmentEmployee($ownerCompany);
    $ownerType = leavePolicyAssignmentType($ownerCompany);
    $ownerPolicy = leavePolicyAssignmentPolicy($ownerCompany, $ownerType);
    $ownerAssignment = leavePolicyAssignmentRecord($ownerCompany, $ownerEmployee, $ownerPolicy);
    $this->actingAs($owner)->delete(route('companies.leave-policy-assignments.destroy', [$ownerCompany, $ownerAssignment]))->assertRedirect();
    expect(EmployeeLeavePolicyAssignment::query()->find($ownerAssignment->id))->toBeNull()
        ->and(EmployeeLeavePolicyAssignment::withTrashed()->findOrFail($ownerAssignment->id)->trashed())->toBeTrue();
});
