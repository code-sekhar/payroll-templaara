<?php

use App\Actions\SeedCompanyRoles;
use App\Enums\LeavePolicyStatus;
use App\Enums\LeaveTypeCategory;
use App\Enums\LeaveTypeStatus;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

/** @return array{User, Company} */
function leaveTypeActor(string $role): array
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

/** @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function leaveTypeInput(array $overrides = []): array
{
    return array_merge([
        'name' => 'Annual Leave', 'code' => 'AL', 'description' => 'Annual paid leave', 'category' => 'paid',
        'is_paid' => true, 'allows_half_day' => true, 'status' => 'active', 'sort_order' => 10,
    ], $overrides);
}

/** @param array<string, mixed> $overrides */
function leaveTypeRow(Company $company, array $overrides = []): LeaveType
{
    return $company->leaveTypes()->create(array_merge(leaveTypeInput(), $overrides));
}

function leaveTypeUrl(Company $company): string
{
    return route('companies.leave-types.index', $company);
}

test('authorized HR users can create and update custom leave types with audit attribution', function () {
    foreach (['company_owner', 'hr_admin', 'hr_manager'] as $role) {
        [$user, $company] = leaveTypeActor($role);
        $this->actingAs($user)->post(leaveTypeUrl($company), leaveTypeInput(['name' => 'Personal Leave', 'code' => 'pl']))
            ->assertRedirect(leaveTypeUrl($company));

        $leaveType = $company->leaveTypes()->firstOrFail();
        expect($leaveType->name)->toBe('Personal Leave')
            ->and($leaveType->code)->toBe('PL')
            ->and($leaveType->created_by)->toBe($user->id)
            ->and($leaveType->updated_by)->toBe($user->id)
            ->and($leaveType->category)->toBe(LeaveTypeCategory::Paid)
            ->and($leaveType->is_paid)->toBeTrue()
            ->and($leaveType->allows_half_day)->toBeTrue();

        $this->put(route('companies.leave-types.update', [$company, $leaveType]), leaveTypeInput(['name' => 'Personal Leave Updated', 'code' => 'pl2']))
            ->assertRedirect(leaveTypeUrl($company));
        expect($leaveType->fresh()->name)->toBe('Personal Leave Updated')
            ->and($leaveType->fresh()->code)->toBe('PL2')
            ->and($leaveType->fresh()->updated_by)->toBe($user->id);
    }
});

test('paid metadata is explicit and independent from the leave type category', function () {
    [$owner, $company] = leaveTypeActor('company_owner');

    $this->actingAs($owner)->post(leaveTypeUrl($company), leaveTypeInput([
        'name' => 'Company Sponsored Special Leave',
        'code' => 'CSS',
        'category' => 'special',
        'is_paid' => true,
    ]))->assertRedirect(leaveTypeUrl($company));

    $leaveType = $company->leaveTypes()->firstOrFail();
    expect($leaveType->category)->toBe(LeaveTypeCategory::Special)
        ->and($leaveType->is_paid)->toBeTrue();
});

test('leave type names and codes are company-scoped and reusable after soft deletion', function () {
    [$owner, $company] = leaveTypeActor('company_owner');
    $otherCompany = Company::factory()->create();
    $type = leaveTypeRow($company);
    $this->actingAs($owner)->post(leaveTypeUrl($company), leaveTypeInput())->assertSessionHasErrors(['name', 'code']);

    $otherType = leaveTypeRow($otherCompany);
    expect($otherType->code)->toBe('AL');

    $type->delete();
    $this->post(leaveTypeUrl($company), leaveTypeInput())->assertRedirect(leaveTypeUrl($company));
    expect(LeaveType::withTrashed()->where('company_id', $company->id)->where('code', 'AL')->count())->toBe(2);
});

test('cross-company leave types are hidden and tenant or audit fields cannot be forged', function () {
    [$owner, $company] = leaveTypeActor('company_owner');
    $otherCompany = Company::factory()->create();
    $foreignType = leaveTypeRow($otherCompany);
    $this->actingAs($owner);

    $this->get(leaveTypeUrl($otherCompany))->assertNotFound();
    $this->put(route('companies.leave-types.update', [$company, $foreignType]), leaveTypeInput(['name' => 'Stolen']))->assertNotFound();
    $this->delete(route('companies.leave-types.destroy', [$company, $foreignType]))->assertNotFound();
    $this->post(leaveTypeUrl($company), leaveTypeInput(['company_id' => $otherCompany->id, 'created_by' => 42, 'updated_by' => 42]))
        ->assertSessionHasErrors(['company_id', 'created_by', 'updated_by']);

    expect($company->leaveTypes()->exists())->toBeFalse()
        ->and($foreignType->fresh()->name)->toBe('Annual Leave');
});

test('leave type enum flags and sort order are validated by the server', function () {
    [$owner, $company] = leaveTypeActor('company_owner');
    $this->actingAs($owner);
    $url = route('companies.leave-types.store', $company);

    $this->post($url, leaveTypeInput(['category' => 'vacation']))->assertSessionHasErrors('category');
    $this->post($url, leaveTypeInput(['is_paid' => 'maybe']))->assertSessionHasErrors('is_paid');
    $this->post($url, leaveTypeInput(['allows_half_day' => 'maybe']))->assertSessionHasErrors('allows_half_day');
    $this->post($url, leaveTypeInput(['sort_order' => -1]))->assertSessionHasErrors('sort_order');
    $this->post($url, leaveTypeInput(['code' => 'leave type']))->assertSessionHasErrors('code');

    expect($company->leaveTypes()->count())->toBe(0);
});

test('leave type activation and deactivation require the matching permission', function () {
    [$owner, $company] = leaveTypeActor('company_owner');
    $type = leaveTypeRow($company, ['status' => LeaveTypeStatus::Inactive]);
    $this->actingAs($owner)->patch(route('companies.leave-types.activate', [$company, $type]))->assertRedirect();
    expect($type->fresh()->status)->toBe(LeaveTypeStatus::Active);
    $this->patch(route('companies.leave-types.deactivate', [$company, $type]))->assertRedirect();
    expect($type->fresh()->status)->toBe(LeaveTypeStatus::Inactive);

    [$manager, $managerCompany] = leaveTypeActor('manager');
    $managerType = leaveTypeRow($managerCompany);
    $this->actingAs($manager)->patch(route('companies.leave-types.deactivate', [$managerCompany, $managerType]))->assertForbidden();
    expect($managerType->fresh()->status)->toBe(LeaveTypeStatus::Active);
});

test('non-deleted policies prevent archiving their leave type until the policy is archived', function () {
    [$owner, $company] = leaveTypeActor('company_owner');
    $type = leaveTypeRow($company);
    LeavePolicy::factory()->for($type, 'leaveType')->create(['company_id' => $company->id]);
    $this->actingAs($owner)->delete(route('companies.leave-types.destroy', [$company, $type]))->assertSessionHasErrors('leave_type');
    expect($type->fresh()->trashed())->toBeFalse();

    $type->policies()->update(['status' => LeavePolicyStatus::Inactive]);
    $this->delete(route('companies.leave-types.destroy', [$company, $type]))->assertSessionHasErrors('leave_type');
    $type->policies()->firstOrFail()->delete();
    $this->delete(route('companies.leave-types.destroy', [$company, $type]))->assertRedirect();
    expect(LeaveType::query()->find($type->id))->toBeNull()
        ->and(LeaveType::withTrashed()->findOrFail($type->id)->trashed())->toBeTrue()
        ->and(LeaveType::withTrashed()->findOrFail($type->id)->updated_by)->toBe($owner->id)
        ->and($type->policies()->withTrashed()->count())->toBe(1)
        ->and($type->policies()->withTrashed()->firstOrFail()->trashed())->toBeTrue();
});

test('an active policy prevents removing half-day capability from its leave type', function () {
    [$owner, $company] = leaveTypeActor('company_owner');
    $type = leaveTypeRow($company);
    LeavePolicy::factory()->for($type, 'leaveType')->create(['company_id' => $company->id, 'allows_half_day' => true]);

    $this->actingAs($owner)->put(route('companies.leave-types.update', [$company, $type]), leaveTypeInput(['allows_half_day' => false]))
        ->assertSessionHasErrors('allows_half_day');
    expect($type->fresh()->allows_half_day)->toBeTrue();
});

test('leave type listing filters by search category and status and paginates within the company', function () {
    [$hr, $company] = leaveTypeActor('hr_admin');
    foreach (range(1, 22) as $number) {
        leaveTypeRow($company, ['name' => "Leave {$number}", 'code' => "LV-{$number}", 'category' => LeaveTypeCategory::Paid]);
    }
    leaveTypeRow($company, ['name' => 'Inactive Special', 'code' => 'SPECIAL', 'category' => LeaveTypeCategory::Special, 'status' => LeaveTypeStatus::Inactive]);
    $otherCompany = Company::factory()->create();
    leaveTypeRow($otherCompany, ['name' => 'Foreign Leave', 'code' => 'FOREIGN']);

    $this->actingAs($hr)->get(leaveTypeUrl($company))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('leave-types/index', false)->has('leaveTypes.data', 20)->where('leaveTypes.total', 23));
    $this->get(route('companies.leave-types.index', ['company' => $company, 'search' => 'Special', 'category' => 'special', 'status' => 'inactive']))
        ->assertInertia(fn (Assert $page) => $page->has('leaveTypes.data', 1)->where('leaveTypes.data.0.code', 'SPECIAL'));
});

test('leave type access follows the company role matrix', function () {
    foreach (['company_owner', 'hr_admin', 'hr_manager', 'manager', 'accountant', 'employee'] as $role) {
        [$user, $company] = leaveTypeActor($role);
        $response = $this->actingAs($user)->get(leaveTypeUrl($company));
        $response->assertStatus(in_array($role, ['accountant', 'employee'], true) ? 403 : 200);

        if ($role === 'manager') {
            $this->post(leaveTypeUrl($company), leaveTypeInput())->assertForbidden();
        }
        if (in_array($role, ['accountant', 'employee'], true)) {
            $this->post(leaveTypeUrl($company), leaveTypeInput())->assertForbidden();
        }
    }
});
