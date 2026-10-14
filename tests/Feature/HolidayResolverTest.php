<?php

use App\Enums\EmployeeHolidayCalendarAssignmentStatus;
use App\Enums\HolidayCalendarStatus;
use App\Enums\HolidayStatus;
use App\Enums\HolidayType;
use App\Exceptions\Holidays\InvalidHolidayCalendarConfigurationException;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeHolidayCalendarAssignment;
use App\Models\Holiday;
use App\Models\HolidayCalendar;
use App\Services\Holidays\HolidayResolver;
use App\Services\Holidays\ResolvedHoliday;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** @return array{Company, Employee, HolidayCalendar, EmployeeHolidayCalendarAssignment} */
function holidayResolverFixture(string $timezone = 'UTC', int $year = 2026): array
{
    $company = Company::factory()->create(['timezone' => $timezone]);
    $employee = Employee::factory()->create(['company_id' => $company->id]);
    $calendar = HolidayCalendar::factory()->create([
        'company_id' => $company->id,
        'year' => $year,
        'status' => HolidayCalendarStatus::Active,
    ]);
    $assignment = EmployeeHolidayCalendarAssignment::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'holiday_calendar_id' => $calendar->id,
        'effective_from' => sprintf('%d-01-01', $year),
        'effective_to' => sprintf('%d-12-31', $year),
        'status' => EmployeeHolidayCalendarAssignmentStatus::Active,
    ]);

    return [$company, $employee, $calendar, $assignment];
}

function holidayResolverHoliday(HolidayCalendar $calendar, array $attributes = []): Holiday
{
    return Holiday::factory()->create(array_merge([
        'company_id' => $calendar->company_id,
        'holiday_calendar_id' => $calendar->id,
        'date' => '2026-10-05',
        'name' => 'Founders Day',
        'type' => HolidayType::Company,
        'status' => HolidayStatus::Active,
        'is_optional' => false,
    ], $attributes));
}

test('active holiday resolves calendar assignment holiday type and optional details', function () {
    [, $employee, $calendar, $assignment] = holidayResolverFixture();
    $holiday = holidayResolverHoliday($calendar, [
        'type' => HolidayType::Festival,
        'is_optional' => true,
        'description' => 'Annual festival day',
    ]);

    $resolved = app(HolidayResolver::class)->resolve($employee, CarbonImmutable::parse('2026-10-05T23:00:00+00:00'));

    expect($resolved)->toBeInstanceOf(ResolvedHoliday::class)
        ->and($resolved->employee->is($employee))->toBeTrue()
        ->and($resolved->date->toDateString())->toBe('2026-10-05')
        ->and($resolved->assignment?->is($assignment))->toBeTrue()
        ->and($resolved->calendar?->is($calendar))->toBeTrue()
        ->and($resolved->holiday?->is($holiday))->toBeTrue()
        ->and($resolved->isHoliday())->toBeTrue()
        ->and($resolved->isOptional())->toBeTrue()
        ->and($resolved->holidayType())->toBe(HolidayType::Festival)
        ->and($resolved->holidayStatus())->toBe(HolidayStatus::Active)
        ->and($resolved->holidayName())->toBe('Founders Day')
        ->and($resolved->holidayDescription())->toBe('Annual festival day');
});

test('a date without an active holiday returns its applicable calendar as a non-holiday', function () {
    [, $employee, $calendar, $assignment] = holidayResolverFixture();

    $resolved = app(HolidayResolver::class)->resolve($employee, '2026-10-06');

    expect($resolved->isHoliday())->toBeFalse()
        ->and($resolved->holiday)->toBeNull()
        ->and($resolved->calendar?->is($calendar))->toBeTrue()
        ->and($resolved->assignment?->is($assignment))->toBeTrue()
        ->and($resolved->holidayType())->toBeNull()
        ->and($resolved->isOptional())->toBeFalse();
});

test('no applicable calendar assignment is an explicit non-holiday result without fallback', function () {
    [, $employee] = holidayResolverFixture();
    $employee->employeeHolidayCalendarAssignments()->delete();

    $resolved = app(HolidayResolver::class)->resolve($employee, '2026-10-05');

    expect($resolved->isHoliday())->toBeFalse()
        ->and($resolved->calendar)->toBeNull()
        ->and($resolved->assignment)->toBeNull()
        ->and($resolved->holiday)->toBeNull();
});

test('inactive and soft deleted holidays do not resolve as holidays', function () {
    [, $employee, $calendar] = holidayResolverFixture();
    holidayResolverHoliday($calendar, ['status' => HolidayStatus::Inactive]);
    $deleted = holidayResolverHoliday($calendar, ['date' => '2026-10-06']);
    $deleted->delete();

    $resolver = app(HolidayResolver::class);
    $inactive = $resolver->resolve($employee, '2026-10-05');
    $softDeleted = $resolver->resolve($employee, '2026-10-06');

    expect($inactive->isHoliday())->toBeFalse()
        ->and($inactive->holiday)->toBeNull()
        ->and($softDeleted->isHoliday())->toBeFalse()
        ->and($softDeleted->holiday)->toBeNull();
});

test('inactive or archived calendar assignments fail explicitly', function () {
    [, $employee, $calendar] = holidayResolverFixture();
    holidayResolverHoliday($calendar);
    $calendar->update(['status' => HolidayCalendarStatus::Inactive]);

    try {
        app(HolidayResolver::class)->resolve($employee, '2026-10-05');
        $this->fail('Expected inactive calendar configuration exception.');
    } catch (InvalidHolidayCalendarConfigurationException $exception) {
        expect($exception->reason)->toBe('calendar_inactive');
    }

    $calendar->update(['status' => HolidayCalendarStatus::Active]);
    $calendar->delete();

    try {
        app(HolidayResolver::class)->resolve($employee, '2026-10-05');
        $this->fail('Expected archived calendar configuration exception.');
    } catch (InvalidHolidayCalendarConfigurationException $exception) {
        expect($exception->reason)->toBe('calendar_missing_or_archived');
    }
});

test('calendar year must match the requested business date year', function () {
    [, $employee, , $assignment] = holidayResolverFixture(year: 2026);
    $assignment->update(['effective_to' => null]);

    try {
        app(HolidayResolver::class)->resolve($employee, '2027-01-01');
        $this->fail('Expected calendar year mismatch exception.');
    } catch (InvalidHolidayCalendarConfigurationException $exception) {
        expect($exception->reason)->toBe('calendar_year_mismatch')
            ->and($exception->date->toDateString())->toBe('2027-01-01');
    }
});

test('date-only resolution preserves the input calendar date without timezone conversion', function () {
    [, $employee, $calendar] = holidayResolverFixture('Asia/Kolkata');
    $holiday = holidayResolverHoliday($calendar);

    $resolved = app(HolidayResolver::class)->resolve($employee, new DateTimeImmutable('2026-10-05T23:30:00+00:00'));

    expect($resolved->date->toDateString())->toBe('2026-10-05')
        ->and($resolved->isHoliday())->toBeTrue()
        ->and($resolved->holiday?->is($holiday))->toBeTrue();
});

test('a forged employee company identity cannot resolve another company calendar', function () {
    [, $employee] = holidayResolverFixture();
    $employee->company_id = Company::factory()->create()->id;

    expect(fn () => app(HolidayResolver::class)->resolve($employee, '2026-10-05'))
        ->toThrow(InvalidArgumentException::class, 'employee must exist and belong to a company');
});

test('multiple active holidays for one date fail explicitly if database integrity is bypassed', function () {
    [, $employee, $calendar] = holidayResolverFixture();
    holidayResolverHoliday($calendar, ['name' => 'Duplicate A']);
    DB::statement('DROP INDEX holidays_active_date_unique');
    holidayResolverHoliday($calendar, ['name' => 'Duplicate B']);

    try {
        app(HolidayResolver::class)->resolve($employee, '2026-10-05');
        $this->fail('Expected duplicate holiday configuration exception.');
    } catch (InvalidHolidayCalendarConfigurationException $exception) {
        expect($exception->reason)->toBe('duplicate_active_holidays');
    }
});

test('repeated resolution is deterministic and does not mutate any holiday domain records', function () {
    [, $employee, $calendar, $assignment] = holidayResolverFixture();
    $holiday = holidayResolverHoliday($calendar);
    $before = [
        EmployeeHolidayCalendarAssignment::query()->count(),
        HolidayCalendar::query()->count(),
        Holiday::withTrashed()->count(),
        $assignment->updated_at?->toISOString(),
        $calendar->updated_at?->toISOString(),
        $holiday->updated_at?->toISOString(),
    ];

    $resolver = app(HolidayResolver::class);
    $first = $resolver->resolve($employee, '2026-10-05');
    $second = $resolver->resolve($employee, '2026-10-05');
    $after = [
        EmployeeHolidayCalendarAssignment::query()->count(),
        HolidayCalendar::query()->count(),
        Holiday::withTrashed()->count(),
        $assignment->fresh()->updated_at?->toISOString(),
        $calendar->fresh()->updated_at?->toISOString(),
        $holiday->fresh()->updated_at?->toISOString(),
    ];

    expect($first->isHoliday())->toBeTrue()
        ->and($second->isHoliday())->toBeTrue()
        ->and($first->holiday?->is($second->holiday))->toBeTrue()
        ->and($after)->toBe($before);
});
