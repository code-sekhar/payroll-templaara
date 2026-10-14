<?php

use App\Enums\AttendancePolicyStatus;
use App\Enums\EmployeeAttendancePolicyAssignmentStatus;
use App\Exceptions\Attendance\InvalidAttendancePolicyConfigurationException;
use App\Exceptions\Attendance\NoAttendancePolicyAssignmentException;
use App\Models\AttendancePolicy;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeAttendancePolicyAssignment;
use App\Services\Attendance\AttendancePolicyResolver;
use App\Services\Attendance\ResolvedAttendancePolicy;
use Carbon\CarbonImmutable;

/** @return array{Company, Employee, AttendancePolicy} */
function attendancePolicyResolutionFixture(string $timezone = 'UTC', array $policyAttributes = []): array
{
    $company = Company::factory()->create(['timezone' => $timezone]);
    $employee = Employee::factory()->create(['company_id' => $company->id]);
    $policy = AttendancePolicy::factory()->create(array_merge([
        'company_id' => $company->id,
        'name' => 'Standard Attendance',
        'code' => 'STD-ATT',
        'status' => AttendancePolicyStatus::Active,
        'grace_in_minutes' => 5,
        'grace_out_minutes' => 6,
        'late_after_minutes' => 15,
        'early_leave_after_minutes' => 20,
        'half_day_before_minutes' => 240,
        'absent_before_minutes' => 480,
        'overtime_enabled' => true,
        'overtime_after_minutes' => 30,
    ], $policyAttributes));

    return [$company, $employee, $policy];
}

function assignAttendancePolicyForResolution(
    Employee $employee,
    AttendancePolicy $policy,
    string $effectiveFrom = '2026-01-01',
    ?string $effectiveTo = null,
    EmployeeAttendancePolicyAssignmentStatus $status = EmployeeAttendancePolicyAssignmentStatus::Active,
): EmployeeAttendancePolicyAssignment {
    return EmployeeAttendancePolicyAssignment::factory()->create([
        'company_id' => $employee->company_id,
        'employee_id' => $employee->id,
        'attendance_policy_id' => $policy->id,
        'effective_from' => $effectiveFrom,
        'effective_to' => $effectiveTo,
        'status' => $status,
    ]);
}

test('resolves the effective assignment and exposes all attendance policy values', function () {
    [, $employee, $policy] = attendancePolicyResolutionFixture();
    $assignment = assignAttendancePolicyForResolution($employee, $policy, '2026-10-01', '2026-10-31');

    $resolved = app(AttendancePolicyResolver::class)->resolve($employee, CarbonImmutable::parse('2026-10-15'));

    expect($resolved)->toBeInstanceOf(ResolvedAttendancePolicy::class)
        ->and($resolved->employee()->is($employee))->toBeTrue()
        ->and($resolved->date()->toDateString())->toBe('2026-10-15')
        ->and($resolved->assignment()->is($assignment))->toBeTrue()
        ->and($resolved->policy()->is($policy))->toBeTrue()
        ->and($resolved->graceInMinutes())->toBe(5)
        ->and($resolved->graceOutMinutes())->toBe(6)
        ->and($resolved->lateAfterMinutes())->toBe(15)
        ->and($resolved->earlyLeaveAfterMinutes())->toBe(20)
        ->and($resolved->halfDayBeforeMinutes())->toBe(240)
        ->and($resolved->absentBeforeMinutes())->toBe(480)
        ->and($resolved->overtimeEnabled())->toBeTrue()
        ->and($resolved->overtimeAfterMinutes())->toBe(30);
});

test('resolves bounded assignments inclusively and adjacent policy periods on their respective dates', function () {
    [, $employee, $firstPolicy] = attendancePolicyResolutionFixture();
    $secondPolicy = AttendancePolicy::factory()->create([
        'company_id' => $employee->company_id,
        'name' => 'Later Policy',
        'code' => 'LATER',
    ]);
    $firstAssignment = assignAttendancePolicyForResolution($employee, $firstPolicy, '2026-01-01', '2026-06-30');
    $secondAssignment = assignAttendancePolicyForResolution($employee, $secondPolicy, '2026-07-01', '2026-12-31');

    $resolver = app(AttendancePolicyResolver::class);

    expect($resolver->resolve($employee, CarbonImmutable::parse('2026-01-01'))->assignment()->is($firstAssignment))->toBeTrue()
        ->and($resolver->resolve($employee, CarbonImmutable::parse('2026-06-30'))->policy()->is($firstPolicy))->toBeTrue()
        ->and($resolver->resolve($employee, CarbonImmutable::parse('2026-07-01'))->assignment()->is($secondAssignment))->toBeTrue()
        ->and($resolver->resolve($employee, CarbonImmutable::parse('2026-12-31'))->policy()->is($secondPolicy))->toBeTrue();
});

test('resolves an open ended assignment for a future date', function () {
    [, $employee, $policy] = attendancePolicyResolutionFixture();
    $assignment = assignAttendancePolicyForResolution($employee, $policy, '2026-10-01');

    $resolved = app(AttendancePolicyResolver::class)->resolve($employee, CarbonImmutable::parse('2030-04-20'));

    expect($resolved->assignment()->is($assignment))->toBeTrue()
        ->and($resolved->policy()->is($policy))->toBeTrue();
});

test('throws a machine readable exception when no active assignment covers the date', function () {
    [, $employee, $policy] = attendancePolicyResolutionFixture();
    assignAttendancePolicyForResolution($employee, $policy, '2026-10-02', '2026-10-31');

    try {
        app(AttendancePolicyResolver::class)->resolve($employee, CarbonImmutable::parse('2026-10-01'));
        $this->fail('Expected no attendance policy assignment exception.');
    } catch (NoAttendancePolicyAssignmentException $exception) {
        expect($exception->reason())->toBe('NO_ATTENDANCE_POLICY_ASSIGNED')
            ->and($exception->employeeId)->toBe($employee->id)
            ->and($exception->date->toDateString())->toBe('2026-10-01');
    }
});

test('throws when the date is after a bounded assignment or the employee has no assignments', function () {
    [, $employee, $policy] = attendancePolicyResolutionFixture();
    assignAttendancePolicyForResolution($employee, $policy, '2026-01-01', '2026-06-30');

    expect(fn () => app(AttendancePolicyResolver::class)->resolve($employee, CarbonImmutable::parse('2026-07-01')))
        ->toThrow(NoAttendancePolicyAssignmentException::class);

    $unassignedEmployee = Employee::factory()->create(['company_id' => $employee->company_id]);
    expect(fn () => app(AttendancePolicyResolver::class)->resolve($unassignedEmployee, CarbonImmutable::parse('2026-07-01')))
        ->toThrow(NoAttendancePolicyAssignmentException::class);
});

test('inactive and soft deleted assignments do not resolve', function () {
    [, $employee, $policy] = attendancePolicyResolutionFixture();
    assignAttendancePolicyForResolution($employee, $policy, '2026-01-01', null, EmployeeAttendancePolicyAssignmentStatus::Inactive);

    expect(fn () => app(AttendancePolicyResolver::class)->resolve($employee, CarbonImmutable::parse('2026-06-01')))
        ->toThrow(NoAttendancePolicyAssignmentException::class);

    $deletedAssignment = assignAttendancePolicyForResolution($employee, $policy, '2026-01-01');
    $deletedAssignment->delete();

    expect(fn () => app(AttendancePolicyResolver::class)->resolve($employee, CarbonImmutable::parse('2026-06-01')))
        ->toThrow(NoAttendancePolicyAssignmentException::class);
});

test('resolves an assigned policy after its status changes to inactive', function () {
    [, $employee, $policy] = attendancePolicyResolutionFixture();
    $assignment = assignAttendancePolicyForResolution($employee, $policy);
    $policy->update(['status' => AttendancePolicyStatus::Inactive]);

    $resolved = app(AttendancePolicyResolver::class)->resolve($employee, CarbonImmutable::parse('2026-10-05'));

    expect($resolved->assignment()->is($assignment))->toBeTrue()
        ->and($resolved->policy()->is($policy))->toBeTrue()
        ->and($resolved->policy()->status)->toBe(AttendancePolicyStatus::Inactive);
});

test('rejects a soft deleted policy referenced by an existing assignment', function () {
    [, $employee, $policy] = attendancePolicyResolutionFixture();
    assignAttendancePolicyForResolution($employee, $policy);
    $policy->delete();

    try {
        app(AttendancePolicyResolver::class)->resolve($employee, CarbonImmutable::parse('2026-10-05'));
        $this->fail('Expected invalid attendance policy configuration exception.');
    } catch (InvalidAttendancePolicyConfigurationException $exception) {
        expect($exception->code())->toBe('INVALID_ATTENDANCE_POLICY_CONFIGURATION')
            ->and($exception->reason)->toBe('attendance_policy_missing_or_deleted');
    }
});

test('rejects negative or overday policy minute configuration', function (string $field, int $value) {
    [, $employee, $policy] = attendancePolicyResolutionFixture();
    assignAttendancePolicyForResolution($employee, $policy);
    $policy->forceFill([$field => $value])->save();

    expect(fn () => app(AttendancePolicyResolver::class)->resolve($employee, CarbonImmutable::parse('2026-10-05')))
        ->toThrow(InvalidAttendancePolicyConfigurationException::class);
})->with([
    'negative grace-in' => ['grace_in_minutes', -1],
    'excessive overtime threshold' => ['overtime_after_minutes', 1441],
]);

test('rejects grace thresholds that exceed their related policy thresholds', function (array $attributes, string $reason) {
    [, $employee, $policy] = attendancePolicyResolutionFixture();
    assignAttendancePolicyForResolution($employee, $policy);
    $policy->forceFill($attributes)->save();

    try {
        app(AttendancePolicyResolver::class)->resolve($employee, CarbonImmutable::parse('2026-10-05'));
        $this->fail('Expected invalid attendance policy configuration exception.');
    } catch (InvalidAttendancePolicyConfigurationException $exception) {
        expect($exception->reason)->toBe($reason);
    }
})->with([
    'grace-in exceeds late threshold' => [['grace_in_minutes' => 16], 'grace_in_exceeds_late_after'],
    'grace-out exceeds early-leave threshold' => [['grace_out_minutes' => 21], 'grace_out_exceeds_early_leave_after'],
]);

test('preserves the supplied calendar date without timezone conversion', function () {
    [, $employee, $policy] = attendancePolicyResolutionFixture('Asia/Kolkata');
    $assignment = assignAttendancePolicyForResolution($employee, $policy, '2026-10-05', '2026-10-05');
    $inputDate = CarbonImmutable::parse('2026-10-05T23:30:00+00:00');

    $resolved = app(AttendancePolicyResolver::class)->resolve($employee, $inputDate);

    expect($resolved->date()->toDateString())->toBe('2026-10-05')
        ->and($resolved->date()->timezoneName)->toBe('Asia/Kolkata')
        ->and($resolved->assignment()->is($assignment))->toBeTrue();
});

test('rejects a forged employee company identity instead of resolving across tenants', function () {
    [, $employee, $policy] = attendancePolicyResolutionFixture();
    assignAttendancePolicyForResolution($employee, $policy);
    $employee->company_id = Company::factory()->create()->id;

    expect(fn () => app(AttendancePolicyResolver::class)->resolve($employee, CarbonImmutable::parse('2026-10-05')))
        ->toThrow(InvalidArgumentException::class, 'employee must exist and belong to a company');
});

test('rejects assignment company corruption even though the database composite key prevents storing it', function () {
    [, $employee, $policy] = attendancePolicyResolutionFixture();
    $assignment = assignAttendancePolicyForResolution($employee, $policy);
    $foreignCompany = Company::factory()->create();

    EmployeeAttendancePolicyAssignment::retrieved(function (EmployeeAttendancePolicyAssignment $retrieved) use ($assignment, $foreignCompany): void {
        if ($retrieved->is($assignment)) {
            $retrieved->company_id = $foreignCompany->id;
        }
    });

    try {
        app(AttendancePolicyResolver::class)->resolve($employee, CarbonImmutable::parse('2026-10-05'));
        $this->fail('Expected invalid attendance policy configuration exception.');
    } catch (InvalidAttendancePolicyConfigurationException $exception) {
        expect($exception->reason)->toBe('assignment_company_mismatch');
    }
});

test('rejects policy company corruption even though the database composite key prevents storing it', function () {
    [, $employee, $policy] = attendancePolicyResolutionFixture();
    assignAttendancePolicyForResolution($employee, $policy);
    $foreignCompany = Company::factory()->create();

    AttendancePolicy::retrieved(function (AttendancePolicy $retrieved) use ($policy, $foreignCompany): void {
        if ($retrieved->is($policy)) {
            $retrieved->company_id = $foreignCompany->id;
        }
    });

    try {
        app(AttendancePolicyResolver::class)->resolve($employee, CarbonImmutable::parse('2026-10-05'));
        $this->fail('Expected invalid attendance policy configuration exception.');
    } catch (InvalidAttendancePolicyConfigurationException $exception) {
        expect($exception->reason)->toBe('attendance_policy_company_mismatch');
    }
});

test('resolution is deterministic read only and returns an immutable result', function () {
    [, $employee, $policy] = attendancePolicyResolutionFixture();
    $assignment = assignAttendancePolicyForResolution($employee, $policy);
    $before = [
        EmployeeAttendancePolicyAssignment::query()->count(),
        AttendancePolicy::withTrashed()->count(),
        $assignment->updated_at?->toISOString(),
        $policy->updated_at?->toISOString(),
    ];
    $resolver = app(AttendancePolicyResolver::class);

    $first = $resolver->resolve($employee, CarbonImmutable::parse('2026-10-05'));
    $second = $resolver->resolve($employee, CarbonImmutable::parse('2026-10-05'));
    $after = [
        EmployeeAttendancePolicyAssignment::query()->count(),
        AttendancePolicy::withTrashed()->count(),
        $assignment->fresh()->updated_at?->toISOString(),
        $policy->fresh()->updated_at?->toISOString(),
    ];

    expect($first->assignment()->is($second->assignment()))->toBeTrue()
        ->and($first->policy()->is($second->policy()))->toBeTrue()
        ->and($after)->toBe($before)
        ->and((new ReflectionClass(ResolvedAttendancePolicy::class))->isReadOnly())->toBeTrue();
});
