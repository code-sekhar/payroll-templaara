<?php

use App\Enums\DayOfWeek;
use App\Enums\EmployeeWorkScheduleAssignmentStatus;
use App\Enums\WorkScheduleDayType;
use App\Exceptions\WorkSchedules\InvalidWorkScheduleConfigurationException;
use App\Exceptions\WorkSchedules\NoWorkScheduleAssignmentException;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeWorkScheduleAssignment;
use App\Models\Shift;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleDay;
use App\Services\WorkSchedules\WorkScheduleResolver;

function resolverFixture(?string $timezone = 'UTC'): array
{
    $company = Company::factory()->create(['timezone' => $timezone]);
    $employee = Employee::factory()->create(['company_id' => $company->id]);
    $schedule = WorkSchedule::factory()->create(['company_id' => $company->id]);

    return [$company, $employee, $schedule];
}

function resolverShift(Company $company, array $attributes = []): Shift
{
    return $company->shifts()->create(array_merge([
        'name' => 'General Shift',
        'code' => 'GEN-'.fake()->unique()->numerify('####'),
        'start_time' => '09:00',
        'end_time' => '18:00',
        'break_duration_minutes' => 60,
        'grace_period_minutes' => 0,
        'is_overnight' => false,
        'status' => 'active',
    ], $attributes));
}

function resolverAssignment(Employee $employee, WorkSchedule $schedule, string $from = '2026-10-01', ?string $to = null, EmployeeWorkScheduleAssignmentStatus $status = EmployeeWorkScheduleAssignmentStatus::Active): EmployeeWorkScheduleAssignment
{
    return EmployeeWorkScheduleAssignment::factory()->create([
        'company_id' => $employee->company_id,
        'employee_id' => $employee->id,
        'work_schedule_id' => $schedule->id,
        'effective_from' => $from,
        'effective_to' => $to,
        'status' => $status,
    ]);
}

function resolverSetDay(WorkSchedule $schedule, DayOfWeek $day, WorkScheduleDayType $type, ?Shift $shift = null): void
{
    WorkScheduleDay::query()
        ->where('work_schedule_id', $schedule->id)
        ->where('day_of_week', $day->value)
        ->update(['day_type' => $type->value, 'shift_id' => $shift?->id]);
}

test('resolves working off and half day configurations using shift business rules', function () {
    [$company, $employee, $schedule] = resolverFixture();
    $generalShift = resolverShift($company);
    $halfShift = resolverShift($company, [
        'name' => 'Half Day Shift',
        'start_time' => '09:00',
        'end_time' => '13:00',
        'break_duration_minutes' => 30,
    ]);
    resolverSetDay($schedule, DayOfWeek::Monday, WorkScheduleDayType::Working, $generalShift);
    resolverSetDay($schedule, DayOfWeek::Saturday, WorkScheduleDayType::HalfDay, $halfShift);
    $assignment = resolverAssignment($employee, $schedule);

    $resolver = app(WorkScheduleResolver::class);
    $working = $resolver->resolve($employee, '2026-10-05');
    $off = $resolver->resolve($employee, '2026-10-11');
    $halfDay = $resolver->resolve($employee, '2026-10-10');

    expect($working->dayOfWeek)->toBe(DayOfWeek::Monday)
        ->and($working->dayType)->toBe(WorkScheduleDayType::Working)
        ->and($working->shift->is($generalShift))->toBeTrue()
        ->and($working->expectedStart?->toDateTimeString())->toBe('2026-10-05 09:00:00')
        ->and($working->expectedEnd?->toDateTimeString())->toBe('2026-10-05 18:00:00')
        ->and($working->expectedWorkingMinutes)->toBe(480)
        ->and($off->dayOfWeek)->toBe(DayOfWeek::Sunday)
        ->and($off->dayType)->toBe(WorkScheduleDayType::Off)
        ->and($off->shift)->toBeNull()
        ->and($off->expectedStart)->toBeNull()
        ->and($off->expectedEnd)->toBeNull()
        ->and($off->expectedWorkingMinutes)->toBe(0)
        ->and($halfDay->dayType)->toBe(WorkScheduleDayType::HalfDay)
        ->and($halfDay->shift->is($halfShift))->toBeTrue()
        ->and($halfDay->expectedWorkingMinutes)->toBe(210);
});

test('resolves overnight shifts on the next local calendar day', function () {
    [$company, $employee, $schedule] = resolverFixture('Asia/Kolkata');
    $shift = resolverShift($company, [
        'start_time' => '22:00',
        'end_time' => '06:00',
        'break_duration_minutes' => 30,
        'is_overnight' => true,
    ]);
    resolverSetDay($schedule, DayOfWeek::Monday, WorkScheduleDayType::Working, $shift);
    $assignment = resolverAssignment($employee, $schedule);

    $result = app(WorkScheduleResolver::class)->resolve($employee, new DateTimeImmutable('2026-10-04T19:00:00+00:00'));

    expect($result->date->toDateString())->toBe('2026-10-05')
        ->and($result->dayOfWeek)->toBe(DayOfWeek::Monday)
        ->and($result->expectedStart?->toDateTimeString())->toBe('2026-10-05 22:00:00')
        ->and($result->expectedEnd?->toDateTimeString())->toBe('2026-10-06 06:00:00')
        ->and($result->expectedEnd->greaterThan($result->expectedStart))->toBeTrue()
        ->and($result->expectedWorkingMinutes)->toBe(450)
        ->and($result->expectedStart->timezoneName)->toBe('Asia/Kolkata');
});

test('selects effective assignment for historical current and future dates with inclusive boundaries', function () {
    [$company, $employee, $historicalSchedule] = resolverFixture();
    $currentSchedule = WorkSchedule::factory()->create(['company_id' => $company->id]);
    $futureSchedule = WorkSchedule::factory()->create(['company_id' => $company->id]);
    resolverAssignment($employee, $historicalSchedule, '2026-01-01', '2026-03-31');
    resolverAssignment($employee, $currentSchedule, '2026-04-01', '2026-10-31');
    resolverAssignment($employee, $futureSchedule, '2026-11-01');

    $resolver = app(WorkScheduleResolver::class);

    expect($resolver->resolve($employee, '2026-03-31')->workSchedule->is($historicalSchedule))->toBeTrue()
        ->and($resolver->resolve($employee, '2026-04-01')->workSchedule->is($currentSchedule))->toBeTrue()
        ->and($resolver->resolve($employee, '2026-10-31')->workSchedule->is($currentSchedule))->toBeTrue()
        ->and($resolver->resolve($employee, '2026-11-01')->workSchedule->is($futureSchedule))->toBeTrue();
});

test('does not fall back when no active assignment covers the date', function () {
    [, $employee, $schedule] = resolverFixture();
    resolverAssignment($employee, $schedule, '2026-10-02', '2026-10-31');

    try {
        app(WorkScheduleResolver::class)->resolve($employee, '2026-10-01');
        $this->fail('Expected no schedule assignment exception.');
    } catch (NoWorkScheduleAssignmentException $exception) {
        expect($exception->reason())->toBe('NO_SCHEDULE_ASSIGNED')
            ->and($exception->employeeId)->toBe($employee->id)
            ->and($exception->date->toDateString())->toBe('2026-10-01');
    }
});

test('ignores inactive assignments and never writes during resolution', function () {
    [$company, $employee, $schedule] = resolverFixture();
    resolverAssignment($employee, $schedule, '2026-10-01', null, EmployeeWorkScheduleAssignmentStatus::Inactive);

    expect(fn () => app(WorkScheduleResolver::class)->resolve($employee, '2026-10-05'))
        ->toThrow(NoWorkScheduleAssignmentException::class);

    $otherSchedule = WorkSchedule::factory()->create(['company_id' => $company->id]);
    $shift = resolverShift($company);
    resolverSetDay($otherSchedule, DayOfWeek::Monday, WorkScheduleDayType::Working, $shift);
    $assignment = resolverAssignment($employee, $otherSchedule);
    $before = [
        EmployeeWorkScheduleAssignment::query()->count(),
        WorkSchedule::query()->count(),
        WorkScheduleDay::query()->count(),
        Shift::query()->count(),
        $assignment->updated_at?->toISOString(),
    ];

    app(WorkScheduleResolver::class)->resolve($employee, '2026-10-05');

    expect([
        EmployeeWorkScheduleAssignment::query()->count(),
        WorkSchedule::query()->count(),
        WorkScheduleDay::query()->count(),
        Shift::query()->count(),
        $assignment->fresh()->updated_at?->toISOString(),
    ])->toBe($before);
});

test('fails explicitly for incomplete or invalid schedule configuration', function () {
    [$company, $employee, $schedule] = resolverFixture();
    $assignment = resolverAssignment($employee, $schedule);
    WorkScheduleDay::query()->where('work_schedule_id', $schedule->id)->where('day_of_week', DayOfWeek::Monday->value)->delete();

    try {
        app(WorkScheduleResolver::class)->resolve($employee, '2026-10-05');
        $this->fail('Expected invalid schedule configuration exception.');
    } catch (InvalidWorkScheduleConfigurationException $exception) {
        expect($exception->reason)->toBe('schedule_day_missing');
    }
    $assignment->delete();

    $anotherSchedule = WorkSchedule::factory()->create(['company_id' => $company->id]);
    WorkScheduleDay::query()
        ->where('work_schedule_id', $anotherSchedule->id)
        ->where('day_of_week', DayOfWeek::Monday->value)
        ->update(['day_type' => WorkScheduleDayType::Working->value, 'shift_id' => null]);
    resolverAssignment($employee, $anotherSchedule);

    try {
        app(WorkScheduleResolver::class)->resolve($employee, '2026-10-05');
        $this->fail('Expected invalid schedule configuration exception.');
    } catch (InvalidWorkScheduleConfigurationException $exception) {
        expect($exception->reason)->toBe('working_day_shift_missing');
    }
});

test('rejects malformed dates and archived schedules explicitly', function () {
    [, $employee, $schedule] = resolverFixture();
    resolverAssignment($employee, $schedule);

    expect(fn () => app(WorkScheduleResolver::class)->resolve($employee, 'not-a-date'))
        ->toThrow(InvalidArgumentException::class);

    $schedule->delete();

    try {
        app(WorkScheduleResolver::class)->resolve($employee, '2026-10-05');
        $this->fail('Expected archived schedule configuration exception.');
    } catch (InvalidWorkScheduleConfigurationException $exception) {
        expect($exception->reason)->toBe('schedule_missing_or_archived');
    }
});
