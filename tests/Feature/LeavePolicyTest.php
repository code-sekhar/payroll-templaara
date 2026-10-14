<?php

use App\Actions\SeedCompanyRoles;
use App\Enums\LeaveAccrualFrequency;
use App\Enums\LeaveAccrualMethod;
use App\Enums\LeavePolicyStatus;
use App\Enums\LeaveTypeCategory;
use App\Enums\LeaveTypeStatus;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

/** @return array{User, Company} */
function leavePolicyActor(string $role): array
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
function leavePolicyInput(int $leaveTypeId, array $overrides = []): array
{
    return array_merge([
        'leave_type_id' => $leaveTypeId, 'name' => 'Standard Annual Policy', 'code' => 'STD-AL',
        'description' => 'Annual leave rules', 'status' => 'active', 'entitlement_days' => '20.00',
        'accrual_method' => 'accrual', 'accrual_frequency' => 'monthly', 'carry_forward_enabled' => true,
        'max_carry_forward_days' => '5.00', 'max_balance_days' => '25.00', 'allow_negative_balance' => false,
        'max_negative_balance_days' => null, 'minimum_request_days' => '0.50', 'maximum_request_days' => '10.00',
        'minimum_notice_days' => 2, 'allows_half_day' => true, 'requires_reason' => true,
    ], $overrides);
}

function leavePolicyUrl(Company $company): string
{
    return route('companies.leave-policies.index', $company);
}

function leavePolicyType(Company $company, array $overrides = []): LeaveType
{
    return $company->leaveTypes()->create(array_merge([
        'name' => 'Annual Leave', 'code' => 'AL', 'category' => LeaveTypeCategory::Paid,
        'is_paid' => true, 'allows_half_day' => true,
    ], $overrides));
}

function leavePolicyRow(Company $company, LeaveType $type, array $overrides = []): LeavePolicy
{
    return $company->leavePolicies()->create(array_merge(leavePolicyInput($type->id), $overrides));
}

test('authorized HR users can create reusable policies with exact decimal days and audit actors', function () {
    foreach (['company_owner', 'hr_admin', 'hr_manager'] as $role) {
        [$user, $company] = leavePolicyActor($role);
        $type = leavePolicyType($company);
        $this->actingAs($user)->post(leavePolicyUrl($company), leavePolicyInput($type->id, ['code' => 'al-policy']))
            ->assertRedirect(leavePolicyUrl($company));

        $policy = $company->leavePolicies()->firstOrFail();
        expect($policy->code)->toBe('AL-POLICY')
            ->and($policy->entitlement_days)->toBe('20.00')
            ->and($policy->minimum_request_days)->toBe('0.50')
            ->and($policy->max_carry_forward_days)->toBe('5.00')
            ->and($policy->accrual_method)->toBe(LeaveAccrualMethod::Accrual)
            ->and($policy->accrual_frequency)->toBe(LeaveAccrualFrequency::Monthly)
            ->and($policy->leave_type_id)->toBe($type->id)
            ->and($policy->company_id)->toBe($company->id)
            ->and($policy->created_by)->toBe($user->id)
            ->and($policy->updated_by)->toBe($user->id)
            ->and($policy->allows_half_day)->toBeTrue()
            ->and($policy->requires_reason)->toBeTrue();
    }
});

test('a new none-accrual policy receives neutral defaults', function () {
    [$owner, $company] = leavePolicyActor('company_owner');
    $type = leavePolicyType($company);

    $this->actingAs($owner)->post(leavePolicyUrl($company), [
        'leave_type_id' => $type->id,
        'name' => 'Unpaid Leave Rules',
        'code' => 'UL-RULES',
        'entitlement_days' => '0.00',
        'minimum_request_days' => '1.00',
    ])->assertRedirect(leavePolicyUrl($company));

    $policy = $company->leavePolicies()->firstOrFail();
    expect($policy->status)->toBe(LeavePolicyStatus::Active)
        ->and($policy->entitlement_days)->toBe('0.00')
        ->and($policy->accrual_method)->toBe(LeaveAccrualMethod::None)
        ->and($policy->accrual_frequency)->toBe(LeaveAccrualFrequency::None)
        ->and($policy->carry_forward_enabled)->toBeFalse()
        ->and($policy->max_carry_forward_days)->toBeNull()
        ->and($policy->allow_negative_balance)->toBeFalse()
        ->and($policy->max_negative_balance_days)->toBeNull()
        ->and($policy->minimum_notice_days)->toBe(0)
        ->and($policy->allows_half_day)->toBeFalse()
        ->and($policy->requires_reason)->toBeFalse();
});

test('policy updates preserve company ownership and record the updater', function () {
    [$owner, $company] = leavePolicyActor('company_owner');
    $otherCompany = Company::factory()->create();
    $type = leavePolicyType($company);
    $otherType = leavePolicyType($otherCompany, ['name' => 'Foreign Leave', 'code' => 'FL']);
    $policy = leavePolicyRow($company, $type);

    $this->actingAs($owner)->put(route('companies.leave-policies.update', [$company, $policy]), leavePolicyInput($type->id, [
        'name' => 'Updated Policy', 'company_id' => $otherCompany->id, 'created_by' => 999, 'updated_by' => 999,
    ]))->assertSessionHasErrors(['company_id', 'created_by', 'updated_by']);
    $this->put(route('companies.leave-policies.update', [$company, $policy]), leavePolicyInput($type->id, ['name' => 'Updated Policy']))
        ->assertRedirect(leavePolicyUrl($company));

    expect($policy->fresh()->company_id)->toBe($company->id)
        ->and($policy->fresh()->name)->toBe('Updated Policy')
        ->and($policy->fresh()->updated_by)->toBe($owner->id)
        ->and($otherType->company_id)->toBe($otherCompany->id);
});

test('a policy cannot reference a foreign or soft-deleted leave type', function () {
    [$owner, $company] = leavePolicyActor('company_owner');
    $foreignCompany = Company::factory()->create();
    $foreignType = leavePolicyType($foreignCompany, ['name' => 'Foreign Leave', 'code' => 'FOREIGN']);
    $deletedType = leavePolicyType($company, ['name' => 'Archived Leave', 'code' => 'ARCHIVE']);
    $deletedType->delete();
    $this->actingAs($owner);

    $this->post(leavePolicyUrl($company), leavePolicyInput($foreignType->id))->assertSessionHasErrors('leave_type_id');
    $this->post(leavePolicyUrl($company), leavePolicyInput($deletedType->id, ['code' => 'ARCHIVE-POL']))->assertSessionHasErrors('leave_type_id');
    expect($company->leavePolicies()->count())->toBe(0);
});

test('new active policies require an active leave type but inactive policy history can be configured', function () {
    [$owner, $company] = leavePolicyActor('company_owner');
    $inactiveType = leavePolicyType($company, ['name' => 'Inactive Leave', 'code' => 'INACTIVE', 'status' => LeaveTypeStatus::Inactive]);
    $this->actingAs($owner)->post(leavePolicyUrl($company), leavePolicyInput($inactiveType->id, ['code' => 'ACTIVE-POLICY']))
        ->assertSessionHasErrors('leave_type_id');

    $this->post(leavePolicyUrl($company), leavePolicyInput($inactiveType->id, ['code' => 'INACTIVE-POLICY', 'status' => 'inactive']))
        ->assertRedirect(leavePolicyUrl($company));
    expect($company->leavePolicies()->firstOrFail()->status)->toBe(LeavePolicyStatus::Inactive);
});

test('inactive leave types remain valid references for existing active policy history', function () {
    [$owner, $company] = leavePolicyActor('company_owner');
    $type = leavePolicyType($company);
    $policy = leavePolicyRow($company, $type);
    $type->update(['status' => LeaveTypeStatus::Inactive]);

    $this->actingAs($owner)->put(route('companies.leave-policies.update', [$company, $policy]), leavePolicyInput($type->id, ['description' => 'Updated historic configuration']))
        ->assertRedirect(leavePolicyUrl($company));
    expect($policy->fresh()->status)->toBe(LeavePolicyStatus::Active)
        ->and($policy->fresh()->description)->toBe('Updated historic configuration');
});

test('policy enum and decimal values reject invalid values and negative amounts', function () {
    [$owner, $company] = leavePolicyActor('company_owner');
    $type = leavePolicyType($company);
    $this->actingAs($owner);
    $url = route('companies.leave-policies.store', $company);

    $this->post($url, leavePolicyInput($type->id, ['entitlement_days' => '-0.50']))->assertSessionHasErrors('entitlement_days');
    $this->post($url, leavePolicyInput($type->id, ['maximum_request_days' => '1.999']))->assertSessionHasErrors('maximum_request_days');
    $this->post($url, leavePolicyInput($type->id, ['accrual_method' => 'daily']))->assertSessionHasErrors('accrual_method');
    $this->post($url, leavePolicyInput($type->id, ['accrual_frequency' => 'daily']))->assertSessionHasErrors('accrual_frequency');
    $this->post($url, leavePolicyInput($type->id, ['minimum_notice_days' => -1]))->assertSessionHasErrors('minimum_notice_days');
    $this->post($url, leavePolicyInput($type->id, ['status' => 'pending']))->assertSessionHasErrors('status');
    $this->post($url, leavePolicyInput($type->id, ['requires_reason' => 'maybe']))->assertSessionHasErrors('requires_reason');

    expect($company->leavePolicies()->count())->toBe(0);
});

test('accrual method and frequency must be compatible', function () {
    [$owner, $company] = leavePolicyActor('company_owner');
    $type = leavePolicyType($company);
    $this->actingAs($owner);
    $url = route('companies.leave-policies.store', $company);

    $this->post($url, leavePolicyInput($type->id, ['accrual_method' => 'accrual', 'accrual_frequency' => 'none']))->assertSessionHasErrors('accrual_frequency');
    $this->post($url, leavePolicyInput($type->id, ['accrual_method' => 'none', 'accrual_frequency' => 'monthly']))->assertSessionHasErrors('accrual_frequency');
    $this->post($url, leavePolicyInput($type->id, ['accrual_method' => 'upfront', 'accrual_frequency' => 'yearly']))->assertSessionHasErrors('accrual_frequency');
    $this->post($url, leavePolicyInput($type->id, ['accrual_method' => 'upfront', 'accrual_frequency' => 'none', 'code' => 'UPFRONT']))->assertRedirect();
});

test('carry-forward and negative-balance fields follow their enablement rules', function () {
    [$owner, $company] = leavePolicyActor('company_owner');
    $type = leavePolicyType($company);
    $this->actingAs($owner);
    $url = route('companies.leave-policies.store', $company);

    $this->post($url, leavePolicyInput($type->id, ['carry_forward_enabled' => false, 'max_carry_forward_days' => '5.00']))->assertSessionHasErrors('max_carry_forward_days');
    $this->post($url, leavePolicyInput($type->id, ['carry_forward_enabled' => true, 'max_carry_forward_days' => null]))->assertSessionHasErrors('max_carry_forward_days');
    $this->post($url, leavePolicyInput($type->id, ['allow_negative_balance' => false, 'max_negative_balance_days' => '1.00']))->assertSessionHasErrors('max_negative_balance_days');
    $this->post($url, leavePolicyInput($type->id, ['allow_negative_balance' => true, 'max_negative_balance_days' => '0.00']))->assertSessionHasErrors('max_negative_balance_days');
    $this->post($url, leavePolicyInput($type->id, ['allow_negative_balance' => true, 'max_negative_balance_days' => '2.50', 'code' => 'NEGATIVE']))->assertRedirect();
});

test('request duration and half-day rules are validated against the leave type', function () {
    [$owner, $company] = leavePolicyActor('company_owner');
    $halfDayType = leavePolicyType($company);
    $wholeDayType = leavePolicyType($company, ['name' => 'Whole Day Leave', 'code' => 'WHOLE', 'allows_half_day' => false]);
    $this->actingAs($owner);
    $url = route('companies.leave-policies.store', $company);

    $this->post($url, leavePolicyInput($halfDayType->id, ['minimum_request_days' => '1.00', 'maximum_request_days' => '0.50']))->assertSessionHasErrors('maximum_request_days');
    $this->post($url, leavePolicyInput($wholeDayType->id, ['code' => 'NO-HALF', 'allows_half_day' => true]))->assertSessionHasErrors('allows_half_day');
    $this->post($url, leavePolicyInput($wholeDayType->id, ['code' => 'FRACTION', 'allows_half_day' => false, 'minimum_request_days' => '0.50']))->assertSessionHasErrors('minimum_request_days');
    $this->post($url, leavePolicyInput($halfDayType->id, ['code' => 'HALF-VALID', 'minimum_request_days' => '0.50']))->assertRedirect();
});

test('policy activation requires an active leave type and status changes are audited', function () {
    [$owner, $company] = leavePolicyActor('company_owner');
    $type = leavePolicyType($company);
    $policy = leavePolicyRow($company, $type, ['status' => LeavePolicyStatus::Inactive]);
    $this->actingAs($owner)->patch(route('companies.leave-policies.activate', [$company, $policy]))->assertRedirect();
    expect($policy->fresh()->status)->toBe(LeavePolicyStatus::Active)
        ->and($policy->fresh()->updated_by)->toBe($owner->id);

    $type->update(['status' => LeaveTypeStatus::Inactive]);
    $this->patch(route('companies.leave-policies.deactivate', [$company, $policy]))->assertRedirect();
    $this->patch(route('companies.leave-policies.activate', [$company, $policy]))->assertSessionHasErrors('leave_type_id');
    expect($policy->fresh()->status)->toBe(LeavePolicyStatus::Inactive);
});

test('policies are soft deleted and company-scoped names and codes can be reused after archival', function () {
    [$owner, $company] = leavePolicyActor('company_owner');
    $otherCompany = Company::factory()->create();
    $type = leavePolicyType($company);
    $policy = leavePolicyRow($company, $type);
    $this->actingAs($owner)->post(leavePolicyUrl($company), leavePolicyInput($type->id))->assertSessionHasErrors(['name', 'code']);

    $otherType = leavePolicyType($otherCompany, ['name' => 'Annual Leave', 'code' => 'AL']);
    $otherPolicy = leavePolicyRow($otherCompany, $otherType);
    expect($otherPolicy->name)->toBe($policy->name)
        ->and($otherPolicy->code)->toBe($policy->code);

    $this->delete(route('companies.leave-policies.destroy', [$company, $policy]))->assertRedirect();
    expect(LeavePolicy::query()->find($policy->id))->toBeNull()
        ->and(LeavePolicy::withTrashed()->findOrFail($policy->id)->trashed())->toBeTrue()
        ->and(LeavePolicy::withTrashed()->findOrFail($policy->id)->updated_by)->toBe($owner->id);
    $this->post(leavePolicyUrl($company), leavePolicyInput($type->id))->assertRedirect();
});

test('cross-company policies cannot be read or changed through another company route', function () {
    [$owner, $company] = leavePolicyActor('company_owner');
    $foreignCompany = Company::factory()->create();
    $foreignType = leavePolicyType($foreignCompany);
    $foreignPolicy = leavePolicyRow($foreignCompany, $foreignType);
    $this->actingAs($owner);

    $this->get(leavePolicyUrl($foreignCompany))->assertNotFound();
    $this->put(route('companies.leave-policies.update', [$company, $foreignPolicy]), leavePolicyInput($foreignType->id))->assertNotFound();
    $this->delete(route('companies.leave-policies.destroy', [$company, $foreignPolicy]))->assertNotFound();
    $this->patch(route('companies.leave-policies.deactivate', [$company, $foreignPolicy]))->assertNotFound();
    expect($foreignPolicy->fresh()->status)->toBe(LeavePolicyStatus::Active);
});

test('policy role access follows the owner HR manager and manager matrix', function () {
    foreach (['company_owner', 'hr_admin', 'hr_manager', 'manager', 'accountant', 'employee'] as $role) {
        [$user, $company] = leavePolicyActor($role);
        $type = leavePolicyType($company);
        $index = $this->actingAs($user)->get(leavePolicyUrl($company));
        $index->assertStatus(in_array($role, ['accountant', 'employee'], true) ? 403 : 200);

        if ($role === 'manager') {
            $this->post(leavePolicyUrl($company), leavePolicyInput($type->id))->assertForbidden();
            $policy = leavePolicyRow($company, $type);
            $this->put(route('companies.leave-policies.update', [$company, $policy]), leavePolicyInput($type->id, ['name' => 'Manager Change']))->assertForbidden();
        }
        if (in_array($role, ['accountant', 'employee'], true)) {
            $this->post(leavePolicyUrl($company), leavePolicyInput($type->id))->assertForbidden();
        }
        if ($role === 'hr_manager') {
            $this->post(leavePolicyUrl($company), leavePolicyInput($type->id, ['code' => 'HRM']))->assertRedirect();
            $policy = leavePolicyRow($company, $type, ['name' => 'Existing Policy', 'code' => 'EXISTING']);
            $this->put(route('companies.leave-policies.update', [$company, $policy]), leavePolicyInput($type->id, ['name' => 'HR Manager Updated', 'code' => 'EXISTING']))->assertRedirect();
            $this->delete(route('companies.leave-policies.destroy', [$company, $policy]))->assertForbidden();
            $this->patch(route('companies.leave-policies.deactivate', [$company, $policy]))->assertForbidden();
        }
    }
});

test('policy list searches filters and paginates without returning another company records', function () {
    [$hr, $company] = leavePolicyActor('hr_admin');
    $type = leavePolicyType($company);
    foreach (range(1, 22) as $number) {
        leavePolicyRow($company, $type, ['name' => "Policy {$number}", 'code' => "POL-{$number}"]);
    }
    leavePolicyRow($company, $type, ['name' => 'Inactive Policy', 'code' => 'INACTIVE', 'status' => LeavePolicyStatus::Inactive, 'accrual_method' => LeaveAccrualMethod::Upfront, 'accrual_frequency' => LeaveAccrualFrequency::None]);
    $foreignCompany = Company::factory()->create();
    $foreignType = leavePolicyType($foreignCompany);
    leavePolicyRow($foreignCompany, $foreignType, ['name' => 'Foreign Policy', 'code' => 'FOREIGN']);

    $this->actingAs($hr)->get(leavePolicyUrl($company))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('leave-policies/index', false)->has('policies.data', 20)->where('policies.total', 23));
    $this->get(route('companies.leave-policies.index', ['company' => $company, 'search' => 'Inactive', 'status' => 'inactive', 'accrual_method' => 'upfront', 'leave_type_id' => $type->id]))
        ->assertInertia(fn (Assert $page) => $page->has('policies.data', 1)->where('policies.data.0.code', 'INACTIVE'));
});

test('database composite foreign keys reject a leave type from another company', function () {
    [$owner, $company] = leavePolicyActor('company_owner');
    $otherCompany = Company::factory()->create();
    $foreignType = leavePolicyType($otherCompany);

    expect(fn () => DB::table('leave_policies')->insert([
        'company_id' => $company->id,
        'leave_type_id' => $foreignType->id,
        'name' => 'Corrupt policy',
        'code' => 'CORRUPT',
        'status' => 'active',
        'entitlement_days' => '1.00',
        'accrual_method' => 'none',
        'accrual_frequency' => 'none',
        'minimum_request_days' => '1.00',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});
