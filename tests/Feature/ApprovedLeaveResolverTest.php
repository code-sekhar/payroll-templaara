<?php

use App\Enums\LeaveRequestDayType;
use App\Enums\LeaveRequestStatus;
use App\Exceptions\Leave\InvalidApprovedLeaveConfigurationException;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeLeaveBalanceAccount;
use App\Models\EmployeeLeavePolicyAssignment;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestDay;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Leave\ApprovedLeaveResolver;
use Carbon\CarbonImmutable;

/** @return array{Company, Employee, LeaveRequest, LeaveRequestDay, LeaveType, LeavePolicy, EmployeeLeaveBalanceAccount} */
function approvedLeaveResolverFixture(
    LeaveRequestStatus $status = LeaveRequestStatus::Approved,
    string $amount = '1.00',
    LeaveRequestDayType $dayType = LeaveRequestDayType::FullDay,
    string $date = '2026-10-05',
    bool $paid = true,
): array {
    $company = Company::factory()->create(['timezone' => 'Asia/Kolkata']);
    $employee = Employee::factory()->create(['company_id' => $company->id]);
    $leaveType = LeaveType::factory()->create(['company_id' => $company->id, 'is_paid' => $paid]);
    $leavePolicy = LeavePolicy::factory()->create(['company_id' => $company->id, 'leave_type_id' => $leaveType->id]);
    $assignment = EmployeeLeavePolicyAssignment::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'leave_policy_id' => $leavePolicy->id,
        'effective_from' => '2026-01-01',
    ]);
    $account = EmployeeLeaveBalanceAccount::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'leave_policy_id' => $leavePolicy->id,
        'employee_leave_policy_assignment_id' => $assignment->id,
        'period_start' => '2026-01-01',
        'period_end' => '2026-12-31',
    ]);
    $requester = User::factory()->create();
    $reviewer = in_array($status, [LeaveRequestStatus::Approved, LeaveRequestStatus::Rejected], true)
        ? User::factory()->create()
        : null;
    $request = LeaveRequest::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'leave_policy_id' => $leavePolicy->id,
        'employee_leave_policy_assignment_id' => $assignment->id,
        'leave_balance_account_id' => $account->id,
        'start_date' => $date,
        'end_date' => $date,
        'requested_days' => $amount === '0.00' ? '1.00' : $amount,
        'status' => $status,
        'requested_by' => $requester->id,
        'reviewed_by' => $reviewer?->id,
        'reviewed_at' => $reviewer ? now() : null,
        'review_note' => $status === LeaveRequestStatus::Rejected ? 'Not approved' : null,
        'cancelled_by' => $status === LeaveRequestStatus::Cancelled ? User::factory()->create()->id : null,
        'cancelled_at' => $status === LeaveRequestStatus::Cancelled ? now() : null,
        'cancellation_reason' => $status === LeaveRequestStatus::Cancelled ? 'Cancelled by employee' : null,
    ]);
    $day = LeaveRequestDay::query()->create([
        'company_id' => $company->id,
        'leave_request_id' => $request->id,
        'employee_id' => $employee->id,
        'leave_date' => $date,
        'day_type' => $dayType,
        'requested_amount' => $amount,
    ]);

    return [$company, $employee, $request, $day, $leaveType, $leavePolicy, $account];
}

test('no matching leave resolves to an explicit empty result', function () {
    $employee = Employee::factory()->create();

    $result = app(ApprovedLeaveResolver::class)->resolve($employee, CarbonImmutable::parse('2026-10-05'));

    expect($result->hasApprovedLeave())->toBeFalse()
        ->and($result->requestedAmount)->toBe('0.00')
        ->and($result->leaveRequest)->toBeNull()
        ->and($result->leaveRequestDay)->toBeNull();
});

test('only approved requests with chargeable day snapshots resolve', function (LeaveRequestStatus $status) {
    [, $employee] = approvedLeaveResolverFixture($status);

    $result = app(ApprovedLeaveResolver::class)->resolve($employee, CarbonImmutable::parse('2026-10-05'));

    expect($result->hasApprovedLeave())->toBeFalse();
})->with([
    'pending request' => LeaveRequestStatus::Pending,
    'rejected request' => LeaveRequestStatus::Rejected,
    'cancelled request' => LeaveRequestStatus::Cancelled,
]);

test('approved full-day and half-day requests resolve their exact references and decimal amount', function (string $amount, LeaveRequestDayType $dayType) {
    [, $employee, $request, $day, $type, $policy, $account] = approvedLeaveResolverFixture(
        amount: $amount,
        dayType: $dayType,
        paid: false,
    );

    $result = app(ApprovedLeaveResolver::class)->resolve($employee, CarbonImmutable::parse('2026-10-05 23:30:00', 'Asia/Kolkata'));

    expect($result->hasApprovedLeave())->toBeTrue()
        ->and($result->date->toDateString())->toBe('2026-10-05')
        ->and($result->leaveRequest?->is($request))->toBeTrue()
        ->and($result->leaveRequestDay?->is($day))->toBeTrue()
        ->and($result->leaveType?->is($type))->toBeTrue()
        ->and($result->leavePolicy?->is($policy))->toBeTrue()
        ->and($result->balanceAccount?->is($account))->toBeTrue()
        ->and($result->requestedAmount)->toBe($amount)
        ->and($result->isPaid())->toBeFalse();
})->with([
    'full day' => ['1.00', LeaveRequestDayType::FullDay],
    'half day' => ['0.50', LeaveRequestDayType::HalfDay],
]);

test('approved zero-charge holiday and off-day snapshots are ignored', function (LeaveRequestDayType $dayType) {
    [, $employee] = approvedLeaveResolverFixture(amount: '0.00', dayType: $dayType);

    $result = app(ApprovedLeaveResolver::class)->resolve($employee, CarbonImmutable::parse('2026-10-05'));

    expect($result->hasApprovedLeave())->toBeFalse()
        ->and($result->requestedAmount)->toBe('0.00');
})->with([
    'holiday' => LeaveRequestDayType::Holiday,
    'off day' => LeaveRequestDayType::OffDay,
]);

test('leave resolution is isolated by company and employee and matches an exact date', function () {
    [, $employee] = approvedLeaveResolverFixture();
    $otherEmployee = Employee::factory()->create(['company_id' => $employee->company_id]);
    $otherCompanyEmployee = Employee::factory()->create();

    $resolver = app(ApprovedLeaveResolver::class);

    expect($resolver->resolve($employee, CarbonImmutable::parse('2026-10-06'))->hasApprovedLeave())->toBeFalse()
        ->and($resolver->resolve($otherEmployee, CarbonImmutable::parse('2026-10-05'))->hasApprovedLeave())->toBeFalse()
        ->and($resolver->resolve($otherCompanyEmployee, CarbonImmutable::parse('2026-10-05'))->hasApprovedLeave())->toBeFalse();
});

test('multiple approved chargeable rows fail with an explicit configuration reason', function () {
    [, $employee, $request, $day] = approvedLeaveResolverFixture();
    $secondRequest = LeaveRequest::query()->create([
        'company_id' => $request->company_id,
        'employee_id' => $request->employee_id,
        'leave_type_id' => $request->leave_type_id,
        'leave_policy_id' => $request->leave_policy_id,
        'employee_leave_policy_assignment_id' => $request->employee_leave_policy_assignment_id,
        'leave_balance_account_id' => $request->leave_balance_account_id,
        'start_date' => $request->start_date->toDateString(),
        'end_date' => $request->end_date->toDateString(),
        'requested_days' => '1.00',
        'duration' => 'full_day',
        'status' => LeaveRequestStatus::Approved,
        'reason' => 'Imported duplicate for corruption test',
        'requested_by' => $request->requested_by,
        'requested_at' => now(),
        'reviewed_by' => $request->reviewed_by,
        'reviewed_at' => now(),
    ]);
    LeaveRequestDay::query()->create([
        'company_id' => $day->company_id,
        'leave_request_id' => $secondRequest->id,
        'employee_id' => $employee->id,
        'leave_date' => $day->leave_date->toDateString(),
        'day_type' => LeaveRequestDayType::FullDay,
        'requested_amount' => '1.00',
    ]);

    try {
        app(ApprovedLeaveResolver::class)->resolve($employee, CarbonImmutable::parse('2026-10-05'));
        test()->fail('Expected corrupt overlapping approved leave to fail resolution.');
    } catch (InvalidApprovedLeaveConfigurationException $exception) {
        expect($exception->reason)->toBe('MULTIPLE_APPROVED_LEAVES_FOR_DATE')
            ->and($exception->employeeId)->toBe($employee->id)
            ->and($exception->date->toDateString())->toBe('2026-10-05');
    }
});

test('paid leave type context is exposed without using the leave ledger', function () {
    [, $employee, $request, $day, $type, , $account] = approvedLeaveResolverFixture();

    $result = app(ApprovedLeaveResolver::class)->resolve($employee, CarbonImmutable::parse('2026-10-05'));

    expect($result->hasApprovedLeave())->toBeTrue()
        ->and($result->leaveRequest?->is($request))->toBeTrue()
        ->and($result->leaveRequestDay?->is($day))->toBeTrue()
        ->and($result->leaveType?->is($type))->toBeTrue()
        ->and($result->isPaid())->toBeTrue()
        ->and($account->ledgerEntries()->count())->toBe(0);
});
