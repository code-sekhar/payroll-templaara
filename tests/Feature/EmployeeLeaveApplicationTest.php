<?php

use App\Actions\SeedCompanyRoles;
use App\Enums\LeaveLedgerTransactionType;
use App\Enums\LeaveRequestDayType;
use App\Enums\WorkScheduleDayType;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\DailyAttendanceRecord;
use App\Models\Employee;
use App\Models\EmployeeLeaveBalanceAccount;
use App\Models\EmployeeLeavePolicyAssignment;
use App\Models\HolidayCalendar;
use App\Models\LeaveLedgerEntry;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Leave\LeaveRequestReservationCalculator;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** @return array{Company, User, Employee} */
function employeeLeaveApplicationIdentity(?User $user = null, ?Company $company = null): array
{
    $company ??= Company::factory()->create(['timezone' => 'Asia/Kolkata']);
    $user ??= User::factory()->create();
    CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $user->id, 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($company);

    $registrar = app(PermissionRegistrar::class);
    $previousTeamId = $registrar->getPermissionsTeamId();
    $registrar->setPermissionsTeamId($company->id);
    if (! $user->hasRole('employee')) {
        $user->assignRole('employee');
    }
    $registrar->setPermissionsTeamId($previousTeamId);

    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'user_id' => $user->id,
        'first_name' => 'Rahul',
        'last_name' => 'Das',
    ]);

    return [$company, $user, $employee];
}

/** @return array{LeaveType, LeavePolicy, EmployeeLeavePolicyAssignment, EmployeeLeaveBalanceAccount, WorkSchedule} */
function employeeLeaveApplicationConfiguration(Company $company, Employee $employee, array $changes = []): array
{
    $type = LeaveType::factory()->create([
        'company_id' => $company->id,
        'name' => $changes['name'] ?? 'Annual Leave',
        'allows_half_day' => $changes['type_allows_half_day'] ?? true,
    ]);
    $policy = LeavePolicy::factory()->create([
        'company_id' => $company->id,
        'leave_type_id' => $type->id,
        'allows_half_day' => $changes['policy_allows_half_day'] ?? true,
        'requires_reason' => $changes['requires_reason'] ?? false,
        'minimum_request_days' => '0.50',
        'minimum_notice_days' => 0,
    ]);
    $assignment = EmployeeLeavePolicyAssignment::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'leave_policy_id' => $policy->id,
        'effective_from' => '2026-01-01',
        'effective_to' => '2026-12-31',
    ]);
    $account = EmployeeLeaveBalanceAccount::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'leave_policy_id' => $policy->id,
        'employee_leave_policy_assignment_id' => $assignment->id,
        'period_start' => '2026-01-01',
        'period_end' => '2026-12-31',
        'status' => 'open',
    ]);
    $account->ledgerEntries()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'leave_policy_id' => $policy->id,
        'employee_leave_policy_assignment_id' => $assignment->id,
        'transaction_type' => LeaveLedgerTransactionType::OpeningBalance,
        'amount' => $changes['balance'] ?? '10.00',
        'effective_date' => '2026-01-01',
        'description' => 'Test opening balance',
    ]);

    $schedule = WorkSchedule::factory()->create(['company_id' => $company->id]);
    $shift = Shift::factory()->create(['company_id' => $company->id]);
    $schedule->days()->whereIn('day_of_week', [1, 2, 3, 4, 5])->update([
        'day_type' => WorkScheduleDayType::Working->value,
        'shift_id' => $shift->id,
    ]);
    $schedule->days()->where('day_of_week', 5)->update(['day_type' => WorkScheduleDayType::HalfDay->value]);
    $company->employeeWorkScheduleAssignments()->create([
        'employee_id' => $employee->id,
        'work_schedule_id' => $schedule->id,
        'effective_from' => '2026-01-01',
        'effective_to' => '2026-12-31',
        'status' => 'active',
    ]);

    return [$type, $policy, $assignment, $account, $schedule];
}

/** @return array<string, mixed> */
function employeeLeaveApplicationPayload(LeaveType $type, array $overrides = []): array
{
    return array_merge([
        'leave_type_id' => $type->id,
        'start_date' => '2026-10-05',
        'end_date' => '2026-10-05',
        'duration' => 'full_day',
        'reason' => 'Personal appointment',
    ], $overrides);
}

beforeEach(function (): void {
    config()->set('inertia.testing.page_paths', [resource_path('js/pages')]);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 08:00:00', 'Asia/Kolkata'));
});

test('employee can open the application form with only current applicable leave types and without writes', function () {
    [$company, $user, $employee] = employeeLeaveApplicationIdentity();
    [$type, , , $account] = employeeLeaveApplicationConfiguration($company, $employee);
    $futureType = LeaveType::factory()->create(['company_id' => $company->id, 'name' => 'Future Leave']);
    $futurePolicy = LeavePolicy::factory()->create(['company_id' => $company->id, 'leave_type_id' => $futureType->id]);
    EmployeeLeavePolicyAssignment::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $futureType->id,
        'leave_policy_id' => $futurePolicy->id,
        'effective_from' => '2027-01-01',
        'effective_to' => null,
    ]);
    $before = [
        LeaveRequest::query()->count(),
        LeaveLedgerEntry::query()->count(),
        EmployeeLeaveBalanceAccount::query()->count(),
        DailyAttendanceRecord::query()->count(),
    ];

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->get(route('employee.leave.apply', ['employee_id' => 999999, 'company_id' => 999999]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('employee-portal/leave-apply', false)
            ->where('employeeName', $employee->full_name)
            ->where('companyName', $company->name)
            ->where('asOfDate', '2026-10-05')
            ->where('leaveTypes.0.id', $type->id)
            ->where('leaveTypes.0.name', 'Annual Leave')
            ->where('leaveTypes.0.configured', true)
            ->where('leaveTypes.0.available', '10.00')
            ->where('leaveTypes.0.pending', '0.00')
            ->where('leaveTypes.0.allowsHalfDay', true)
            ->has('leaveTypes', 1)
            ->missing('leaveTypes.0.employee_id')
            ->missing('leaveTypes.0.company_id')
            ->missing('leaveTypes.0.policyId')
            ->where('auth.user', null)
            ->where('companies', [])
            ->where('activeCompany', null));

    expect([
        LeaveRequest::query()->count(),
        LeaveLedgerEntry::query()->count(),
        EmployeeLeaveBalanceAccount::query()->count(),
        DailyAttendanceRecord::query()->count(),
    ])->toBe($before)
        ->and($account->ledgerEntries()->count())->toBe(1);
});

test('employee can submit a pending request using the canonical resolver and server-owned identity', function () {
    [$company, $user, $employee] = employeeLeaveApplicationIdentity();
    [$type, , , $account] = employeeLeaveApplicationConfiguration($company, $employee);

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->post(route('employee.leave.store'), employeeLeaveApplicationPayload($type, [
            'employee_id' => $employee->id + 1,
            'company_id' => $company->id + 1,
        ]))
        ->assertSessionHasErrors(['employee_id', 'company_id']);

    expect(LeaveRequest::query()->count())->toBe(0);

    $this->post(route('employee.leave.store'), employeeLeaveApplicationPayload($type, [
        'reason' => '<script>alert("x")</script>',
    ]))->assertRedirect(route('employee.leave.index'))->assertSessionHas('success', 'Leave request submitted. It is now pending review.');

    $request = LeaveRequest::query()->with('days')->firstOrFail();
    expect($request->company_id)->toBe($company->id)
        ->and($request->employee_id)->toBe($employee->id)
        ->and($request->leave_type_id)->toBe($type->id)
        ->and($request->requested_by)->toBe($user->id)
        ->and($request->status->value)->toBe('pending')
        ->and($request->requested_days)->toBe('1.00')
        ->and($request->reason)->toBe('<script>alert("x")</script>')
        ->and($request->reviewed_by)->toBeNull()
        ->and($request->review_note)->toBeNull()
        ->and($request->days)->toHaveCount(1)
        ->and($account->ledgerEntries()->count())->toBe(1)
        ->and(app(LeaveRequestReservationCalculator::class)->pendingCents($account))->toBe(100);
});

test('employee leave overview does not offer application when self create permission is removed', function () {
    [$company, $user, $employee] = employeeLeaveApplicationIdentity();
    employeeLeaveApplicationConfiguration($company, $employee);
    $registrar = app(PermissionRegistrar::class);
    $previousTeamId = $registrar->getPermissionsTeamId();
    $registrar->setPermissionsTeamId($company->id);
    Role::query()->where('name', 'employee')->firstOrFail()->revokePermissionTo('leave_request.self_create');
    $registrar->setPermissionsTeamId($previousTeamId);

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->get(route('employee.leave.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canApplyForLeave', false));
});

test('employee cannot forge request status duration amount reviewer or accounting fields', function () {
    [$company, $user, $employee] = employeeLeaveApplicationIdentity();
    [$type] = employeeLeaveApplicationConfiguration($company, $employee);

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->post(route('employee.leave.store'), employeeLeaveApplicationPayload($type, [
            'duration' => '0.5',
            'requested_days' => '0.01',
            'status' => 'approved',
            'requested_by' => User::factory()->create()->id,
            'reviewed_by' => User::factory()->create()->id,
            'review_note' => 'Approved',
            'leave_balance_account_id' => 98765,
            'ledger_entry_id' => 12345,
        ]))
        ->assertSessionHasErrors(['duration', 'requested_days', 'status', 'requested_by', 'reviewed_by', 'review_note', 'leave_balance_account_id', 'ledger_entry_id']);

    expect(LeaveRequest::query()->count())->toBe(0);
});

test('employee request form rejects foreign and unassigned leave types', function () {
    [$company, $user, $employee] = employeeLeaveApplicationIdentity();
    employeeLeaveApplicationConfiguration($company, $employee);
    [$otherCompany] = employeeLeaveApplicationIdentity();
    $foreignType = LeaveType::factory()->create(['company_id' => $otherCompany->id]);
    $unassignedType = LeaveType::factory()->create(['company_id' => $company->id, 'name' => 'Unassigned']);

    $this->actingAs($user)->withSession(['active_company_id' => $company->id]);
    $this->post(route('employee.leave.store'), employeeLeaveApplicationPayload($foreignType))
        ->assertSessionHasErrors('leave_type_id');
    $this->post(route('employee.leave.store'), employeeLeaveApplicationPayload($unassignedType))
        ->assertSessionHasErrors('start_date');

    expect(LeaveRequest::query()->count())->toBe(0);
});

test('employee request uses updated assignment after form load and rejects stale configuration', function () {
    [$company, $user, $employee] = employeeLeaveApplicationIdentity();
    [$type, , $assignment] = employeeLeaveApplicationConfiguration($company, $employee);
    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->get(route('employee.leave.apply'))->assertOk();

    $assignment->update(['status' => 'inactive']);
    $this->post(route('employee.leave.store'), employeeLeaveApplicationPayload($type))
        ->assertSessionHasErrors('start_date');

    expect(LeaveRequest::query()->count())->toBe(0);
});

test('employee request rechecks current reservations instead of trusting displayed available balance', function () {
    [$company, $user, $employee] = employeeLeaveApplicationIdentity();
    [$type, $policy, $assignment, $account] = employeeLeaveApplicationConfiguration($company, $employee);
    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->get(route('employee.leave.apply'))->assertOk();

    $existingPending = LeaveRequest::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'leave_policy_id' => $policy->id,
        'employee_leave_policy_assignment_id' => $assignment->id,
        'leave_balance_account_id' => $account->id,
        'start_date' => '2026-11-02',
        'end_date' => '2026-11-13',
        'requested_days' => '9.50',
        'status' => 'pending',
    ]);
    $workScheduleAssignment = $employee->workScheduleAssignmentForDate('2026-11-02');
    $chargeableDate = CarbonImmutable::parse('2026-11-02');
    $chargeableDayIndex = 0;
    while ($chargeableDate->lte(CarbonImmutable::parse('2026-11-13'))) {
        if ($chargeableDate->isWeekday()) {
            $amount = $chargeableDayIndex === 9 ? '0.50' : '1.00';
            $existingPending->days()->create([
                'company_id' => $company->id,
                'employee_id' => $employee->id,
                'leave_date' => $chargeableDate->toDateString(),
                'day_type' => $amount === '0.50' ? LeaveRequestDayType::HalfDay : LeaveRequestDayType::FullDay,
                'requested_amount' => $amount,
                'work_schedule_id' => $workScheduleAssignment->work_schedule_id,
                'work_schedule_assignment_id' => $workScheduleAssignment->id,
            ]);
            $chargeableDayIndex++;
        }

        $chargeableDate = $chargeableDate->addDay();
    }

    $this->post(route('employee.leave.store'), employeeLeaveApplicationPayload($type))
        ->assertSessionHasErrors(['leave_type_id' => 'The available leave balance is insufficient after pending requests.']);

    expect(LeaveRequest::query()->count())->toBe(1)
        ->and($account->ledgerEntries()->count())->toBe(1);
});

test('employee request preserves configured negative balance allowance and limit', function () {
    [$company, $user, $employee] = employeeLeaveApplicationIdentity();
    [$type, $policy] = employeeLeaveApplicationConfiguration($company, $employee, ['balance' => '0.50']);
    $policy->update(['allow_negative_balance' => true, 'max_negative_balance_days' => '1.00']);

    $this->actingAs($user)->withSession(['active_company_id' => $company->id]);
    $this->post(route('employee.leave.store'), employeeLeaveApplicationPayload($type))
        ->assertRedirect(route('employee.leave.index'));
    $this->post(route('employee.leave.store'), employeeLeaveApplicationPayload($type, [
        'start_date' => '2026-10-12',
        'end_date' => '2026-10-12',
    ]))->assertSessionHasErrors('leave_type_id');

    expect(LeaveRequest::query()->where('employee_id', $employee->id)->where('status', 'pending')->count())->toBe(1);
});

test('employee may request half day and server day resolver discounts a holiday in a date range', function () {
    [$company, $user, $employee] = employeeLeaveApplicationIdentity();
    [$type, , , $account] = employeeLeaveApplicationConfiguration($company, $employee);
    $schedule = $employee->currentWorkScheduleAssignment()->workSchedule;
    $schedule->days()->where('day_of_week', 3)->update([
        'day_type' => WorkScheduleDayType::Working->value,
        'shift_id' => $company->shifts()->firstOrFail()->id,
    ]);
    $calendar = HolidayCalendar::factory()->create(['company_id' => $company->id, 'year' => 2026]);
    $company->employeeHolidayCalendarAssignments()->create([
        'employee_id' => $employee->id,
        'holiday_calendar_id' => $calendar->id,
        'effective_from' => '2026-01-01',
        'effective_to' => '2026-12-31',
        'status' => 'active',
    ]);
    $calendar->holidays()->create([
        'company_id' => $company->id,
        'date' => '2026-10-06',
        'name' => 'Company holiday',
        'type' => 'company',
        'status' => 'active',
    ]);

    $this->actingAs($user)->withSession(['active_company_id' => $company->id]);
    $this->post(route('employee.leave.store'), employeeLeaveApplicationPayload($type, [
        'start_date' => '2026-10-05',
        'end_date' => '2026-10-05',
        'duration' => 'half_day',
    ]))->assertRedirect(route('employee.leave.index'));

    $this->post(route('employee.leave.store'), employeeLeaveApplicationPayload($type, [
        'start_date' => '2026-10-06',
        'end_date' => '2026-10-07',
        'duration' => 'full_day',
    ]))->assertRedirect(route('employee.leave.index'));

    $requests = LeaveRequest::query()->with('days')->orderBy('id')->get();
    expect($requests)->toHaveCount(2)
        ->and($requests[0]->requested_days)->toBe('0.50')
        ->and($requests[0]->days->first()->requested_amount)->toBe('0.50')
        ->and($requests[1]->requested_days)->toBe('1.00')
        ->and($requests[1]->days->pluck('requested_amount')->all())->toBe(['0.00', '1.00'])
        ->and($account->ledgerEntries()->count())->toBe(1);
});

test('employee leave submission is denied when the self create permission is removed', function () {
    [$company, $user, $employee] = employeeLeaveApplicationIdentity();
    [$type] = employeeLeaveApplicationConfiguration($company, $employee);
    $registrar = app(PermissionRegistrar::class);
    $previousTeamId = $registrar->getPermissionsTeamId();
    $registrar->setPermissionsTeamId($company->id);
    Role::query()->where('name', 'employee')->firstOrFail()->revokePermissionTo('leave_request.self_create');
    $registrar->setPermissionsTeamId($previousTeamId);

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->get(route('employee.leave.apply'))->assertForbidden();
    $this->post(route('employee.leave.store'), employeeLeaveApplicationPayload($type))->assertForbidden();

    expect(LeaveRequest::query()->count())->toBe(0);
});

test('employee cannot submit when membership is inactive or employee role is removed', function () {
    [$company, $user, $employee] = employeeLeaveApplicationIdentity();
    [$type] = employeeLeaveApplicationConfiguration($company, $employee);
    CompanyUser::query()->where('company_id', $company->id)->where('user_id', $user->id)->update(['status' => 'inactive']);

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->get(route('employee.leave.apply'))->assertForbidden();
    $this->post(route('employee.leave.store'), employeeLeaveApplicationPayload($type))->assertForbidden();

    CompanyUser::query()->where('company_id', $company->id)->where('user_id', $user->id)->update(['status' => 'active']);
    $registrar = app(PermissionRegistrar::class);
    $previousTeamId = $registrar->getPermissionsTeamId();
    $registrar->setPermissionsTeamId($company->id);
    $user->removeRole('employee');
    $registrar->setPermissionsTeamId($previousTeamId);

    $this->get(route('employee.leave.apply'))->assertForbidden();
    $this->post(route('employee.leave.store'), employeeLeaveApplicationPayload($type))->assertForbidden();
});

test('same user submits only for the employee in the selected company', function () {
    [$companyA, $user, $employeeA] = employeeLeaveApplicationIdentity();
    employeeLeaveApplicationConfiguration($companyA, $employeeA);
    [$companyB, , $employeeB] = employeeLeaveApplicationIdentity($user);
    [$typeB] = employeeLeaveApplicationConfiguration($companyB, $employeeB, ['name' => 'Company B Leave']);

    $this->actingAs($user)->withSession(['active_company_id' => $companyB->id])
        ->get(route('employee.leave.apply'))
        ->assertInertia(fn (Assert $page) => $page->where('leaveTypes.0.name', 'Company B Leave')->has('leaveTypes', 1));
    $this->post(route('employee.leave.store'), employeeLeaveApplicationPayload($typeB))
        ->assertRedirect(route('employee.leave.index'));

    $request = LeaveRequest::query()->firstOrFail();
    expect($request->company_id)->toBe($companyB->id)
        ->and($request->employee_id)->toBe($employeeB->id)
        ->and($request->employee_id)->not->toBe($employeeA->id);
});
