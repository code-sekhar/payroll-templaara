<?php

use App\Actions\SeedCompanyRoles;
use App\Enums\LeaveRequestDayType;
use App\Enums\LeaveRequestStatus;
use App\Enums\WorkScheduleDayType;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\EmployeeLeaveBalanceAccount;
use App\Models\EmployeeLeavePolicyAssignment;
use App\Models\HolidayCalendar;
use App\Models\LeaveLedgerEntry;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestDay;
use App\Models\LeaveType;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Leave\LeaveAmount;
use App\Services\Leave\LeaveDayResolver;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

/** @return array{User, Company, Employee} */
function leaveRequestActor(string $role = 'hr_admin', bool $employeeUser = false, string $timezone = 'Asia/Kolkata'): array
{
    $user = User::factory()->create();
    $company = Company::factory()->create(['timezone' => $timezone]);
    CompanyUser::create(['company_id' => $company->id, 'user_id' => $user->id, 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($company);
    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $employee = Employee::factory()->create(['company_id' => $company->id, 'user_id' => $employeeUser ? $user->id : null]);

    return [$user, $company, $employee];
}

/** @return array{LeaveType, LeavePolicy, EmployeeLeavePolicyAssignment, EmployeeLeaveBalanceAccount, WorkSchedule, Shift} */
function leaveRequestConfiguration(Company $company, Employee $employee, array $policyChanges = [], array $typeChanges = [], string $balance = '10.00'): array
{
    $type = LeaveType::factory()->create(array_merge(['company_id' => $company->id, 'allows_half_day' => true], $typeChanges));
    $policy = LeavePolicy::factory()->create(array_merge([
        'company_id' => $company->id, 'leave_type_id' => $type->id, 'allows_half_day' => true,
        'minimum_request_days' => '0.50', 'minimum_notice_days' => 0,
    ], $policyChanges));
    $assignment = EmployeeLeavePolicyAssignment::factory()->create([
        'company_id' => $company->id, 'employee_id' => $employee->id, 'leave_type_id' => $type->id,
        'leave_policy_id' => $policy->id, 'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31',
    ]);
    $account = EmployeeLeaveBalanceAccount::factory()->create([
        'company_id' => $company->id, 'employee_id' => $employee->id, 'leave_type_id' => $type->id,
        'leave_policy_id' => $policy->id, 'employee_leave_policy_assignment_id' => $assignment->id,
        'period_start' => '2026-01-01', 'period_end' => '2026-12-31', 'status' => 'open',
    ]);
    $account->ledgerEntries()->create([
        'company_id' => $company->id, 'employee_id' => $employee->id, 'leave_type_id' => $type->id,
        'leave_policy_id' => $policy->id, 'employee_leave_policy_assignment_id' => $assignment->id,
        'transaction_type' => 'opening_balance', 'amount' => $balance, 'effective_date' => '2026-01-01',
        'description' => 'Test opening', 'created_at' => now(),
    ]);
    $schedule = WorkSchedule::factory()->create(['company_id' => $company->id]);
    $shift = $company->shifts()->create([
        'name' => 'General '.fake()->unique()->numerify('####'), 'code' => 'GEN-'.fake()->unique()->numerify('####'),
        'start_time' => '09:00', 'end_time' => '18:00', 'break_duration_minutes' => 60, 'grace_period_minutes' => 0,
        'is_overnight' => false, 'status' => 'active',
    ]);
    $schedule->days()->where('day_of_week', 1)->update(['day_type' => WorkScheduleDayType::Working->value, 'shift_id' => $shift->id]);
    $company->employeeWorkScheduleAssignments()->create([
        'employee_id' => $employee->id, 'work_schedule_id' => $schedule->id,
        'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31', 'status' => 'active',
    ]);

    return [$type, $policy, $assignment, $account, $schedule, $shift];
}

function leaveRequestPayload(LeaveType $type, array $overrides = []): array
{
    return array_merge(['leave_type_id' => $type->id, 'start_date' => '2026-10-05', 'end_date' => '2026-10-05', 'duration' => 'full_day', 'reason' => 'Personal appointment'], $overrides);
}

beforeEach(function (): void {
    config()->set('inertia.testing.page_paths', [resource_path('js/pages')]);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 08:00:00', 'Asia/Kolkata'));
});

test('leave day resolver snapshots working off and holiday dates using shared schedule and holiday resolvers', function () {
    [$hr, $company, $employee] = leaveRequestActor();
    [$type, , , , $schedule] = leaveRequestConfiguration($company, $employee);
    $calendar = HolidayCalendar::factory()->create(['company_id' => $company->id, 'year' => 2026]);
    $company->employeeHolidayCalendarAssignments()->create([
        'employee_id' => $employee->id, 'holiday_calendar_id' => $calendar->id,
        'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31', 'status' => 'active',
    ]);
    $calendar->holidays()->create(['company_id' => $company->id, 'date' => '2026-10-05', 'name' => 'Company holiday', 'type' => 'company', 'status' => 'active']);

    $result = app(LeaveDayResolver::class)->resolve($employee, '2026-10-05', '2026-10-06');

    expect($result->requestedDays)->toBe('0.00')
        ->and($result->days[0]->dayType)->toBe(LeaveRequestDayType::Holiday)
        ->and($result->days[0]->requestedAmount)->toBe('0.00')
        ->and($result->days[0]->workSchedule->workSchedule->is($schedule))->toBeTrue()
        ->and($result->days[1]->dayType)->toBe(LeaveRequestDayType::OffDay);
});

test('HR submits a pending request with immutable day snapshots and leaves ledger untouched', function () {
    [$hr, $company, $employee] = leaveRequestActor();
    [$type, , , $account] = leaveRequestConfiguration($company, $employee);
    $before = $account->ledgerEntries()->count();

    $this->actingAs($hr)->post(route('companies.leave-requests.store', $company), leaveRequestPayload($type, ['employee_id' => $employee->id]))->assertRedirect();

    $request = LeaveRequest::query()->with('days')->firstOrFail();
    expect($request->status)->toBe(LeaveRequestStatus::Pending)
        ->and($request->employee_id)->toBe($employee->id)
        ->and($request->requested_by)->toBe($hr->id)
        ->and($request->requested_days)->toBe('1.00')
        ->and($request->days)->toHaveCount(1)
        ->and($request->days->first()->day_type)->toBe(LeaveRequestDayType::FullDay)
        ->and($account->ledgerEntries()->count())->toBe($before)
        ->and(LeaveLedgerEntry::query()->where('leave_balance_account_id', $account->id)->count())->toBe(1);
});

test('employee self service derives employee and requester and rejects an employee identity spoof', function () {
    [$user, $company, $employee] = leaveRequestActor('employee', true);
    [$type] = leaveRequestConfiguration($company, $employee);
    $other = Employee::factory()->create(['company_id' => $company->id]);

    $this->actingAs($user)->post(route('companies.my-leave.store', $company), leaveRequestPayload($type, ['employee_id' => $other->id]))->assertRedirect();

    $request = LeaveRequest::query()->firstOrFail();
    expect($request->employee_id)->toBe($employee->id)->and($request->requested_by)->toBe($user->id);
});

test('a request uses only the effective leave policy and rejects missing or crossed policy periods', function () {
    [$hr, $company, $employee] = leaveRequestActor();
    [$type, $policy, $assignment, $account] = leaveRequestConfiguration($company, $employee);
    $this->actingAs($hr);

    $assignment->update(['effective_to' => '2026-10-04']);
    $this->post(route('companies.leave-requests.store', $company), leaveRequestPayload($type, ['employee_id' => $employee->id, 'start_date' => '2026-10-12', 'end_date' => '2026-10-12']))->assertSessionHasErrors('start_date');
    $assignment->update(['effective_to' => '2026-10-06']);
    $nextPolicy = LeavePolicy::factory()->create(['company_id' => $company->id, 'leave_type_id' => $type->id]);
    $nextAssignment = EmployeeLeavePolicyAssignment::factory()->create([
        'company_id' => $company->id, 'employee_id' => $employee->id, 'leave_type_id' => $type->id,
        'leave_policy_id' => $nextPolicy->id, 'effective_from' => '2026-10-07', 'effective_to' => '2026-12-31',
    ]);
    $account->update(['status' => 'closed']);
    $this->post(route('companies.leave-requests.store', $company), leaveRequestPayload($type, ['employee_id' => $employee->id, 'end_date' => '2026-10-08']))->assertSessionHasErrors('start_date');
    expect($nextAssignment->exists)->toBeTrue();
});

test('pending requests reserve balance while cancelled requests release reservation without ledger postings', function () {
    [$hr, $company, $employee] = leaveRequestActor();
    [$type, , , $account] = leaveRequestConfiguration($company, $employee, balance: '1.00');
    $this->actingAs($hr);
    $this->post(route('companies.leave-requests.store', $company), leaveRequestPayload($type, ['employee_id' => $employee->id, 'start_date' => '2026-10-12', 'end_date' => '2026-10-12']))->assertRedirect();
    $request = LeaveRequest::query()->firstOrFail();
    $this->post(route('companies.leave-requests.store', $company), leaveRequestPayload($type, ['employee_id' => $employee->id]))->assertSessionHasErrors('leave_type_id');
    $this->post(route('companies.leave-requests.cancel', [$company, $request]), ['cancellation_reason' => '     '])->assertSessionHasErrors('cancellation_reason');
    expect($request->fresh()->status)->toBe(LeaveRequestStatus::Pending)
        ->and($account->ledgerEntries()->count())->toBe(1);
    $this->post(route('companies.leave-requests.cancel', [$company, $request]), ['cancellation_reason' => 'Plans changed'])->assertRedirect();
    expect($request->fresh()->status)->toBe(LeaveRequestStatus::Cancelled)
        ->and($request->days()->count())->toBe(1)
        ->and($account->ledgerEntries()->count())->toBe(1);
    $this->post(route('companies.leave-requests.store', $company), leaveRequestPayload($type, ['employee_id' => $employee->id]))->assertRedirect();
    expect($account->ledgerEntries()->count())->toBe(1);
});

test('same employee cannot reserve the same chargeable date across leave types', function () {
    [$hr, $company, $employee] = leaveRequestActor();
    [$type] = leaveRequestConfiguration($company, $employee, balance: '5.00');
    [$otherType] = leaveRequestConfiguration($company, $employee, balance: '5.00');
    $this->actingAs($hr);
    $this->post(route('companies.leave-requests.store', $company), leaveRequestPayload($type, ['employee_id' => $employee->id]))->assertRedirect();
    expect(LeaveRequest::query()->count())->toBe(1);
    $this->post(route('companies.leave-requests.store', $company), leaveRequestPayload($otherType, ['employee_id' => $employee->id]))->assertSessionHasErrors('start_date');
});

test('new leave requests cannot overlap an already approved chargeable date', function () {
    [$hr, $company, $employee] = leaveRequestActor();
    [$type, $policy, $assignment, $account, $schedule] = leaveRequestConfiguration($company, $employee);
    $scheduleAssignment = $employee->workScheduleAssignments()->firstOrFail();
    $approved = LeaveRequest::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'leave_policy_id' => $policy->id,
        'employee_leave_policy_assignment_id' => $assignment->id,
        'leave_balance_account_id' => $account->id,
        'start_date' => '2026-10-05',
        'end_date' => '2026-10-05',
        'requested_days' => '1.00',
        'status' => LeaveRequestStatus::Approved,
    ]);
    $approved->days()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_date' => '2026-10-05',
        'day_type' => LeaveRequestDayType::FullDay,
        'requested_amount' => '1.00',
        'work_schedule_id' => $schedule->id,
        'work_schedule_assignment_id' => $scheduleAssignment->id,
    ]);

    $this->actingAs($hr)->post(route('companies.leave-requests.store', $company), leaveRequestPayload($type, ['employee_id' => $employee->id]))
        ->assertSessionHasErrors(['start_date' => 'An existing pending or approved leave request already covers one or more selected working dates.']);

    expect(LeaveRequest::query()->where('employee_id', $employee->id)->count())->toBe(1);
});

test('rejected and cancelled requests do not block a new request for the same dates', function () {
    [$hr, $company, $employee] = leaveRequestActor();
    [$type, $policy, $assignment, $account, $schedule] = leaveRequestConfiguration($company, $employee);
    $scheduleAssignment = $employee->workScheduleAssignments()->firstOrFail();

    foreach ([['2026-10-05', 'rejected'], ['2026-10-12', 'cancelled']] as [$date, $status]) {
        $oldRequest = LeaveRequest::factory()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'leave_policy_id' => $policy->id,
            'employee_leave_policy_assignment_id' => $assignment->id,
            'leave_balance_account_id' => $account->id,
            'start_date' => $date,
            'end_date' => $date,
            'requested_days' => '1.00',
            'status' => $status,
        ]);
        $oldRequest->days()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'leave_date' => $date,
            'day_type' => LeaveRequestDayType::FullDay,
            'requested_amount' => '1.00',
            'work_schedule_id' => $schedule->id,
            'work_schedule_assignment_id' => $scheduleAssignment->id,
        ]);
    }

    $this->actingAs($hr)->post(route('companies.leave-requests.store', $company), leaveRequestPayload($type, ['employee_id' => $employee->id]))
        ->assertRedirect();
    $this->post(route('companies.leave-requests.store', $company), leaveRequestPayload($type, [
        'employee_id' => $employee->id,
        'start_date' => '2026-10-12',
        'end_date' => '2026-10-12',
    ]))->assertRedirect();

    expect(LeaveRequest::query()->where('employee_id', $employee->id)->where('status', LeaveRequestStatus::Pending->value)->count())->toBe(2);
});

test('off days and holidays do not consume balance and all-zero requests are rejected', function () {
    [$hr, $company, $employee] = leaveRequestActor();
    [$type, , , $account] = leaveRequestConfiguration($company, $employee, balance: '5.00');
    $this->actingAs($hr);
    $this->post(route('companies.leave-requests.store', $company), leaveRequestPayload($type, ['employee_id' => $employee->id, 'start_date' => '2026-10-06', 'end_date' => '2026-10-06']))->assertSessionHasErrors('start_date');
    expect($account->ledgerEntries()->count())->toBe(1)->and(LeaveRequestDay::query()->count())->toBe(0);
});

test('scheduled half day charges one half for a full-day request and half-day request is limited to one date', function () {
    [$hr, $company, $employee] = leaveRequestActor();
    [$type, $policy, , $account, $schedule, $shift] = leaveRequestConfiguration($company, $employee, balance: '5.00');
    $schedule->days()->where('day_of_week', 1)->update(['day_type' => WorkScheduleDayType::HalfDay->value]);
    $this->actingAs($hr);
    $this->post(route('companies.leave-requests.store', $company), leaveRequestPayload($type, ['employee_id' => $employee->id]))->assertRedirect();
    $firstRequest = LeaveRequest::query()->latest('id')->firstOrFail();
    expect($firstRequest->requested_days)->toBe('0.50');
    $this->post(route('companies.leave-requests.cancel', [$company, $firstRequest]), ['cancellation_reason' => 'Correcting duration'])->assertRedirect();
    $this->post(route('companies.leave-requests.store', $company), leaveRequestPayload($type, ['employee_id' => $employee->id, 'duration' => 'half_day']))->assertRedirect();
    expect(LeaveRequest::query()->latest('id')->firstOrFail()->requested_days)->toBe('0.50');
    $this->post(route('companies.leave-requests.store', $company), leaveRequestPayload($type, ['employee_id' => $employee->id, 'start_date' => '2026-10-05', 'end_date' => '2026-10-06', 'duration' => 'half_day']))->assertSessionHasErrors('end_date');
});

test('policy minimum notice reason minimum and maximum request rules are enforced', function () {
    [$hr, $company, $employee] = leaveRequestActor();
    [$type, $policy] = leaveRequestConfiguration($company, $employee, ['minimum_notice_days' => 3, 'minimum_request_days' => '1.50', 'maximum_request_days' => '2.00', 'requires_reason' => true]);
    $this->actingAs($hr);
    $url = route('companies.leave-requests.store', $company);
    $this->post($url, leaveRequestPayload($type, ['employee_id' => $employee->id, 'reason' => null]))->assertSessionHasErrors('reason');
    $this->post($url, leaveRequestPayload($type, ['employee_id' => $employee->id, 'start_date' => '2026-10-06', 'end_date' => '2026-10-06']))->assertSessionHasErrors('start_date');
    $policy->update(['minimum_notice_days' => 0, 'minimum_request_days' => '0.50']);
    $this->post($url, leaveRequestPayload($type, ['employee_id' => $employee->id, 'start_date' => '2026-10-05', 'end_date' => '2026-10-19']))->assertSessionHasErrors('end_date');
});

test('HR and employees see only authorized request scope and request structure has no update or delete route', function () {
    [$employeeUser, $company, $employee] = leaveRequestActor('employee', true);
    [$type] = leaveRequestConfiguration($company, $employee);
    $request = LeaveRequest::factory()->create([
        'company_id' => $company->id, 'employee_id' => $employee->id, 'leave_type_id' => $type->id,
        'leave_policy_id' => $type->policies()->first()->id,
        'employee_leave_policy_assignment_id' => $employee->leavePolicyAssignments()->first()->id,
        'leave_balance_account_id' => $employee->leaveBalanceAccounts()->first()->id,
        'start_date' => '2026-10-05', 'end_date' => '2026-10-05',
    ]);
    $this->actingAs($employeeUser)->get(route('companies.my-leave.index', $company))->assertOk()->assertInertia(fn (Assert $page) => $page->component('leave-requests/my-leave')->has('requests.data', 1));
    $this->get(route('companies.leave-requests.index', $company))->assertForbidden();
    $this->get(route('companies.my-leave.show', [$company, $request]))->assertOk();
    $this->put(route('companies.leave-requests.show', [$company, $request]), [])->assertMethodNotAllowed();
    $this->delete(route('companies.leave-requests.show', [$company, $request]))->assertMethodNotAllowed();
});

test('manager list is scoped to self and direct reports and accountants have no request access', function () {
    [$managerUser, $company, $managerEmployee] = leaveRequestActor('manager', true);
    $direct = Employee::factory()->create(['company_id' => $company->id, 'manager_id' => $managerEmployee->id]);
    $other = Employee::factory()->create(['company_id' => $company->id]);
    [$type, $policy, $assignment, $account] = leaveRequestConfiguration($company, $direct);
    $directRequest = LeaveRequest::factory()->create(['company_id' => $company->id, 'employee_id' => $direct->id, 'leave_type_id' => $type->id,
        'leave_policy_id' => $policy->id, 'employee_leave_policy_assignment_id' => $assignment->id, 'leave_balance_account_id' => $account->id,
        'start_date' => '2026-10-05', 'end_date' => '2026-10-05']);
    $otherSetup = leaveRequestConfiguration($company, $other);
    LeaveRequest::factory()->create(['company_id' => $company->id, 'employee_id' => $other->id, 'leave_type_id' => $otherSetup[0]->id,
        'leave_policy_id' => $otherSetup[1]->id, 'employee_leave_policy_assignment_id' => $otherSetup[2]->id, 'leave_balance_account_id' => $otherSetup[3]->id,
        'start_date' => '2026-10-05', 'end_date' => '2026-10-05']);

    $this->actingAs($managerUser)->get(route('companies.leave-requests.index', $company))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->component('leave-requests/index')->has('requests.data', 1)->where('requests.data.0.id', $directRequest->id));
    [$accountant] = leaveRequestActor('accountant');
    $this->actingAs($accountant)->get(route('companies.leave-requests.index', $company))->assertNotFound();
});

test('foreign tenant request URLs are hidden from another company', function () {
    [$hr, $company, $employee] = leaveRequestActor();
    [$type, $policy, $assignment, $account] = leaveRequestConfiguration($company, $employee);
    $request = LeaveRequest::factory()->create(['company_id' => $company->id, 'employee_id' => $employee->id, 'leave_type_id' => $type->id,
        'leave_policy_id' => $policy->id, 'employee_leave_policy_assignment_id' => $assignment->id, 'leave_balance_account_id' => $account->id,
        'start_date' => '2026-10-05', 'end_date' => '2026-10-05']);
    [$otherHr, $otherCompany] = leaveRequestActor();
    $this->actingAs($otherHr)->get(route('companies.leave-requests.show', [$otherCompany, $request]))->assertNotFound();
});

test('model and database preserve immutable request day snapshots', function () {
    [$hr, $company, $employee] = leaveRequestActor();
    [$type, $policy, $assignment, $account] = leaveRequestConfiguration($company, $employee);
    $request = LeaveRequest::factory()->create(['company_id' => $company->id, 'employee_id' => $employee->id, 'leave_type_id' => $type->id,
        'leave_policy_id' => $policy->id, 'employee_leave_policy_assignment_id' => $assignment->id, 'leave_balance_account_id' => $account->id,
        'start_date' => '2026-10-05', 'end_date' => '2026-10-05']);
    $day = $request->days()->create(['company_id' => $company->id, 'employee_id' => $employee->id, 'leave_date' => '2026-10-05', 'day_type' => LeaveRequestDayType::FullDay, 'requested_amount' => '1.00']);

    expect(fn () => $day->update(['requested_amount' => '0.50']))->toThrow(LogicException::class)
        ->and(fn () => $day->delete())->toThrow(LogicException::class)
        ->and(fn () => $request->update(['requested_days' => '0.50']))->toThrow(LogicException::class)
        ->and(fn () => $request->delete())->toThrow(LogicException::class)
        ->and($day->fresh()->requested_amount)->toBe('1.00')
        ->and(LeaveAmount::toCents((string) $request->fresh()->requested_days))->toBe(100);
});
